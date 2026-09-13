<?php
/**
 * Verifies per-license policy editing on a disposable WordPress site.
 *
 * Run with wp eval-file scripts/verify-live-license-policy.php --path=<disposable-site>.
 *
 * @package DreamaxLicenseManager
 */

use Dreamax\LicenseManager\Admin\Admin;
use Dreamax\LicenseManager\Licenses\LicensePolicyEditor;
use Dreamax\LicenseManager\ReleaseTools\DisposableEnvironmentGuard;
use Dreamax\LicenseManager\Support\PublicId;

require_once __DIR__ . '/lib/release-tools.php';

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'DB_NAME' ) ) {
	throw new RuntimeException( 'Run this verifier through WP-CLI on a disposable site.' );
}
DisposableEnvironmentGuard::assertSafe(
	array(
		'marker'   => 'DREAMAX_LM_DISPOSABLE_TEST',
		'database' => (string) DB_NAME,
		'site_url' => home_url( '/' ),
	)
);

global $wpdb;
$licenses   = $wpdb->prefix . 'dreamax_lm_licenses';
$activations = $wpdb->prefix . 'dreamax_lm_activations';
$events      = $wpdb->prefix . 'dreamax_lm_events';
foreach ( array( $licenses, $activations, $events ) as $table ) {
	$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s', (string) DB_NAME, $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Guarded engine verification.
	if ( ! is_string( $engine ) || 'INNODB' !== strtoupper( $engine ) ) {
		throw new RuntimeException( 'The disposable tables must be transactional.' );
	}
}
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
if ( array() === $admins ) {
	throw new RuntimeException( 'A disposable administrator is required.' );
}
$baseline = array(
	'licenses'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$licenses}" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Disposable aggregate baseline.
	'activations' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$activations}" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Disposable aggregate baseline.
	'events'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$events}" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Disposable aggregate baseline.
);
$public_id     = PublicId::generate( 'lic' );
$product_id    = PublicId::generate( 'prd' );
$now           = gmdate( 'Y-m-d H:i:s' );
$old_expiry    = gmdate( 'Y-m-d H:i:s', time() + 60 * DAY_IN_SECONDS );
$new_expiry    = gmdate( 'Y-m-d H:i:s', time() + 120 * DAY_IN_SECONDS );
$metadata      = '{"woocommerce":{"source":"generated"},"merchant_data":{"note":"kept","fields":[]}}';
$license_id    = 0;
$previous_user = get_current_user_id();
$previous_get  = $_GET;
$passed        = array();
$failed        = false;
$buffer        = false;

try {
	wp_set_current_user( (int) $admins[0] );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Exact synthetic license is removed below.
	$inserted = $wpdb->insert(
		$licenses,
		array(
			'public_id'             => $public_id,
			'key_ciphertext'        => 'fixture-not-a-key',
			'key_fingerprint'       => random_bytes( 32 ),
			'normalization_profile' => 'generated-ascii-v1',
			'lifecycle_status'      => 'assigned',
			'product_public_id'     => $product_id,
			'activation_limit'      => 3,
			'expires_at'            => $old_expiry,
			'created_at'            => $now,
			'updated_at'            => $now,
			'metadata'              => $metadata,
		),
		array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
	);
	if ( 1 !== $inserted ) {
		throw new RuntimeException( 'The synthetic license could not be created.' );
	}
	$license_id = (int) $wpdb->insert_id;
	for ( $index = 0; $index < 2; ++$index ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Exact synthetic activation is removed by license ownership below.
		$wpdb->insert(
			$activations,
			array(
				'public_id'           => PublicId::generate( 'act' ),
				'license_id'          => $license_id,
				'instance_fingerprint' => random_bytes( 32 ),
				'status'              => 'active',
				'first_activated_at'  => $now,
				'activated_at'        => $now,
				'updated_at'          => $now,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}
	$editor   = new LicensePolicyEditor();
	$original = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', $licenses, $license_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fresh synthetic row.
	$revision = is_array( $original ) ? $editor->revision( $original ) : '';
	$passed['below_active_rejected'] = false;
	try {
		$editor->save( $public_id, $revision, 1, $new_expiry, 'Unsafe reduction test', (int) $admins[0] );
	} catch ( InvalidArgumentException $error ) {
		$passed['below_active_rejected'] = true;
	}
	$passed['saved'] = $editor->save( $public_id, $revision, 2, $new_expiry, 'Approved policy change', (int) $admins[0] );
	$updated = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', $licenses, $license_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fresh synthetic row.
	$decoded = is_array( $updated ) ? json_decode( (string) $updated['metadata'], true ) : null;
	$passed['values_saved'] = is_array( $updated ) && 2 === (int) $updated['activation_limit'] && $new_expiry === $updated['expires_at'] && null === $updated['valid_for_seconds'];
	$passed['metadata_preserved'] = is_array( $decoded ) && ( $decoded['woocommerce']['source'] ?? '' ) === 'generated' && ( $decoded['merchant_data']['note'] ?? '' ) === 'kept' && ( $decoded['manual_overrides']['activation_limit'] ?? false ) && ( $decoded['manual_overrides']['expires_at'] ?? false );
	$passed['stale_rejected'] = false;
	try {
		$editor->save( $public_id, $revision, 3, null, 'Stale overwrite test', (int) $admins[0] );
	} catch ( RuntimeException $error ) {
		$passed['stale_rejected'] = true;
	}
	$recorded = $wpdb->get_results( $wpdb->prepare( 'SELECT event_type,actor_type,actor_id,metadata FROM %i WHERE license_id=%d', $events, $license_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact synthetic audit set.
	$event_metadata = is_array( $recorded ) && isset( $recorded[0] ) ? json_decode( (string) $recorded[0]['metadata'], true ) : null;
	$passed['single_audit'] = is_array( $recorded ) && 1 === count( $recorded ) && 'license_updated' === $recorded[0]['event_type'] && 'administrator' === $recorded[0]['actor_type'] && is_array( $event_metadata ) && array( 'activation_limit', 'expires_at' ) === $event_metadata['changed_fields'];
	$_GET = array( 'page' => 'dreamax-license-manager-license', 'license' => $public_id );
	ob_start();
	$buffer = true;
	( new Admin() )->license_page();
	$markup = (string) ob_get_clean();
	$buffer = false;
	$passed['editor_rendered'] = str_contains( $markup, 'dreamax_lm_save_license_policy' ) && str_contains( $markup, 'data-active-count="2"' ) && str_contains( $markup, 'value="2"' );
} catch ( Throwable $error ) {
	$failed = true;
} finally {
	if ( $buffer ) {
		ob_end_clean();
	}
	$_GET = $previous_get;
	wp_set_current_user( $previous_user );
	if ( $license_id > 0 && $public_id === $wpdb->get_var( $wpdb->prepare( 'SELECT public_id FROM %i WHERE id=%d', $licenses, $license_id ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact fixture ownership check.
		$wpdb->delete( $activations, array( 'license_id' => $license_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Exact owned rows only.
		$wpdb->delete( $events, array( 'license_id' => $license_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Exact owned rows only.
		$wpdb->delete( $licenses, array( 'id' => $license_id, 'public_id' => $public_id ), array( '%d', '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Exact owned row only.
	}
}
$passed['cleanup'] = $baseline['licenses'] === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$licenses}" ) && $baseline['activations'] === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$activations}" ) && $baseline['events'] === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$events}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact aggregate cleanup verification.
$passed['runtime'] = ! $failed;
echo wp_json_encode( $passed ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI prints fixed booleans only.
if ( in_array( false, $passed, true ) ) {
	exit( 1 );
}
