<?php
/**
 * Verifies replay, conflict, storage, and bounded cleanup idempotency contracts.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

use Dreamax\LicenseManager\Api\IdempotencyRepository;
use Dreamax\LicenseManager\Licenses\LicenseException;
use Dreamax\LicenseManager\ReleaseTools\DisposableEnvironmentGuard;

require_once __DIR__ . '/lib/release-tools.php';

/**
 * Stops with a sanitized failure.
 *
 * @param string $message Sanitized failure message.
 */
function dreamax_lm_f16_fail( string $message ): never {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI verifier reports only sanitized failures.
	fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
	exit( 1 );
}

$options = getopt( '', array( 'environment-marker:', 'wp-root:' ) );
$marker  = (string) ( $options['environment-marker'] ?? '' );
$wp_root = realpath( (string) ( $options['wp-root'] ?? '' ) );

if ( false === $wp_root || ! is_file( $wp_root . '/wp-load.php' ) ) {
	dreamax_lm_f16_fail( 'A valid WordPress root is required.' );
}
if ( ! defined( 'DISABLE_WP_CRON' ) ) {
	define( 'DISABLE_WP_CRON', true );
}
if ( ! defined( 'WP_USE_THEMES' ) ) {
	define( 'WP_USE_THEMES', false );
}

require_once $wp_root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

if ( ! defined( 'DB_NAME' ) ) {
	dreamax_lm_f16_fail( 'The database identity is unavailable.' );
}
DisposableEnvironmentGuard::assertSafe(
	array(
		'marker'   => $marker,
		'database' => (string) DB_NAME,
		'site_url' => home_url( '/' ),
	)
);
if ( ! is_plugin_active( 'dreamax-license-manager/dreamax-license-manager.php' ) ) {
	dreamax_lm_f16_fail( 'Dreamax License Manager is not active.' );
}
if ( false === wp_next_scheduled( 'dreamax_lm_cleanup' ) ) {
	dreamax_lm_f16_fail( 'The bounded cleanup event is not scheduled.' );
}

global $wpdb;
$table_idempotency  = $wpdb->prefix . 'dreamax_lm_idempotency';
$table_rate_limits  = $wpdb->prefix . 'dreamax_lm_rate_limits';
$table_guest_claims = $wpdb->prefix . 'dreamax_lm_guest_claims';

foreach ( array( $table_idempotency, $table_rate_limits, $table_guest_claims ) as $table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup safety requires authoritative table engines.
	$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s', (string) DB_NAME, $table ) );
	if ( 'InnoDB' !== $engine ) {
		dreamax_lm_f16_fail( 'A cleanup table is not safely transactional.' );
	}
}

$now = gmdate( 'Y-m-d H:i:s' );
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted prefixed tables are required and the cleanup drill must not affect pre-existing expired state.
$expired_before = array(
	'idempotency'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table_idempotency} WHERE expires_at < %s", $now ) ),
	'rate_limits'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table_rate_limits} WHERE expires_at < %s", $now ) ),
	'guest_claims' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table_guest_claims} WHERE status IN ('pending','issued') AND expires_at < %s", $now ) ),
);
$count_before   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_idempotency}" );
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
if ( array_sum( $expired_before ) > 0 ) {
	dreamax_lm_f16_fail( 'Pre-existing expired cleanup candidates must be resolved separately.' );
}

$repository      = new IdempotencyRepository();
$raw_key         = 'f16-' . bin2hex( random_bytes( 16 ) );
$future_raw_key  = 'f16-' . bin2hex( random_bytes( 16 ) );
$license_marker  = 'synthetic-license-' . bin2hex( random_bytes( 16 ) );
$secret_sentinel = 'synthetic-secret-' . bin2hex( random_bytes( 16 ) );
// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Benign base64url formatting for a synthetic public identifier.
$product_marker  = 'prd_' . substr( rtrim( strtr( base64_encode( random_bytes( 17 ) ), '+/', '-_' ), '=' ), 0, 22 );
$instance_marker = 'f16-instance-' . substr( hash( 'sha256', random_bytes( 32 ) ), 0, 24 );
// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Benign base64url formatting for a synthetic public identifier.
$activation_value = 'act_' . substr( rtrim( strtr( base64_encode( random_bytes( 17 ) ), '+/', '-_' ), '=' ), 0, 22 );
$payload          = array(
	'license_key'       => $license_marker,
	'product_public_id' => $product_marker,
	'instance_id'       => $instance_marker,
	'instance_label'    => 'F16 disposable verifier',
);
$result           = array(
	'activation_public_id' => $activation_value,
	'product_public_id'    => $product_marker,
	'status'               => 'active',
	'replayed'             => false,
	'secret_sentinel'      => $secret_sentinel,
);
$expected_replay  = array_intersect_key(
	$result,
	array_flip( array( 'license_public_id', 'product_public_id', 'activation_public_id', 'status', 'expires_at', 'replayed', 'activations' ) )
);
$owned_scopes     = array();
$failure          = null;
$replay_passed    = false;
$conflict_passed  = false;
$storage_passed   = false;
$cleanup_passed   = false;

try {
	$first = $repository->reserve( $raw_key, 'activate', $payload );
	if ( null !== $first['replay'] ) {
		throw new RuntimeException( 'The first reservation unexpectedly replayed.' );
	}
	$owned_scopes[] = bin2hex( $first['scope'] );
	$repository->complete( $first['scope'], 200, 'license_activated', $result );
	$replay        = $repository->reserve( $raw_key, 'activate', $payload );
	$replay_passed = array(
		'status' => 200,
		'code'   => 'license_activated',
		'data'   => $expected_replay,
	) === $replay['replay'];
	if ( ! $replay_passed ) {
		throw new RuntimeException( 'The completed reservation did not replay its exact bounded result.' );
	}

	$conflicting_payload                   = $payload;
	$conflicting_payload['instance_label'] = 'F16 changed payload';
	try {
		$repository->reserve( $raw_key, 'activate', $conflicting_payload );
	} catch ( LicenseException $error ) {
		$conflict_passed = 'idempotency_conflict' === $error->machine_code() && 409 === $error->http_status();
	}
	if ( ! $conflict_passed ) {
		throw new RuntimeException( 'The changed payload did not produce the required conflict.' );
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The exact owned scope and trusted prefixed table are required to inspect safe storage.
	$stored         = $wpdb->get_row( $wpdb->prepare( "SELECT api_version,operation,state,http_status,result_code,result_public_id,result_metadata FROM {$table_idempotency} WHERE scope_hash=UNHEX(%s)", $owned_scopes[0] ), ARRAY_A );
	$encoded_stored = wp_json_encode( $stored );
	$storage_passed = is_array( $stored )
		&& 'v1' === $stored['api_version']
		&& 'activate' === $stored['operation']
		&& 'completed' === $stored['state']
		&& 200 === (int) $stored['http_status']
		&& 'license_activated' === $stored['result_code']
		&& ! str_contains( (string) $encoded_stored, $raw_key )
		&& ! str_contains( (string) $encoded_stored, $license_marker )
		&& ! str_contains( (string) $encoded_stored, $secret_sentinel );
	if ( ! $storage_passed ) {
		throw new RuntimeException( 'The stored reservation did not meet the bounded secret-free contract.' );
	}

	$future = $repository->reserve( $future_raw_key, 'activate', $payload );
	if ( null !== $future['replay'] ) {
		throw new RuntimeException( 'The future reservation unexpectedly replayed.' );
	}
	$owned_scopes[] = bin2hex( $future['scope'] );
	$repository->complete( $future['scope'], 200, 'license_activated', $result );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Expire only the exact owned completed row in the trusted prefixed table.
	$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$table_idempotency} SET expires_at=%s WHERE scope_hash=UNHEX(%s)", gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ), $owned_scopes[0] ) );
	if ( 1 !== $updated ) {
		throw new RuntimeException( 'The owned cleanup fixture could not be expired.' );
	}

	do_action( 'dreamax_lm_cleanup' );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Exact scopes in the trusted prefixed table prove bounded deletion and preservation.
	$expired_remaining = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table_idempotency} WHERE scope_hash=UNHEX(%s)", $owned_scopes[0] ) );
	$future_remaining  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table_idempotency} WHERE scope_hash=UNHEX(%s)", $owned_scopes[1] ) );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$cleanup_passed = 0 === $expired_remaining && 1 === $future_remaining;
	if ( ! $cleanup_passed ) {
		throw new RuntimeException( 'Bounded cleanup did not delete only the expired owned reservation.' );
	}
} catch ( Throwable $error ) {
	$failure = $error;
} finally {
	foreach ( array_unique( $owned_scopes ) as $scope_hex ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Cleanup in the trusted prefixed table is restricted to exact owned keyed scopes.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table_idempotency} WHERE scope_hash=UNHEX(%s)", $scope_hex ) );
	}
	if ( function_exists( 'sodium_memzero' ) ) {
		sodium_memzero( $raw_key );
		sodium_memzero( $future_raw_key );
		sodium_memzero( $license_marker );
		sodium_memzero( $secret_sentinel );
	}
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The trusted prefixed table aggregate verifies exact cleanup.
$count_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_idempotency}" );
if ( $failure instanceof Throwable ) {
	dreamax_lm_f16_fail( $failure->getMessage() );
}
if ( $count_before !== $count_after ) {
	dreamax_lm_f16_fail( 'The owned fixture did not restore the initial idempotency aggregate.' );
}

echo wp_json_encode(
	array(
		'classification'               => 'live_disposable_wordpress_innodb',
		'exact_result_replay'          => $replay_passed,
		'changed_payload_conflict_409' => $conflict_passed,
		'bounded_secret_free_storage'  => $storage_passed,
		'expired_row_deleted'          => $cleanup_passed,
		'future_row_preserved'         => $cleanup_passed,
		'cleanup_event_scheduled'      => true,
		'owned_fixture_cleanup'        => true,
		'database_state_unchanged'     => $count_before === $count_after,
		'sensitive_output'             => false,
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
