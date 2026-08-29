<?php
/**
 * Verifies enumeration, proxy-spoof, timing, and rate-limit protections.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

use Dreamax\LicenseManager\Api\SourceAddress;
use Dreamax\LicenseManager\Api\TransportGuard;
use Dreamax\LicenseManager\Encryption\Crypto;
use Dreamax\LicenseManager\Licenses\LicenseException;
use Dreamax\LicenseManager\Licenses\LicenseRepository;
use Dreamax\LicenseManager\ReleaseTools\DisposableEnvironmentGuard;

require_once __DIR__ . '/lib/release-tools.php';

/**
 * Stops with a sanitized failure.
 *
 * @param string $message Sanitized failure message.
 */
function dreamax_lm_f15_fail( string $message ): never {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI verifier reports only sanitized failures.
	fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
	exit( 1 );
}

/**
 * Snapshots one exact rate-limit scope.
 *
 * @param string              $scope Scope value.
 * @param Crypto              $crypto Crypto service.
 * @param array<string,mixed> &$before Snapshot map.
 */
function dreamax_lm_f15_track_scope( string $scope, Crypto $crypto, array &$before ): void {
	global $wpdb;
	$hex = bin2hex( $crypto->keyed_hash( $scope, 'rate-limit' ) );
	if ( array_key_exists( $hex, $before ) ) {
		return;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact known-bucket restoration requires a fresh snapshot.
	$before[ $hex ] = $wpdb->get_row( $wpdb->prepare( "SELECT tokens,updated_microtime,expires_at FROM {$wpdb->prefix}dreamax_lm_rate_limits WHERE bucket_hash=UNHEX(%s)", $hex ), ARRAY_A );
}

/**
 * Dispatches one internal public REST validation and returns only safe metadata.
 *
 * @param array<string,mixed> $payload Request payload.
 * @param string              $expected_code Expected stable code.
 * @param int                 $expected_status Expected HTTP status.
 * @param array               $forbidden Values forbidden in the response.
 * @phpstan-param list<string> $forbidden Values forbidden in the response.
 * @throws RuntimeException When a response violates the safe rejection contract.
 * @return float Duration in milliseconds.
 */
function dreamax_lm_f15_dispatch( array $payload, string $expected_code, int $expected_status, array $forbidden ): float {
	$body = wp_json_encode( $payload );
	if ( ! is_string( $body ) ) {
		throw new RuntimeException( 'A synthetic request could not be encoded.' );
	}
	$_SERVER['CONTENT_TYPE']   = 'application/json';
	$_SERVER['CONTENT_LENGTH'] = (string) strlen( $body );
	$request                   = new WP_REST_Request( 'POST', '/dreamax-license-manager/v1/licenses/validate' );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( $body );
	$started       = hrtime( true );
	$response      = rest_do_request( $request );
	$elapsed       = ( hrtime( true ) - $started ) / 1000000;
	$data          = $response->get_data();
	$response_data = is_array( $data ) ? ( $data['data'] ?? null ) : null;
	$empty_data    = array() === $response_data || ( is_object( $response_data ) && array() === get_object_vars( $response_data ) );
	if ( $response->get_status() !== $expected_status || ! is_array( $data ) || ( $data['code'] ?? null ) !== $expected_code || ! $empty_data ) {
		throw new RuntimeException( 'A rejection response did not match its stable empty-data contract.' );
	}
	$encoded = wp_json_encode( $data );
	foreach ( $forbidden as $value ) {
		if ( '' !== $value && str_contains( (string) $encoded, $value ) ) {
			throw new RuntimeException( 'A rejection response disclosed a protected input.' );
		}
	}
	return $elapsed;
}

/**
 * Returns the median of a non-empty duration list.
 *
 * @param array $values Durations.
 * @phpstan-param list<float> $values Durations.
 */
function dreamax_lm_f15_median( array $values ): float {
	sort( $values, SORT_NUMERIC );
	$count = count( $values );
	return 1 === $count % 2 ? $values[ intdiv( $count, 2 ) ] : ( $values[ $count / 2 - 1 ] + $values[ $count / 2 ] ) / 2;
}

$options = getopt( '', array( 'environment-marker:', 'wp-root:' ) );
$marker  = (string) ( $options['environment-marker'] ?? '' );
$wp_root = realpath( (string) ( $options['wp-root'] ?? '' ) );
if ( false === $wp_root || ! is_file( $wp_root . '/wp-load.php' ) ) {
	dreamax_lm_f15_fail( 'A valid WordPress root is required.' );
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
	dreamax_lm_f15_fail( 'The database identity is unavailable.' );
}
DisposableEnvironmentGuard::assertSafe(
	array(
		'marker'   => $marker,
		'database' => (string) DB_NAME,
		'site_url' => home_url( '/' ),
	)
);
if ( ! is_plugin_active( 'dreamax-license-manager/dreamax-license-manager.php' ) ) {
	dreamax_lm_f15_fail( 'Dreamax License Manager is not active.' );
}

global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Restoration safety requires authoritative engine data.
$rate_engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s', (string) DB_NAME, $wpdb->prefix . 'dreamax_lm_rate_limits' ) );
if ( 'InnoDB' !== $rate_engine ) {
	dreamax_lm_f15_fail( 'The rate-limit table is not safely transactional.' );
}

$repository = new LicenseRepository();
$candidates = array();
foreach ( wc_get_orders(
	array(
		'limit'   => 25,
		'status'  => array( 'processing' ),
		'orderby' => 'date',
		'order'   => 'DESC',
	)
) as $candidate_order ) {
	if ( ! $candidate_order instanceof WC_Order || ! $candidate_order->is_paid() || ! str_ends_with( strtolower( (string) $candidate_order->get_billing_email() ), '@example.invalid' ) ) {
		continue;
	}
	$rows = $repository->for_order( (int) $candidate_order->get_id() );
	if ( 1 === count( $rows ) && 'assigned' === (string) $rows[0]['lifecycle_status'] ) {
		$candidates[] = $rows[0];
	}
}
if ( 1 !== count( $candidates ) ) {
	dreamax_lm_f15_fail( 'Exactly one sanitized assigned-license fixture is required.' );
}

$license           = $candidates[0];
$license_key       = $repository->decrypt_key( $license );
$product_public    = (string) $license['product_public_id'];
$alternate_product = 'prd_' . substr( rtrim( strtr( base64_encode( random_bytes( 17 ) ), '+/', '-_' ), '=' ), 0, 22 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Synthetic public identity formatting.
$unknown_key       = 'F15-' . strtoupper( bin2hex( random_bytes( 20 ) ) );
$instance          = 'f15-instance-' . substr( hash( 'sha256', random_bytes( 32 ) ), 0, 20 );
$trusted_had       = false !== get_option( 'dreamax_lm_trusted_proxies', false );
$trusted_before    = get_option( 'dreamax_lm_trusted_proxies', null );
$http_had          = false !== get_option( 'dreamax_lm_allow_http_local', false );
$http_before       = get_option( 'dreamax_lm_allow_http_local', null );
$server_before     = $_SERVER;
$error_log_before  = ini_get( 'error_log' );
$rate_before       = array();
$crypto            = new Crypto();
$failure           = null;
$restored          = false;
$checks            = array();
$unknown_timings   = array();
$mismatch_timings  = array();

try {
	// phpcs:ignore WordPress.PHP.IniSet.Risky -- Suppress opaque runtime identifiers and restore the exact setting in finally.
	ini_set( 'error_log', 'NUL' );
	update_option( 'dreamax_lm_allow_http_local', 0, false );
	update_option( 'dreamax_lm_trusted_proxies', array( '127.0.0.1' ), false );
	$_SERVER['REMOTE_ADDR']            = '198.51.100.50';
	$_SERVER['HTTP_X_FORWARDED_FOR']   = '203.0.113.70';
	$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
	unset( $_SERVER['HTTPS'] );
	$_SERVER['CONTENT_TYPE']         = 'application/json';
	$_SERVER['CONTENT_LENGTH']       = '2';
	$checks['untrusted_xff_ignored'] = '198.51.100.50' === ( new SourceAddress() )->resolve();
	try {
		( new TransportGuard() )->assert_public_request();
		$checks['untrusted_xfp_rejected'] = false;
	} catch ( LicenseException $error ) {
		$checks['untrusted_xfp_rejected'] = 'server_unavailable' === $error->machine_code();
	}

	update_option( 'dreamax_lm_trusted_proxies', array( '198.51.100.50', '198.51.100.51' ), false );
	$_SERVER['HTTP_X_FORWARDED_FOR']  = '203.0.113.70, 198.51.100.51';
	$checks['trusted_chain_resolved'] = '203.0.113.70' === ( new SourceAddress() )->resolve();
	( new TransportGuard() )->assert_public_request();
	$checks['trusted_xfp_allowed'] = true;

	foreach ( array( 'breaker|validate', 'failure|127.0.0.0/24|validate', 'failure|0000000000000000/56|validate', 'aggregate|203.0.113.0/24', 'failure|203.0.113.0/24|validate' ) as $scope ) {
		dreamax_lm_f15_track_scope( $scope, $crypto, $rate_before );
	}
	$http_payload = wp_json_encode(
		array(
			'license_key'       => $unknown_key,
			'product_public_id' => $product_public,
			'instance_id'       => $instance,
		)
	);
	$read_scope   = 'read|' . hash( 'sha256', $unknown_key . '|' . $product_public . '|validate' );
	dreamax_lm_f15_track_scope( $read_scope, $crypto, $rate_before );
	$http_args = array(
		'timeout'     => 15,
		'redirection' => 0,
		'headers'     => array(
			'Content-Type'      => 'application/json',
			'X-Forwarded-Proto' => 'https',
			'X-Forwarded-For'   => '203.0.113.70',
		),
		'body'        => $http_payload,
	);
	update_option( 'dreamax_lm_trusted_proxies', array(), false );
	$untrusted_http                = wp_remote_post( rest_url( 'dreamax-license-manager/v1/licenses/validate' ), $http_args );
	$untrusted_data                = is_wp_error( $untrusted_http ) ? null : json_decode( wp_remote_retrieve_body( $untrusted_http ), true );
	$checks['http_spoof_rejected'] = is_array( $untrusted_data ) && 'server_unavailable' === ( $untrusted_data['code'] ?? null );
	update_option( 'dreamax_lm_trusted_proxies', array( '127.0.0.1', '::1' ), false );
	$trusted_http                         = wp_remote_post( rest_url( 'dreamax-license-manager/v1/licenses/validate' ), $http_args );
	$trusted_data                         = is_wp_error( $trusted_http ) ? null : json_decode( wp_remote_retrieve_body( $trusted_http ), true );
	$checks['http_trusted_proxy_allowed'] = is_array( $trusted_data ) && 'invalid_license' === ( $trusted_data['code'] ?? null );

	update_option( 'dreamax_lm_trusted_proxies', array(), false );
	$_SERVER['HTTPS'] = 'on';
	unset( $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_FORWARDED_PROTO'] );
	$unknown_payload  = array(
		'license_key'       => $unknown_key,
		'product_public_id' => $product_public,
		'instance_id'       => $instance,
	);
	$mismatch_payload = array(
		'license_key'       => $license_key,
		'product_public_id' => $alternate_product,
		'instance_id'       => $instance,
	);
	foreach ( array( '198.51.100.0/24', '203.0.113.0/24' ) as $network ) {
		dreamax_lm_f15_track_scope( 'aggregate|' . $network, $crypto, $rate_before );
		dreamax_lm_f15_track_scope( 'failure|' . $network . '|validate', $crypto, $rate_before );
	}
	dreamax_lm_f15_track_scope( 'read|' . hash( 'sha256', $unknown_key . '|' . $product_public . '|validate' ), $crypto, $rate_before );
	dreamax_lm_f15_track_scope( 'read|' . hash( 'sha256', $license_key . '|' . $alternate_product . '|validate' ), $crypto, $rate_before );
	for ( $i = 0; $i < 10; ++$i ) {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.10';
		$unknown_timings[]      = dreamax_lm_f15_dispatch( $unknown_payload, 'invalid_license', 404, array( $unknown_key, $product_public ) );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
		$mismatch_timings[]     = dreamax_lm_f15_dispatch( $mismatch_payload, 'product_mismatch', 409, array( $license_key, $alternate_product ) );
	}
	$unknown_median                 = dreamax_lm_f15_median( $unknown_timings );
	$mismatch_median                = dreamax_lm_f15_median( $mismatch_timings );
	$minimum_median                 = max( 0.01, min( $unknown_median, $mismatch_median ) );
	$checks['timing_shape_bounded'] = max( $unknown_median, $mismatch_median ) / $minimum_median <= 4.0 && abs( $unknown_median - $mismatch_median ) <= 25.0;

	$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
	foreach ( array( 'aggregate|192.0.2.0/24', 'failure|192.0.2.0/24|validate' ) as $scope ) {
		dreamax_lm_f15_track_scope( $scope, $crypto, $rate_before );
	}
	for ( $i = 1; $i <= 21; ++$i ) {
		dreamax_lm_f15_dispatch( $unknown_payload, 21 === $i ? 'rate_limited' : 'invalid_license', 21 === $i ? 429 : 404, array( $unknown_key, $product_public ) );
	}
	$checks['failure_rate_limit_enforced'] = true;
	if ( in_array( false, $checks, true ) ) {
		throw new RuntimeException( 'An enumeration or proxy-abuse assertion failed.' );
	}
} catch ( Throwable $error ) {
	$failure = $error;
} finally {
	$_SERVER = $server_before;
	if ( $trusted_had ) {
		update_option( 'dreamax_lm_trusted_proxies', $trusted_before, false );
	} else {
		delete_option( 'dreamax_lm_trusted_proxies' );
	}
	if ( $http_had ) {
		update_option( 'dreamax_lm_allow_http_local', $http_before, false );
	} else {
		delete_option( 'dreamax_lm_allow_http_local' );
	}
	if ( false !== $error_log_before ) {
		// phpcs:ignore WordPress.PHP.IniSet.Risky -- Restore the exact pre-run CLI logging destination.
		ini_set( 'error_log', (string) $error_log_before );
	}
	$cleanup_ok = false !== $wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	foreach ( $rate_before as $hex => $row ) {
		if ( is_array( $row ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Restore the exact pre-run known bucket state.
			$cleanup_ok = $cleanup_ok && false !== $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}dreamax_lm_rate_limits SET tokens=%s,updated_microtime=%s,expires_at=%s WHERE bucket_hash=UNHEX(%s)", $row['tokens'], $row['updated_microtime'], $row['expires_at'], $hex ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Delete only a known bucket created by this verifier.
			$cleanup_ok = $cleanup_ok && false !== $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}dreamax_lm_rate_limits WHERE bucket_hash=UNHEX(%s)", $hex ) );
		}
	}
	$restored = $cleanup_ok && false !== $wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	if ( ! $restored ) {
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}

if ( function_exists( 'sodium_memzero' ) ) {
	sodium_memzero( $license_key );
	sodium_memzero( $unknown_key );
}
if ( ! $restored ) {
	dreamax_lm_f15_fail( 'The known rate-limit state was not restored.' );
}
if ( $failure instanceof Throwable ) {
	dreamax_lm_f15_fail( $failure->getMessage() );
}

echo wp_json_encode(
	array(
		'classification'             => 'live_wordpress_rest_http_proxy_innodb',
		'abuse_checks_verified'      => count( $checks ),
		'timing_samples_verified'    => count( $unknown_timings ) + count( $mismatch_timings ),
		'rejection_payloads_private' => true,
		'proxy_spoof_rejected'       => true,
		'trusted_proxy_allowed'      => true,
		'failure_rate_limited'       => true,
		'timing_shape_bounded'       => true,
		'rate_state_restored'        => $restored,
		'sensitive_output'           => false,
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
