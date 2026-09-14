<?php
/**
 * Verifies the commercial expiry-extension command on a disposable WordPress site.
 *
 * Run through WP-CLI or a private local harness that defines WP_CLI.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

use Dreamax\LicenseManager\Contracts\Commercial\V1\CommercialContracts;
use Dreamax\LicenseManager\Contracts\Commercial\V1\LicenseExpiryExtensionCommand;
use Dreamax\LicenseManager\ReleaseTools\DisposableEnvironmentGuard;
use Dreamax\LicenseManager\Support\PublicId;

require_once __DIR__ . '/lib/release-tools.php';

$approved_harness = ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'DREAMAX_LM_LOCAL_VERIFIER' ) && true === DREAMAX_LM_LOCAL_VERIFIER );
if ( ! $approved_harness || ! defined( 'DB_NAME' ) ) {
	throw new RuntimeException( 'Run this verifier through an approved harness on a disposable site.' );
}
DisposableEnvironmentGuard::assertSafe(
	array(
		'marker'   => 'DREAMAX_LM_DISPOSABLE_TEST',
		'database' => (string) DB_NAME,
		'site_url' => home_url( '/' ),
	)
);

global $wpdb;
$licenses = $wpdb->prefix . 'dreamax_lm_licenses';
$events   = $wpdb->prefix . 'dreamax_lm_events';
foreach ( array( $licenses, $events ) as $table ) {
	$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s', (string) DB_NAME, $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Guarded engine verification.
	if ( ! is_string( $engine ) || 'INNODB' !== strtoupper( $engine ) ) {
		throw new RuntimeException( 'The disposable command tables must be transactional.' );
	}
}

$baseline          = array(
	'licenses' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $licenses ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Disposable aggregate baseline.
	'events'   => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $events ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Disposable aggregate baseline.
);
$license_public_id = PublicId::generate( 'lic' );
$product_public_id = PublicId::generate( 'prd' );
$wrong_product_id  = PublicId::generate( 'prd' );
$operation_id      = 'renewal:woo:' . substr( hash( 'sha256', random_bytes( 16 ) ), 0, 24 );
$old_expiry        = gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS );
$expected_expiry   = gmdate( 'Y-m-d H:i:s', strtotime( $old_expiry . ' UTC' ) + 365 * DAY_IN_SECONDS );
$effective_at      = gmdate( 'Y-m-d\TH:i:s\Z', time() - MINUTE_IN_SECONDS );
$license_id        = 0;
$failed            = false;
$passed            = array();

try {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Exact synthetic license is removed below.
	$inserted = $wpdb->insert(
		$licenses,
		array(
			'public_id'             => $license_public_id,
			'key_ciphertext'        => 'commercial-command-fixture',
			'key_fingerprint'       => random_bytes( 32 ),
			'normalization_profile' => 'generated-ascii-v1',
			'lifecycle_status'      => 'assigned',
			'product_public_id'     => $product_public_id,
			'activation_limit'      => 1,
			'expires_at'            => $old_expiry,
			'created_at'            => gmdate( 'Y-m-d H:i:s' ),
			'updated_at'            => gmdate( 'Y-m-d H:i:s' ),
			'metadata'              => '{}',
		),
		array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
	);
	if ( 1 !== $inserted ) {
		throw new RuntimeException( 'The synthetic command license could not be created.' );
	}
	$license_id = (int) $wpdb->insert_id;
	$authority  = CommercialContracts::registry()->expiry_extension_authority();
	$command    = new LicenseExpiryExtensionCommand( $license_public_id, $product_public_id, 365, $effective_at, $operation_id );

	$first                    = $authority->extend( $command );
	$passed['first_applied']  = $first->applied() && ! $first->replayed() && 'extended' === $first->code() && $expected_expiry === $first->expires_at();
	$stored_after_first       = $wpdb->get_row( $wpdb->prepare( 'SELECT expires_at,metadata FROM %i WHERE id=%d', $licenses, $license_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact synthetic row.
	$metadata_after_first     = is_array( $stored_after_first ) ? json_decode( (string) $stored_after_first['metadata'], true ) : null;
	$commercial_operations    = is_array( $metadata_after_first ) && is_array( $metadata_after_first['commercial_extension_operations'] ?? null ) ? $metadata_after_first['commercial_extension_operations'] : array();
	$passed['marker_bounded'] = 1 === count( $commercial_operations ) && ! str_contains( (string) $stored_after_first['metadata'], $operation_id );

	$replay                    = $authority->extend( $command );
	$passed['replay_exact']    = $replay->applied() && $replay->replayed() && 'replayed' === $replay->code() && $expected_expiry === $replay->expires_at();
	$conflict                  = $authority->extend( new LicenseExpiryExtensionCommand( $license_public_id, $product_public_id, 30, $effective_at, $operation_id ) );
	$passed['conflict_denied'] = ! $conflict->applied() && 'idempotency_conflict' === $conflict->code();

	$mismatch                  = $authority->extend( new LicenseExpiryExtensionCommand( $license_public_id, $wrong_product_id, 365, $effective_at, $operation_id . ':product' ) );
	$passed['product_bound']   = ! $mismatch->applied() && 'product_mismatch' === $mismatch->code();
	$future                    = $authority->extend( new LicenseExpiryExtensionCommand( $license_public_id, $product_public_id, 365, gmdate( 'Y-m-d\TH:i:s\Z', time() + 10 * MINUTE_IN_SECONDS ), $operation_id . ':future' ) );
	$passed['future_rejected'] = ! $future->applied() && 'command_rejected' === $future->code();

	$stored_final              = $wpdb->get_var( $wpdb->prepare( 'SELECT expires_at FROM %i WHERE id=%d', $licenses, $license_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact synthetic row.
	$passed['single_mutation'] = $expected_expiry === $stored_final;
	$recorded                  = $wpdb->get_results( $wpdb->prepare( 'SELECT event_type,actor_type,actor_id,request_id,metadata FROM %i WHERE license_id=%d', $events, $license_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact synthetic audit set.
	$event_metadata            = is_array( $recorded ) && isset( $recorded[0] ) ? json_decode( (string) $recorded[0]['metadata'], true ) : null;
	$passed['single_audit']    = is_array( $recorded )
		&& 1 === count( $recorded )
		&& 'license_expiry_extension_applied' === $recorded[0]['event_type']
		&& 'system' === $recorded[0]['actor_type']
		&& null === $recorded[0]['actor_id']
		&& $operation_id === $recorded[0]['request_id']
		&& is_array( $event_metadata )
		&& $old_expiry === $event_metadata['old_expiry']
		&& $expected_expiry === $event_metadata['new_expiry']
		&& 365 === $event_metadata['extension_days']
		&& 'commercial_contract_v1' === $event_metadata['source'];
} catch ( Throwable $error ) {
	$failed = true;
} finally {
	if ( $license_id > 0 && $license_public_id === $wpdb->get_var( $wpdb->prepare( 'SELECT public_id FROM %i WHERE id=%d', $licenses, $license_id ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact fixture ownership check.
		$wpdb->delete( $events, array( 'license_id' => $license_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Exact owned events only.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact owned license only.
		$wpdb->delete(
			$licenses,
			array(
				'id'        => $license_id,
				'public_id' => $license_public_id,
			),
			array( '%d', '%s' )
		);
	}
}

$license_count     = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $licenses ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact aggregate cleanup verification.
$event_count       = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $events ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact aggregate cleanup verification.
$passed['cleanup'] = $baseline['licenses'] === $license_count && $baseline['events'] === $event_count;
$passed['runtime'] = ! $failed;
echo wp_json_encode( $passed ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI prints fixed booleans only.
if ( in_array( false, $passed, true ) ) {
	exit( 1 );
}
