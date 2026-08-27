<?php
/**
 * Verifies the public v1 lifecycle REST matrix on a disposable site.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

use Dreamax\LicenseManager\Api\SourceAddress;
use Dreamax\LicenseManager\Encryption\Crypto;
use Dreamax\LicenseManager\Licenses\LicenseService;
use Dreamax\LicenseManager\ReleaseTools\DisposableEnvironmentGuard;

require_once __DIR__ . '/lib/release-tools.php';

/**
 * Stops with a sanitized failure.
 *
 * @param string $message Sanitized failure message.
 */
function dreamax_lm_f08_fail( string $message ): never {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI verifier reports only sanitized failures.
	fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
	exit( 1 );
}

/**
 * Snapshots a rate-limit scope before a request can mutate it.
 *
 * @param string              $scope Scope value.
 * @param Crypto              $crypto Crypto service.
 * @param array<string,mixed> &$before Snapshot map.
 */
function dreamax_lm_f08_track_scope( string $scope, Crypto $crypto, array &$before ): void {
	global $wpdb;
	$hex = bin2hex( $crypto->keyed_hash( $scope, 'rate-limit' ) );
	if ( array_key_exists( $hex, $before ) ) {
		return;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact known-bucket restoration requires a fresh snapshot.
	$before[ $hex ] = $wpdb->get_row( $wpdb->prepare( "SELECT tokens,updated_microtime,expires_at FROM {$wpdb->prefix}dreamax_lm_rate_limits WHERE bucket_hash=UNHEX(%s)", $hex ), ARRAY_A );
}

/**
 * Dispatches and validates one public REST response without retaining values.
 *
 * @param array  $definition Case definition.
 * @phpstan-param array<string,mixed> $definition Case definition.
 * @param Crypto $crypto Crypto service.
 * @param array  &$rate_before Rate snapshot map.
 * @phpstan-param array<string,mixed> $rate_before Rate snapshot map.
 * @param array  $sensitive_values Values forbidden in the response.
 * @phpstan-param list<string> $sensitive_values Values forbidden in the response.
 * @throws RuntimeException When a REST contract does not match.
 * @return array<string,mixed>
 */
function dreamax_lm_f08_request( array $definition, Crypto $crypto, array &$rate_before, array $sensitive_values ): array {
	$operation = (string) $definition['operation'];
	$payload   = $definition['payload'];
	if ( ! is_array( $payload ) ) {
		throw new RuntimeException( 'A lifecycle case payload is invalid.' );
	}
	$network = ( new SourceAddress() )->network();
	if ( empty( $definition['skip_protective'] ) ) {
		dreamax_lm_f08_track_scope( 'breaker|' . $operation, $crypto, $rate_before );
		dreamax_lm_f08_track_scope( 'aggregate|' . $network, $crypto, $rate_before );
		$key_scope = hash( 'sha256', (string) ( $payload['license_key'] ?? '' ) . '|' . (string) ( $payload['product_public_id'] ?? '' ) . '|' . $operation );
		$scope     = in_array( $operation, array( 'activate', 'deactivate' ), true )
			? 'mutation|' . $key_scope . '|' . (string) ( $payload['instance_id'] ?? '' )
			: 'read|' . $key_scope;
		dreamax_lm_f08_track_scope( $scope, $crypto, $rate_before );
	}
	if ( (int) $definition['status'] >= 400 ) {
		dreamax_lm_f08_track_scope( 'failure|' . $network . '|' . $operation, $crypto, $rate_before );
	}

	$body = wp_json_encode( $payload );
	if ( ! is_string( $body ) ) {
		throw new RuntimeException( 'A lifecycle case could not be encoded.' );
	}
	$_SERVER['CONTENT_TYPE']   = 'application/json';
	$_SERVER['CONTENT_LENGTH'] = (string) strlen( $body );
	$request                   = new WP_REST_Request( 'POST', '/dreamax-license-manager/v1/licenses/' . $operation );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( $body );
	$response = rest_do_request( $request );
	$data     = $response->get_data();
	if ( ! is_array( $data ) ) {
		throw new RuntimeException( 'A lifecycle response envelope is invalid.' );
	}
	foreach ( array( 'success', 'code', 'message', 'request_id', 'timestamp', 'data' ) as $field ) {
		if ( ! array_key_exists( $field, $data ) ) {
			throw new RuntimeException( 'A lifecycle response envelope field is missing.' );
		}
	}
	$headers       = array_change_key_case( $response->get_headers(), CASE_LOWER );
	$cache_control = strtolower( (string) ( $headers['cache-control'] ?? '' ) );
	if ( (int) $definition['status'] !== $response->get_status()
		|| (string) $definition['code'] !== $data['code']
		|| (bool) $definition['success'] !== $data['success']
		|| ! str_contains( $cache_control, 'no-store' )
		|| 'no-cache' !== strtolower( (string) ( $headers['pragma'] ?? '' ) ) ) {
		$actual_code = preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $data['code'] ) );
		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Values are constrained to fixed contract codes, fixed categories, and integer HTTP statuses for CLI diagnostics.
		throw new RuntimeException(
			'A lifecycle REST outcome did not match: expected ' . (string) $definition['code'] . '/' . (int) $definition['status']
			. ', received ' . (string) $actual_code . '/' . $response->get_status() . '.'
		);
		// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}
	new DateTimeImmutable( (string) $data['timestamp'] );
	$encoded_response = wp_json_encode( $data );
	foreach ( $sensitive_values as $sensitive ) {
		if ( '' !== $sensitive && str_contains( (string) $encoded_response, $sensitive ) ) {
			throw new RuntimeException( 'A lifecycle response disclosed an input value.' );
		}
	}
	if ( array_key_exists( 'replayed', $definition ) ) {
		$response_data = $data['data'];
		if ( is_object( $response_data ) ) {
			$response_data = get_object_vars( $response_data );
		}
		if ( ! is_array( $response_data ) ) {
			throw new RuntimeException( 'A lifecycle replay response is invalid.' );
		}
		$expected_replayed = (bool) $definition['replayed'];
		$actual_replayed   = (bool) ( $response_data['replayed'] ?? null );
		if ( $expected_replayed !== $actual_replayed ) {
			throw new RuntimeException( 'A lifecycle replay flag did not match its contract.' );
		}
	}
	return $data;
}

$options = getopt( '', array( 'environment-marker:', 'wp-root:' ) );
$marker  = (string) ( $options['environment-marker'] ?? '' );
$wp_root = realpath( (string) ( $options['wp-root'] ?? '' ) );
if ( false === $wp_root || ! is_file( $wp_root . '/wp-load.php' ) ) {
	dreamax_lm_f08_fail( 'A valid WordPress root is required.' );
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
	dreamax_lm_f08_fail( 'The database identity is unavailable.' );
}
DisposableEnvironmentGuard::assertSafe(
	array(
		'marker'   => $marker,
		'database' => (string) DB_NAME,
		'site_url' => home_url( '/' ),
	)
);
if ( ! is_plugin_active( 'dreamax-license-manager/dreamax-license-manager.php' ) ) {
	dreamax_lm_f08_fail( 'Dreamax License Manager is not active.' );
}

global $wpdb;
foreach ( array( 'licenses', 'activations', 'events', 'idempotency', 'rate_limits' ) as $suffix ) {
	$table = $wpdb->prefix . 'dreamax_lm_' . $suffix;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup safety requires authoritative table engines.
	$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s', (string) DB_NAME, $table ) );
	if ( 'InnoDB' !== $engine ) {
		dreamax_lm_f08_fail( 'A lifecycle fixture table is not safely transactional.' );
	}
}

$products = array();
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
	$items = array_values( $candidate_order->get_items( 'line_item' ) );
	if ( 1 !== count( $items ) || ! $items[0] instanceof WC_Order_Item_Product ) {
		continue;
	}
	$product = $items[0]->get_product();
	if ( $product instanceof WC_Product && preg_match( '/^prd_[A-Za-z0-9_-]{22}$/D', (string) $product->get_meta( '_dreamax_lm_product_public_id', true ) ) ) {
		$products[] = $product;
	}
}
if ( 1 !== count( $products ) ) {
	dreamax_lm_f08_fail( 'Exactly one sanitized lifecycle product fixture is required.' );
}
$product           = $products[0];
$product_public_id = (string) $product->get_meta( '_dreamax_lm_product_public_id', true );

$counts = static function () use ( $wpdb ): array {
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact restoration evidence requires fresh aggregates.
	return array(
		'licenses'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_licenses" ),
		'activations' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_activations" ),
		'events'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_events" ),
		'idempotency' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_idempotency" ),
		'rate_limits' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_rate_limits" ),
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
};

$before           = $counts();
$option_had_value = false !== get_option( 'dreamax_lm_allow_http_local', false );
$option_before    = get_option( 'dreamax_lm_allow_http_local', null );
$server_before    = $_SERVER;
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The verifier snapshots query state and performs no form processing.
$get_before       = $_GET;
$error_log_before = ini_get( 'error_log' );
$owned            = array();
$keys             = array();
$rate_before      = array();
$failure          = null;
$cleanup_passed   = false;
$matrix_passed    = false;
$outcome_count    = 0;
$crypto           = new Crypto();
$service          = new LicenseService();
// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Benign base64url formatting for a synthetic public identifier.
$alternate_product = 'prd_' . substr( rtrim( strtr( base64_encode( random_bytes( 17 ) ), '+/', '-_' ), '=' ), 0, 22 );
$invalid_key       = 'F08-' . strtoupper( bin2hex( random_bytes( 16 ) ) );
$instance_a        = 'f08-instance-' . substr( hash( 'sha256', random_bytes( 32 ) ), 0, 24 );
$instance_b        = 'f08-instance-' . substr( hash( 'sha256', random_bytes( 32 ) ), 0, 24 );
$instance_missing  = 'f08-instance-' . substr( hash( 'sha256', random_bytes( 32 ) ), 0, 24 );

try {
	foreach (
		array(
			'valid'     => array(
				'lifecycle_status' => 'assigned',
				'activation_limit' => 1,
				'expires_at'       => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			),
			'suspended' => array(
				'lifecycle_status' => 'suspended',
				'activation_limit' => 1,
				'expires_at'       => null,
			),
			'revoked'   => array(
				'lifecycle_status' => 'revoked',
				'activation_limit' => 1,
				'expires_at'       => null,
			),
			'expired'   => array(
				'lifecycle_status' => 'assigned',
				'activation_limit' => 1,
				'expires_at'       => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
			),
			'zero'      => array(
				'lifecycle_status' => 'assigned',
				'activation_limit' => 0,
				'expires_at'       => null,
			),
		) as $name => $attributes
	) {
		$created       = $service->create_generated(
			array_merge(
				$attributes,
				array(
					'product_public_id' => $product_public_id,
					'product_id'        => (int) $product->get_id(),
					'actor_type'        => 'system',
					'request_id'        => 'f08-create-' . substr( hash( 'sha256', random_bytes( 32 ) ), 0, 24 ),
					'source'            => 'generated',
					'metadata'          => array( 'fixture' => array( 'purpose' => 'f08-lifecycle-api' ) ),
				)
			)
		);
		$owned[]       = array(
			'id'        => (int) $created['id'],
			'public_id' => (string) $created['public_id'],
		);
		$keys[ $name ] = (string) $created['key'];
	}

	update_option( 'dreamax_lm_allow_http_local', 1, false );
	// phpcs:ignore WordPress.PHP.IniSet.Risky -- The disposable verifier suppresses opaque runtime request identifiers and restores the exact setting in finally.
	ini_set( 'error_log', 'NUL' );
	$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
	$_GET                   = array();
	$server                 = rest_get_server();
	if ( ! isset( $server->get_routes()['/dreamax-license-manager/v1/licenses/activate'] ) ) {
		throw new RuntimeException( 'The public lifecycle REST routes are unavailable.' );
	}

	$valid_payload = array(
		'license_key'       => $keys['valid'],
		'product_public_id' => $product_public_id,
		'instance_id'       => $instance_a,
		'instance_label'    => 'F08 verifier',
	);
	$cases         = array(
		array(
			'operation'       => 'validate',
			'payload'         => array_merge( $valid_payload, array( 'unexpected' => true ) ),
			'status'          => 400,
			'code'            => 'invalid_request',
			'success'         => false,
			'skip_protective' => true,
		),
		array(
			'operation' => 'validate',
			'payload'   => array(
				'license_key'       => $invalid_key,
				'product_public_id' => $product_public_id,
				'instance_id'       => $instance_a,
			),
			'status'    => 404,
			'code'      => 'invalid_license',
			'success'   => false,
		),
		array(
			'operation' => 'validate',
			'payload'   => array(
				'license_key'       => $keys['valid'],
				'product_public_id' => $alternate_product,
				'instance_id'       => $instance_a,
			),
			'status'    => 409,
			'code'      => 'product_mismatch',
			'success'   => false,
		),
		array(
			'operation' => 'validate',
			'payload'   => array(
				'license_key'       => $keys['suspended'],
				'product_public_id' => $product_public_id,
				'instance_id'       => $instance_a,
			),
			'status'    => 403,
			'code'      => 'license_suspended',
			'success'   => false,
		),
		array(
			'operation' => 'validate',
			'payload'   => array(
				'license_key'       => $keys['revoked'],
				'product_public_id' => $product_public_id,
				'instance_id'       => $instance_a,
			),
			'status'    => 403,
			'code'      => 'license_revoked',
			'success'   => false,
		),
		array(
			'operation' => 'validate',
			'payload'   => array(
				'license_key'       => $keys['expired'],
				'product_public_id' => $product_public_id,
				'instance_id'       => $instance_a,
			),
			'status'    => 403,
			'code'      => 'license_expired',
			'success'   => false,
		),
		array(
			'operation' => 'activate',
			'payload'   => array(
				'license_key'       => $keys['zero'],
				'product_public_id' => $product_public_id,
				'instance_id'       => $instance_a,
			),
			'status'    => 409,
			'code'      => 'activation_limit_reached',
			'success'   => false,
		),
		array(
			'operation' => 'activate',
			'payload'   => $valid_payload,
			'status'    => 200,
			'code'      => 'license_activated',
			'success'   => true,
			'replayed'  => false,
		),
		array(
			'operation' => 'activate',
			'payload'   => $valid_payload,
			'status'    => 200,
			'code'      => 'license_activated',
			'success'   => true,
			'replayed'  => true,
		),
		array(
			'operation' => 'activate',
			'payload'   => array_merge( $valid_payload, array( 'instance_id' => $instance_b ) ),
			'status'    => 409,
			'code'      => 'activation_limit_reached',
			'success'   => false,
		),
		array(
			'operation' => 'validate',
			'payload'   => $valid_payload,
			'status'    => 200,
			'code'      => 'license_valid',
			'success'   => true,
		),
		array(
			'operation' => 'status',
			'payload'   => $valid_payload,
			'status'    => 200,
			'code'      => 'license_valid',
			'success'   => true,
		),
		array(
			'operation' => 'deactivate',
			'payload'   => array_merge( $valid_payload, array( 'instance_id' => $instance_missing ) ),
			'status'    => 404,
			'code'      => 'activation_not_found',
			'success'   => false,
		),
		array(
			'operation' => 'deactivate',
			'payload'   => $valid_payload,
			'status'    => 200,
			'code'      => 'license_deactivated',
			'success'   => true,
			'replayed'  => false,
		),
		array(
			'operation' => 'deactivate',
			'payload'   => $valid_payload,
			'status'    => 200,
			'code'      => 'license_deactivated',
			'success'   => true,
			'replayed'  => true,
		),
		array(
			'operation' => 'validate',
			'payload'   => $valid_payload,
			'status'    => 200,
			'code'      => 'license_valid',
			'success'   => true,
		),
	);
	foreach ( $cases as $case_index => $case ) {
		try {
			dreamax_lm_f08_request( $case, $crypto, $rate_before, array_merge( array_values( $keys ), array( $invalid_key ) ) );
		} catch ( Throwable $case_error ) {
			throw new RuntimeException( 'Lifecycle REST case failed at fixed position ' . ( (int) $case_index + 1 ) . ': ' . $case_error->getMessage(), 0, $case_error );
		}
		++$outcome_count;
	}
	$ping = rest_do_request( new WP_REST_Request( 'GET', '/dreamax-license-manager/v1/system/ping' ) );
	$data = $ping->get_data();
	if ( 200 !== $ping->get_status() || ! is_array( $data ) || 'system_available' !== ( $data['code'] ?? null ) ) {
		throw new RuntimeException( 'The public system availability route failed.' );
	}
	++$outcome_count;
	$matrix_passed = true;
} catch ( Throwable $error ) {
	$failure = $error;
} finally {
	if ( $option_had_value ) {
		update_option( 'dreamax_lm_allow_http_local', $option_before, false );
	} else {
		delete_option( 'dreamax_lm_allow_http_local' );
	}
	if ( false !== $error_log_before ) {
		// phpcs:ignore WordPress.PHP.IniSet.Risky -- Restore the exact pre-run CLI logging destination after the guarded matrix.
		ini_set( 'error_log', (string) $error_log_before );
	}
	$_SERVER = $server_before;
	$_GET    = $get_before;
	$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$cleanup_ok = true;
	foreach ( $owned as $license ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup is restricted to exact owned fixture identities.
		$cleanup_ok = $cleanup_ok && false !== $wpdb->delete( $wpdb->prefix . 'dreamax_lm_activations', array( 'license_id' => $license['id'] ), array( '%d' ) );
		$cleanup_ok = $cleanup_ok && false !== $wpdb->delete( $wpdb->prefix . 'dreamax_lm_events', array( 'license_id' => $license['id'] ), array( '%d' ) );
		$cleanup_ok = $cleanup_ok && 1 === $wpdb->delete(
			$wpdb->prefix . 'dreamax_lm_licenses',
			array(
				'id'        => $license['id'],
				'public_id' => $license['public_id'],
			),
			array( '%d', '%s' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}
	foreach ( $rate_before as $hex => $row ) {
		if ( is_array( $row ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Restore the exact pre-run known bucket state.
			$cleanup_ok = $cleanup_ok && false !== $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}dreamax_lm_rate_limits SET tokens=%s,updated_microtime=%s,expires_at=%s WHERE bucket_hash=UNHEX(%s)", $row['tokens'], $row['updated_microtime'], $row['expires_at'], $hex ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Delete only a known bucket created by this fixture.
			$cleanup_ok = $cleanup_ok && false !== $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}dreamax_lm_rate_limits WHERE bucket_hash=UNHEX(%s)", $hex ) );
		}
	}
	$cleanup_passed = $cleanup_ok && false !== $wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	if ( ! $cleanup_passed ) {
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}
	foreach ( $keys as &$key ) {
		if ( function_exists( 'sodium_memzero' ) ) {
			sodium_memzero( $key );
		}
	}
	unset( $key );
	if ( function_exists( 'sodium_memzero' ) ) {
		sodium_memzero( $invalid_key );
	}
}

$after = $counts();
if ( $failure instanceof Throwable ) {
	dreamax_lm_f08_fail( $failure->getMessage() );
}
if ( ! $matrix_passed || ! $cleanup_passed || $before !== $after ) {
	dreamax_lm_f08_fail( 'The lifecycle matrix did not restore its starting state.' );
}

echo wp_json_encode(
	array(
		'classification'              => 'live_disposable_wordpress_rest_innodb',
		'rest_outcomes_verified'      => $outcome_count,
		'allowed_lifecycle_paths'     => true,
		'rejected_lifecycle_codes'    => true,
		'product_mismatch_verified'   => true,
		'replay_flags_verified'       => true,
		'response_envelopes_verified' => true,
		'private_headers_verified'    => true,
		'rate_buckets_restored'       => true,
		'owned_fixture_cleanup'       => $cleanup_passed,
		'database_state_unchanged'    => $before === $after,
		'sensitive_output'            => false,
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
