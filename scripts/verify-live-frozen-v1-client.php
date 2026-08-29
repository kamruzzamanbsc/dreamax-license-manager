<?php
/**
 * Runs the unchanged frozen v1 client against a disposable local server.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

use Dreamax\LicenseManager\Encryption\Crypto;
use Dreamax\LicenseManager\Licenses\LicenseService;
use Dreamax\LicenseManager\ReleaseTools\DisposableEnvironmentGuard;

require_once __DIR__ . '/lib/release-tools.php';

/**
 * Stops with a sanitized failure.
 *
 * @param string $message Sanitized failure message.
 */
function dreamax_lm_f28_fail( string $message ): never {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI verifier reports a sanitized failure.
	fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
	exit( 1 );
}

$options = getopt( '', array( 'environment-marker:', 'wp-root:' ) );
$marker  = (string) ( $options['environment-marker'] ?? '' );
$wp_root = realpath( (string) ( $options['wp-root'] ?? '' ) );
if ( false === $wp_root || ! is_file( $wp_root . '/wp-load.php' ) ) {
	dreamax_lm_f28_fail( 'A valid WordPress root is required.' );
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
	dreamax_lm_f28_fail( 'The database identity is unavailable.' );
}
DisposableEnvironmentGuard::assertSafe(
	array(
		'marker'   => $marker,
		'database' => (string) DB_NAME,
		'site_url' => home_url( '/' ),
	)
);
if ( ! is_plugin_active( 'dreamax-license-manager/dreamax-license-manager.php' ) ) {
	dreamax_lm_f28_fail( 'Dreamax License Manager is not active.' );
}

global $wpdb;
$client = dirname( __DIR__ ) . '/tests/fixtures/reference-client/v1/client.php';
$router = __DIR__ . '/lib/frozen-v1-loopback-router.php';
if ( ! is_file( $client ) || ! is_file( $router ) ) {
	dreamax_lm_f28_fail( 'The frozen v1 client is unavailable.' );
}
$client_hash_before = hash_file( 'sha256', $client );
if ( ! is_string( $client_hash_before ) ) {
	dreamax_lm_f28_fail( 'The frozen client hash could not be read.' );
}

$tables = array( 'licenses', 'activations', 'events', 'idempotency', 'rate_limits' );
foreach ( $tables as $suffix ) {
	$table = $wpdb->prefix . 'dreamax_lm_' . $suffix;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Live cleanup safety requires authoritative engines.
	$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s', (string) DB_NAME, $table ) );
	if ( 'InnoDB' !== $engine ) {
		dreamax_lm_f28_fail( 'A required v1 fixture table is not safely transactional.' );
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
	dreamax_lm_f28_fail( 'Exactly one sanitized v1 product fixture is required.' );
}
$product           = $products[0];
$product_public_id = (string) $product->get_meta( '_dreamax_lm_product_public_id', true );

$counts = static function () use ( $wpdb ): array {
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact cleanup evidence requires fresh counts.
	return array(
		'licenses'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_licenses" ),
		'activations' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_activations" ),
		'events'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_events" ),
		'idempotency' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_idempotency" ),
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
};

$before               = $counts();
$option_before        = get_option( 'dreamax_lm_allow_http_local', null );
$option_had_value     = false !== get_option( 'dreamax_lm_allow_http_local', false );
$license_id           = 0;
$license_public_id    = '';
$license_key          = '';
$activation_public_id = '';
$run_failure          = null;
$cleanup_passed       = false;
$rate_before          = array();
$rate_hashes          = array();
$instance             = 'f28-instance-' . substr( hash( 'sha256', random_bytes( 32 ) ), 0, 24 );
$router_process       = null;
$router_pipes         = array();

try {
	$created           = ( new LicenseService() )->create_generated(
		array(
			'lifecycle_status'  => 'assigned',
			'product_public_id' => $product_public_id,
			'product_id'        => (int) $product->get_id(),
			'activation_limit'  => 1,
			'actor_type'        => 'system',
			'request_id'        => 'f28-create-' . substr( hash( 'sha256', random_bytes( 32 ) ), 0, 24 ),
			'source'            => 'generated',
			'metadata'          => array( 'fixture' => array( 'purpose' => 'f28-frozen-client' ) ),
		)
	);
	$license_id        = (int) $created['id'];
	$license_public_id = (string) $created['public_id'];
	$license_key       = (string) $created['key'];

	$crypto   = new Crypto();
	$networks = array( '127.0.0.0/24', '0000000000000000/56' );
	$scopes   = array( 'breaker|activate', 'breaker|validate', 'breaker|deactivate' );
	foreach ( $networks as $network ) {
		$scopes[] = 'aggregate|' . $network;
		$scopes[] = 'failure|' . $network . '|activate';
	}
	foreach ( array( 'activate', 'deactivate', 'validate' ) as $operation ) {
		$key_scope = hash( 'sha256', $license_key . '|' . $product_public_id . '|' . $operation );
		$scopes[]  = 'validate' === $operation ? 'read|' . $key_scope : 'mutation|' . $key_scope . '|' . $instance;
	}
	foreach ( array_unique( $scopes ) as $scope ) {
		$hash          = $crypto->keyed_hash( $scope, 'rate-limit' );
		$hex           = bin2hex( $hash );
		$rate_hashes[] = $hex;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact known-bucket snapshots are required for restoration.
		$rate_before[ $hex ] = $wpdb->get_row( $wpdb->prepare( "SELECT tokens,updated_microtime,expires_at FROM {$wpdb->prefix}dreamax_lm_rate_limits WHERE bucket_hash=UNHEX(%s)", $hex ), ARRAY_A );
	}

	update_option( 'dreamax_lm_allow_http_local', 1, false );
	$inherited_environment = getenv();
	$router_port           = random_int( 18080, 18999 );
	$router_environment    = array_merge(
		$inherited_environment,
		array( 'DREAMAX_LM_F28_UPSTREAM' => home_url( '/' ) )
	);
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Starts a loopback-only path adapter for the unchanged client.
	$router_process = proc_open(
		array( PHP_BINARY, '-S', '127.0.0.1:' . $router_port, $router ),
		array(
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		),
		$router_pipes,
		null,
		$router_environment
	);
	if ( ! is_resource( $router_process ) ) {
		throw new RuntimeException( 'The loopback REST path adapter could not start.' );
	}
	usleep( 500000 );
	$environment = array_merge(
		$inherited_environment,
		array(
			'DREAMAX_LM_BASE_URL' => 'http://127.0.0.1:' . $router_port,
			'DREAMAX_LM_LICENSE'  => $license_key,
			'DREAMAX_LM_PRODUCT'  => $product_public_id,
			'DREAMAX_LM_INSTANCE' => $instance,
		)
	);
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- The release verifier must execute the committed frozen client as a separate unchanged process.
	$process = proc_open(
		array( PHP_BINARY, $client ),
		array(
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		),
		$pipes,
		null,
		$environment
	);
	if ( ! is_resource( $process ) ) {
		throw new RuntimeException( 'The frozen client process could not start.' );
	}
	$stdout = stream_get_contents( $pipes[1] );
	$stderr = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the verifier's subprocess pipe.
	fclose( $pipes[2] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the verifier's subprocess pipe.
	$exit_code = proc_close( $process );
	if ( 0 !== $exit_code || 'Frozen v1 reference flow passed.' !== trim( (string) $stdout ) ) {
		$category = 'The unchanged frozen client did not complete its required contract.';
		foreach (
			array(
				'Network request failed.'               => 'The frozen client could not reach the local REST server.',
				'Activation replay contract failed.'    => 'The frozen client activation replay contract failed.',
				'Idempotency conflict contract failed.' => 'The frozen client idempotency conflict contract failed.',
				'Validation contract failed.'           => 'The frozen client validation contract failed.',
				'Deactivation contract failed.'         => 'The frozen client deactivation contract failed.',
				'Missing envelope field:'               => 'The frozen client response envelope contract failed.',
				'Syntax error'                          => 'The local REST server returned a non-JSON response.',
				'DateTimeImmutable'                     => 'The frozen client timestamp contract failed.',
			) as $signature => $sanitized
		) {
			if ( str_contains( (string) $stderr, $signature ) ) {
				$category = $sanitized;
				break;
			}
		}
		unset( $stderr );
		throw new RuntimeException( $category );
	}
	unset( $stderr );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The fixture activation identity scopes exact cleanup.
	$activation_public_id = (string) $wpdb->get_var( $wpdb->prepare( "SELECT public_id FROM {$wpdb->prefix}dreamax_lm_activations WHERE license_id=%d", $license_id ) );
	if ( ! preg_match( '/^act_[A-Za-z0-9_-]{22}$/D', $activation_public_id ) ) {
		throw new RuntimeException( 'The frozen client activation result is missing.' );
	}
} catch ( Throwable $error ) {
	$run_failure = $error;
} finally {
	if ( $option_had_value ) {
		update_option( 'dreamax_lm_allow_http_local', $option_before, false );
	} else {
		delete_option( 'dreamax_lm_allow_http_local' );
	}
	if ( is_resource( $router_process ) ) {
		proc_terminate( $router_process );
		foreach ( $router_pipes as $router_pipe ) {
			if ( is_resource( $router_pipe ) ) {
				fclose( $router_pipe ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes an ephemeral server pipe.
			}
		}
		proc_close( $router_process );
	}
	$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$cleanup_ok = true;
	if ( '' !== $activation_public_id ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact result identity owns both v1 idempotency rows.
		$cleanup_ok = false !== $wpdb->delete( $wpdb->prefix . 'dreamax_lm_idempotency', array( 'result_public_id' => $activation_public_id ), array( '%s' ) );
	}
	if ( $license_id > 0 ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup is restricted to the exact owned fixture license.
		$cleanup_ok = $cleanup_ok && false !== $wpdb->delete( $wpdb->prefix . 'dreamax_lm_activations', array( 'license_id' => $license_id ), array( '%d' ) );
		$cleanup_ok = $cleanup_ok && false !== $wpdb->delete( $wpdb->prefix . 'dreamax_lm_events', array( 'license_id' => $license_id ), array( '%d' ) );
		$cleanup_ok = $cleanup_ok && 1 === $wpdb->delete(
			$wpdb->prefix . 'dreamax_lm_licenses',
			array(
				'id'        => $license_id,
				'public_id' => $license_public_id,
			),
			array( '%d', '%s' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}
	foreach ( $rate_hashes as $hex ) {
		$row = $rate_before[ $hex ];
		if ( is_array( $row ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Restore the exact pre-run shared bucket state.
			$cleanup_ok = $cleanup_ok && false !== $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}dreamax_lm_rate_limits SET tokens=%s,updated_microtime=%s,expires_at=%s WHERE bucket_hash=UNHEX(%s)", $row['tokens'], $row['updated_microtime'], $row['expires_at'], $hex ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Delete only the known bucket created by this fixture.
			$cleanup_ok = $cleanup_ok && false !== $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}dreamax_lm_rate_limits WHERE bucket_hash=UNHEX(%s)", $hex ) );
		}
	}
	$cleanup_passed = $cleanup_ok && false !== $wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	if ( ! $cleanup_passed ) {
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}
	if ( '' !== $license_key && function_exists( 'sodium_memzero' ) ) {
		sodium_memzero( $license_key );
	}
}

$after             = $counts();
$client_hash_after = hash_file( 'sha256', $client );
if ( $run_failure instanceof Throwable ) {
	dreamax_lm_f28_fail( $run_failure->getMessage() );
}
if ( ! $cleanup_passed || $before !== $after || ! hash_equals( $client_hash_before, (string) $client_hash_after ) ) {
	dreamax_lm_f28_fail( 'The frozen-client fixture did not restore its starting state.' );
}

echo wp_json_encode(
	array(
		'classification'               => 'live_disposable_v1_http_loopback',
		'frozen_client_unchanged'      => true,
		'activation_replay_passed'     => true,
		'idempotency_conflict_passed'  => true,
		'validation_passed'            => true,
		'deactivation_passed'          => true,
		'local_http_option_restored'   => true,
		'loopback_path_adapter_closed' => true,
		'rate_buckets_restored'        => true,
		'owned_fixture_cleanup'        => $cleanup_passed,
		'database_state_unchanged'     => $before === $after,
		'sensitive_output'             => false,
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
