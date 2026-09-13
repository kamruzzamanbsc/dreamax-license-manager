<?php
/**
 * Verifies merchant-only metadata on a disposable WordPress installation.
 *
 * Run with wp eval-file scripts/verify-live-merchant-notes.php --path=<disposable-site>.
 *
 * @package DreamaxLicenseManager
 */

use Dreamax\LicenseManager\Admin\Admin;
use Dreamax\LicenseManager\Licenses\MerchantMetadata;
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
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
if ( array() === $admins ) {
	throw new RuntimeException( 'A disposable administrator is required.' );
}
$table  = $wpdb->prefix . 'dreamax_lm_licenses';
$events = $wpdb->prefix . 'dreamax_lm_events';
foreach ( array( $table, $events ) as $checked_table ) {
	$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s', (string) DB_NAME, $checked_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Guarded engine verification.
	if ( ! is_string( $engine ) || 'INNODB' !== strtoupper( $engine ) ) {
		throw new RuntimeException( 'The disposable tables must be transactional.' );
	}
}
$baseline_licenses = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Disposable aggregate baseline.
$baseline_events   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$events}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Disposable aggregate baseline.
$public_id         = PublicId::generate( 'lic' );
$product_id        = PublicId::generate( 'prd' );
$original          = '{"woocommerce":{"source":"pool"},"lifecycle_operations":["existing-marker"]}';
$now               = gmdate( 'Y-m-d H:i:s' );
$previous_user     = get_current_user_id();
$previous_get      = $_GET;
$license_id        = 0;
$passed            = array();
$failed            = false;
$started_buffer    = false;

try {
	wp_set_current_user( (int) $admins[0] );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- An exact disposable fixture is removed in the finally block.
	$inserted = $wpdb->insert(
		$table,
		array(
			'public_id'             => $public_id,
			'key_ciphertext'        => 'fixture-not-a-license-key',
			'key_fingerprint'       => random_bytes( 32 ),
			'normalization_profile' => 'generated-ascii-v1',
			'lifecycle_status'      => 'assigned',
			'product_public_id'     => $product_id,
			'created_at'            => $now,
			'updated_at'            => $now,
			'metadata'              => $original,
		),
		array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
	);
	if ( 1 !== $inserted ) {
		throw new RuntimeException( 'A disposable license row could not be created.' );
	}
	$license_id = (int) $wpdb->insert_id;
	$service    = new MerchantMetadata();
	$revision   = $service->view( array( 'metadata' => $original ) )['revision'];
	$passed['saved'] = $service->save( $public_id, $revision, 'Follow up on case', array( 'ticket' => 'case-7' ), (int) $admins[0] );
	$stored = $wpdb->get_row( $wpdb->prepare( 'SELECT metadata FROM %i WHERE id=%d', $table, $license_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fresh owned fixture verification.
	$decoded = is_array( $stored ) ? json_decode( (string) $stored['metadata'], true ) : null;
	$passed['policy_preserved'] = is_array( $decoded ) && ( $decoded['woocommerce']['source'] ?? '' ) === 'pool' && ( $decoded['lifecycle_operations'][0] ?? '' ) === 'existing-marker';
	$passed['merchant_saved'] = is_array( $decoded ) && ( $decoded['merchant_data']['note'] ?? '' ) === 'Follow up on case' && ( $decoded['merchant_data']['fields']['ticket'] ?? '' ) === 'case-7';
	$new_revision = $service->view( $stored )['revision'];
	$passed['noop'] = ! $service->save( $public_id, $new_revision, 'Follow up on case', array( 'ticket' => 'case-7' ), (int) $admins[0] );
	$passed['stale_rejected'] = false;
	try {
		$service->save( $public_id, $revision, 'Overwrite from stale form', array(), (int) $admins[0] );
	} catch ( RuntimeException $error ) {
		$passed['stale_rejected'] = true;
	}
	$passed['secret_field_rejected'] = false;
	try {
		$service->save( $public_id, $new_revision, 'Other', array( 'api_token' => 'not-a-real-secret' ), (int) $admins[0] );
	} catch ( InvalidArgumentException $error ) {
		$passed['secret_field_rejected'] = true;
	}
	$recorded = $wpdb->get_results( $wpdb->prepare( 'SELECT event_type,actor_type,actor_id,metadata FROM %i WHERE license_id=%d', $events, $license_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact owned audit verification.
	$passed['single_redacted_audit'] = is_array( $recorded ) && 1 === count( $recorded ) && 'license_updated' === $recorded[0]['event_type'] && 'administrator' === $recorded[0]['actor_type'] && (int) $recorded[0]['actor_id'] === (int) $admins[0] && ! str_contains( wp_json_encode( $recorded ), 'Follow up' ) && ! str_contains( wp_json_encode( $recorded ), 'case-7' );
	$_GET = array( 'page' => 'dreamax-license-manager-license', 'license' => $public_id );
	ob_start();
	$started_buffer = true;
	( new Admin() )->license_page();
	$markup = (string) ob_get_clean();
	$started_buffer = false;
	$passed['admin_editor'] = str_contains( $markup, 'Follow up on case' ) && str_contains( $markup, 'case-7' ) && str_contains( $markup, 'dreamax_lm_save_license_notes' );
} catch ( Throwable $error ) {
	$failed = true;
} finally {
	if ( $started_buffer ) {
		ob_end_clean();
	}
	$_GET = $previous_get;
	wp_set_current_user( $previous_user );
	if ( $license_id > 0 ) {
		$fixture = $wpdb->get_var( $wpdb->prepare( 'SELECT public_id FROM %i WHERE id=%d', $table, $license_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Only the exact owned fixture may be removed.
		if ( $fixture === $public_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Remove only audit rows owned by the exact synthetic license.
			$wpdb->delete( $events, array( 'license_id' => $license_id ), array( '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Remove the exact synthetic license only.
			$wpdb->delete( $table, array( 'id' => $license_id, 'public_id' => $public_id ), array( '%d', '%s' ) );
		}
	}
}
$passed['rollback'] = $baseline_licenses === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) && $baseline_events === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$events}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Disposable aggregate comparison.
$passed['runtime'] = ! $failed;
echo wp_json_encode( $passed ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI emits fixed boolean fields only.
if ( in_array( false, $passed, true ) ) {
	exit( 1 );
}
