<?php
/**
 * Verifies F06 pool and F07 activation concurrency on disposable InnoDB.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

use Dreamax\LicenseManager\Events\AuditEventCatalog;
use Dreamax\LicenseManager\Integrations\WooCommerce\OrderLicensing;
use Dreamax\LicenseManager\Licenses\KeyNormalizer;
use Dreamax\LicenseManager\Licenses\LicenseService;
use Dreamax\LicenseManager\ReleaseTools\DisposableEnvironmentGuard;
use Dreamax\LicenseManager\ReleaseTools\Filesystem;
use Dreamax\LicenseManager\Support\PublicId;

require_once __DIR__ . '/lib/release-tools.php';

/**
 * Stops with a sanitized error that does not disclose fixture values.
 *
 * @param string $message Sanitized failure message.
 */
function dreamax_lm_f0607_fail( string $message ): never {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI verifier must report a sanitized failure to STDERR.
	fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
	exit( 1 );
}

/**
 * Starts two workers, proves both wait on the held license row, then releases it.
 *
 * @param list<array<string,mixed>> $payloads Worker inputs.
 * @param int                       $license_id Exact owned license row used as the start gate.
 * @param string                    $label Race label used only for owned barrier filenames.
 * @param string                    $wp_root Validated disposable WordPress root.
 * @param string                    $marker Required disposable-environment marker.
 * @param string                    $temp_dir Exact owned private barrier directory.
 * @throws RuntimeException When a worker, barrier, or database gate fails.
 * @return array{results:list<array<string,mixed>>,both_in_flight:bool}
 */
function dreamax_lm_f0607_race( array $payloads, int $license_id, string $label, string $wp_root, string $marker, string $temp_dir ): array {
	global $wpdb;
	$processes        = array();
	$transaction_open = false;
	$go_file          = $temp_dir . DIRECTORY_SEPARATOR . $label . '.go';
	$race_stage       = 'start_gate_transaction';

	try {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The coordinator intentionally holds the exact fixture row so both contenders become concurrently in-flight.
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			throw new RuntimeException( 'The concurrency gate transaction could not start.' );
		}
		$transaction_open = true;
		$race_stage       = 'lock_gate_row';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- This is the exact owned row used as the concurrency start gate.
		$locked = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}dreamax_lm_licenses WHERE id=%d FOR UPDATE",
				$license_id
			)
		);
		if ( $license_id !== $locked ) {
			throw new RuntimeException( 'The concurrency gate row could not be locked.' );
		}

		$race_stage = 'spawn_workers';
		foreach ( $payloads as $index => $payload ) {
			$ready_file            = $temp_dir . DIRECTORY_SEPARATOR . $label . '-' . $index . '.ready';
			$payload['ready_file'] = $ready_file;
			$payload['go_file']    = $go_file;
			$descriptors           = array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			);
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Starts only the committed local worker with sensitive fixture input sent through STDIN.
			$process = proc_open(
				array(
					PHP_BINARY,
					__DIR__ . '/lib/concurrency-worker.php',
					'--environment-marker=' . $marker,
					'--wp-root=' . $wp_root,
				),
				$descriptors,
				$pipes,
				__DIR__
			);
			if ( ! is_resource( $process ) ) {
				throw new RuntimeException( 'A concurrency worker could not start.' );
			}
			$encoded = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );
			if ( ! is_string( $encoded ) ) {
				throw new RuntimeException( 'A concurrency worker input could not be encoded.' );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- The private child pipe avoids secret-bearing command arguments and files.
			fwrite( $pipes[0], $encoded );
			fclose( $pipes[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the private child-process pipe immediately after input delivery.
			stream_set_blocking( $pipes[1], false );
			stream_set_blocking( $pipes[2], false );
			$processes[] = array(
				'process'    => $process,
				'pipes'      => $pipes,
				'ready_file' => $ready_file,
			);
			if ( function_exists( 'sodium_memzero' ) ) {
				sodium_memzero( $encoded );
			}
		}

		$race_stage     = 'wait_for_barrier';
		$ready_deadline = microtime( true ) + 20.0;
		do {
			$ready = true;
			foreach ( $processes as $worker ) {
				$status = proc_get_status( $worker['process'] );
				if ( ! $status['running'] ) {
					throw new RuntimeException( 'A concurrency worker stopped before the barrier.' );
				}
				$ready = $ready && is_file( $worker['ready_file'] );
			}
			if ( ! $ready ) {
				usleep( 20000 );
			}
		} while ( ! $ready && microtime( true ) < $ready_deadline );
		if ( ! $ready ) {
			throw new RuntimeException( 'The concurrency workers did not reach the barrier.' );
		}

		$race_stage = 'release_barrier';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- The owned go marker contains no data and releases local workers only.
		if ( false === file_put_contents( $go_file, 'go' ) ) {
			throw new RuntimeException( 'The concurrency barrier could not be released.' );
		}
		$race_stage = 'prove_workers_in_flight';
		usleep( 400000 );
		$both_in_flight = true;
		foreach ( $processes as $worker ) {
			$status         = proc_get_status( $worker['process'] );
			$both_in_flight = $both_in_flight && $status['running'];
		}
		if ( ! $both_in_flight ) {
			throw new RuntimeException( 'Both contenders were not in flight before releasing the database row.' );
		}

		$race_stage = 'release_gate_row';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases both verified in-flight contenders onto the same InnoDB row.
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			throw new RuntimeException( 'The concurrency gate row could not be released.' );
		}
		$transaction_open = false;

		$race_stage      = 'wait_for_workers';
		$finish_deadline = microtime( true ) + 30.0;
		do {
			$running = false;
			foreach ( $processes as $worker ) {
				$status  = proc_get_status( $worker['process'] );
				$running = $running || $status['running'];
			}
			if ( $running ) {
				usleep( 20000 );
			}
		} while ( $running && microtime( true ) < $finish_deadline );
		if ( $running ) {
			throw new RuntimeException( 'A concurrency worker exceeded its bounded runtime.' );
		}

		$race_stage = 'collect_worker_results';
		$results    = array();
		foreach ( $processes as $worker ) {
			stream_set_blocking( $worker['pipes'][1], true );
			stream_set_blocking( $worker['pipes'][2], true );
			$stdout = stream_get_contents( $worker['pipes'][1] );
			$stderr = stream_get_contents( $worker['pipes'][2] );
			fclose( $worker['pipes'][1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the owned child-process output pipe.
			fclose( $worker['pipes'][2] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the owned child-process error pipe.
			proc_close( $worker['process'] );
			$result = is_string( $stdout ) ? json_decode( trim( $stdout ), true ) : null;
			if ( '' !== trim( (string) $stderr ) ) {
				$race_stage = 'worker_stderr';
				throw new RuntimeException( 'A concurrency worker returned an invalid result.' );
			}
			if ( ! is_array( $result ) ) {
				$race_stage = 'worker_non_json';
				throw new RuntimeException( 'A concurrency worker returned an invalid result.' );
			}
			if ( 'worker_error' === ( $result['status'] ?? '' ) ) {
				$race_stage = 'worker_error';
				throw new RuntimeException( 'A concurrency worker returned an invalid result.' );
			}
			$results[] = $result;
		}

		return array(
			'results'        => $results,
			'both_in_flight' => $both_in_flight,
		);
	} catch ( Throwable $error ) {
		if ( $transaction_open ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases the exact concurrency gate after any coordinator failure.
			$wpdb->query( 'ROLLBACK' );
		}
		foreach ( $processes as $worker ) {
			if ( is_resource( $worker['process'] ) ) {
				$status = proc_get_status( $worker['process'] );
				if ( $status['running'] ) {
					proc_terminate( $worker['process'] );
				}
			}
			foreach ( $worker['pipes'] as $pipe ) {
				if ( is_resource( $pipe ) ) {
					fclose( $pipe ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes an owned child-process pipe during bounded failure cleanup.
				}
			}
			if ( is_resource( $worker['process'] ) ) {
				proc_close( $worker['process'] );
			}
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The stage is an internal fixed label; the original error is chained but never rendered.
		throw new RuntimeException( 'Concurrency race failed at sanitized substage: ' . $race_stage . '.', 0, $error );
	}
}

$options = getopt( '', array( 'environment-marker:', 'wp-root:' ) );
$marker  = (string) ( $options['environment-marker'] ?? '' );
$wp_root = realpath( (string) ( $options['wp-root'] ?? '' ) );

if ( false === $wp_root || ! is_file( $wp_root . '/wp-load.php' ) ) {
	dreamax_lm_f0607_fail( 'A valid WordPress root is required.' );
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
	dreamax_lm_f0607_fail( 'The database identity is unavailable.' );
}
DisposableEnvironmentGuard::assertSafe(
	array(
		'marker'   => $marker,
		'database' => (string) DB_NAME,
		'site_url' => home_url( '/' ),
	)
);
if ( ! is_plugin_active( 'dreamax-license-manager/dreamax-license-manager.php' )
	|| ! function_exists( 'WC' )
	|| ! class_exists( WC_Product_Simple::class )
	|| ! class_exists( OrderLicensing::class ) ) {
	dreamax_lm_f0607_fail( 'The required WordPress plugins are not active.' );
}

global $wpdb;

$tables = array(
	$wpdb->posts,
	$wpdb->postmeta,
	$wpdb->comments,
	$wpdb->commentmeta,
	$wpdb->options,
	$wpdb->prefix . 'wc_product_meta_lookup',
	$wpdb->prefix . 'wc_orders',
	$wpdb->prefix . 'wc_order_addresses',
	$wpdb->prefix . 'wc_order_operational_data',
	$wpdb->prefix . 'wc_order_stats',
	$wpdb->prefix . 'wc_order_product_lookup',
	$wpdb->prefix . 'woocommerce_order_items',
	$wpdb->prefix . 'woocommerce_order_itemmeta',
	$wpdb->prefix . 'dreamax_lm_licenses',
	$wpdb->prefix . 'dreamax_lm_activations',
	$wpdb->prefix . 'dreamax_lm_events',
);
foreach ( $tables as $table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Concurrency and cleanup require authoritative transactional-engine checks.
	$engine = $wpdb->get_var(
		$wpdb->prepare(
			'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
			(string) DB_NAME,
			$table
		)
	);
	if ( 'InnoDB' !== $engine ) {
		dreamax_lm_f0607_fail( 'A required concurrency table is not safely transactional.' );
	}
}

$snapshot = static function () use ( $wpdb ): array {
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact cleanup evidence requires authoritative aggregate reads.
	return array(
		'licenses'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_licenses" ),
		'activations' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_activations" ),
		'events'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_events" ),
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
};

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Stale owned fixtures must block a new run before mutation.
$stale_products = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product' AND post_title LIKE %s", $wpdb->esc_like( 'DLM F06 temporary pool product' ) . '%' ) );
$stale_orders   = wc_get_orders(
	array(
		'limit'         => 1,
		'billing_email' => 'dlm-f06@example.invalid',
		'return'        => 'ids',
	)
);
if ( 0 !== $stale_products || array() !== $stale_orders ) {
	dreamax_lm_f0607_fail( 'A stale owned concurrency fixture must be cleaned before running.' );
}

$before              = $snapshot();
$suffix              = substr( hash( 'sha256', random_bytes( 32 ) ), 0, 16 );
$temp_dir            = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dreamax-lm-f06-f07-' . $suffix;
$product             = null;
$product_id          = 0;
$orders              = array();
$order_ids           = array();
$order_item_ids      = array();
$license_ids         = array();
$request_ids         = array();
$keys                = array();
$failure             = null;
$cleanup_failure     = null;
$cleanup_committed   = false;
$pool_in_flight      = false;
$pool_contract       = false;
$pool_events_exact   = false;
$different_in_flight = false;
$different_contract  = false;
$same_in_flight      = false;
$same_contract       = false;
$activation_events   = false;
$verification_stage  = 'fixture_setup';
$failure_stage       = 'none';
$failure_substage    = 'none';
$failure_type        = 'none';
$failure_line        = 0;

if ( ! mkdir( $temp_dir, 0700 ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creates one permission-restricted, random, exactly owned local barrier directory.
	dreamax_lm_f0607_fail( 'The private concurrency barrier could not be created.' );
}

try {
	add_filter( 'woocommerce_email_enabled_new_order', '__return_false', PHP_INT_MAX );
	add_filter( 'woocommerce_email_enabled_customer_processing_order', '__return_false', PHP_INT_MAX );
	add_filter( 'woocommerce_email_enabled_customer_completed_order', '__return_false', PHP_INT_MAX );

	$product = new WC_Product_Simple();
	$product->set_name( 'DLM F06 temporary pool product ' . $suffix );
	$product->set_status( 'publish' );
	$product->set_virtual( true );
	$product->set_regular_price( '10' );
	$product_id        = (int) $product->save();
	$product_public_id = PublicId::generate( 'prd' );
	$product->update_meta_data( '_dreamax_lm_enabled', 'yes' );
	$product->update_meta_data( '_dreamax_lm_product_public_id', $product_public_id );
	$product->update_meta_data( '_dreamax_lm_source', 'pool' );
	$product->update_meta_data( '_dreamax_lm_issuance', 'per_quantity' );
	$product->update_meta_data( '_dreamax_lm_activation_limit', '1' );
	$product->save_meta_data();

	foreach ( array( 'a', 'b' ) as $label ) {
		$fixture_order = wc_create_order( array( 'status' => 'processing' ) );
		if ( ! $fixture_order instanceof WC_Order ) {
			throw new RuntimeException( 'A temporary concurrency order could not be created.' );
		}
		$fixture_order->set_billing_email( 'dlm-f06@example.invalid' );
		$fixture_order->set_payment_method( 'bacs' );
		$item_id = (int) $fixture_order->add_product( $product, 1 );
		$fixture_order->calculate_totals();
		$fixture_order->set_date_paid( time() );
		$fixture_order->save();
		$orders[]         = $fixture_order;
		$order_ids[]      = (int) $fixture_order->get_id();
		$order_item_ids[] = $item_id;
		$request_ids[]    = 'f06-' . $label . '-' . $suffix;
	}

	$pool_key           = bin2hex( random_bytes( 32 ) );
	$pool_result        = ( new LicenseService() )->import(
		$pool_key,
		KeyNormalizer::IMPORTED,
		null,
		array(
			'lifecycle_status'  => 'available',
			'product_public_id' => $product_public_id,
			'activation_limit'  => 1,
			'actor_type'        => 'system',
			'request_id'        => 'f06-pool-' . $suffix,
			'source'            => 'concurrency_fixture',
		)
	);
	$license_ids[]      = (int) $pool_result['id'];
	$keys[]             = $pool_key;
	$verification_stage = 'pool_race';
	$pool_race          = dreamax_lm_f0607_race(
		array(
			array(
				'mode'       => 'pool',
				'order_id'   => $order_ids[0],
				'request_id' => $request_ids[0],
			),
			array(
				'mode'       => 'pool',
				'order_id'   => $order_ids[1],
				'request_id' => $request_ids[1],
			),
		),
		(int) $pool_result['id'],
		'pool',
		$wp_root,
		$marker,
		$temp_dir
	);
	$pool_in_flight     = $pool_race['both_in_flight'];
	$verification_stage = 'pool_assertions';
	$pool_outcomes      = array_map(
		static fn( array $result ): string => (string) ( $result['allocated'] ?? -1 ) . ':' . (string) ( $result['failed'] ?? -1 ),
		$pool_race['results']
	);
	sort( $pool_outcomes );
	$pool_contract = array( '0:1', '1:0' ) === $pool_outcomes;

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact concurrency outcome and audit assertions require authoritative reads.
	$pool_assigned         = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_licenses WHERE id=%d AND lifecycle_status='assigned' AND order_id IN (%d,%d)", (int) $pool_result['id'], $order_ids[0], $order_ids[1] ) );
	$pool_assigned_events  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_events WHERE license_id=%d AND event_type=%s", (int) $pool_result['id'], AuditEventCatalog::LICENSE_ASSIGNED ) );
	$pool_delivered_events = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_events WHERE license_id=%d AND event_type=%s", (int) $pool_result['id'], AuditEventCatalog::LICENSE_DELIVERED ) );
	$pool_failed_events    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_events WHERE request_id IN (%s,%s) AND event_type=%s", $request_ids[0], $request_ids[1], AuditEventCatalog::ORDER_ALLOCATION_FAILED ) );
	$pool_completed_events = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_events WHERE request_id IN (%s,%s) AND event_type=%s", $request_ids[0], $request_ids[1], AuditEventCatalog::ORDER_AUTOMATIC_ALLOCATION_COMPLETED ) );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$previews = array(
		( new OrderLicensing() )->preview( $order_ids[0] ),
		( new OrderLicensing() )->preview( $order_ids[1] ),
	);
	$missing  = array_map( static fn( array $preview ): int => (int) $preview['missing'], $previews );
	sort( $missing );
	$pool_contract     = $pool_contract && 1 === $pool_assigned && array( 0, 1 ) === $missing;
	$pool_events_exact = 1 === $pool_assigned_events && 1 === $pool_delivered_events && 1 === $pool_failed_events && 1 === $pool_completed_events;

	$different_product_id = PublicId::generate( 'prd' );
	$different            = ( new LicenseService() )->create_generated(
		array(
			'lifecycle_status'  => 'assigned',
			'product_public_id' => $different_product_id,
			'activation_limit'  => 1,
			'actor_type'        => 'system',
			'request_id'        => 'f07-different-fixture-' . $suffix,
			'source'            => 'concurrency_fixture',
		)
	);
	$license_ids[]        = (int) $different['id'];
	$keys[]               = (string) $different['key'];
	$verification_stage   = 'different_instance_race';
	$different_race       = dreamax_lm_f0607_race(
		array(
			array(
				'mode'              => 'activation',
				'key'               => (string) $different['key'],
				'product_public_id' => $different_product_id,
				'instance_id'       => 'f07-different-a-' . $suffix,
				'request_id'        => 'f07-different-a-' . $suffix,
			),
			array(
				'mode'              => 'activation',
				'key'               => (string) $different['key'],
				'product_public_id' => $different_product_id,
				'instance_id'       => 'f07-different-b-' . $suffix,
				'request_id'        => 'f07-different-b-' . $suffix,
			),
		),
		(int) $different['id'],
		'different',
		$wp_root,
		$marker,
		$temp_dir
	);
	$different_in_flight  = $different_race['both_in_flight'];
	$verification_stage   = 'different_instance_assertions';
	$different_outcomes   = array_map(
		static function ( array $result ): string {
			return 'success' === ( $result['status'] ?? '' )
				? 'success'
				: (string) ( $result['machine_code'] ?? 'unknown' );
		},
		$different_race['results']
	);
	sort( $different_outcomes );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact activation concurrency assertions require authoritative reads.
	$different_active = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_activations WHERE license_id=%d AND status='active'", (int) $different['id'] ) );
	$different_events = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_events WHERE license_id=%d AND event_type=%s", (int) $different['id'], AuditEventCatalog::LICENSE_ACTIVATED ) );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$different_contract = array( 'activation_limit_reached', 'success' ) === $different_outcomes && 1 === $different_active;

	$same_product_id    = PublicId::generate( 'prd' );
	$same               = ( new LicenseService() )->create_generated(
		array(
			'lifecycle_status'  => 'assigned',
			'product_public_id' => $same_product_id,
			'activation_limit'  => 1,
			'actor_type'        => 'system',
			'request_id'        => 'f07-same-fixture-' . $suffix,
			'source'            => 'concurrency_fixture',
		)
	);
	$license_ids[]      = (int) $same['id'];
	$keys[]             = (string) $same['key'];
	$same_instance      = 'f07-same-instance-' . $suffix;
	$verification_stage = 'same_instance_race';
	$same_race          = dreamax_lm_f0607_race(
		array(
			array(
				'mode'              => 'activation',
				'key'               => (string) $same['key'],
				'product_public_id' => $same_product_id,
				'instance_id'       => $same_instance,
				'request_id'        => 'f07-same-a-' . $suffix,
			),
			array(
				'mode'              => 'activation',
				'key'               => (string) $same['key'],
				'product_public_id' => $same_product_id,
				'instance_id'       => $same_instance,
				'request_id'        => 'f07-same-b-' . $suffix,
			),
		),
		(int) $same['id'],
		'same',
		$wp_root,
		$marker,
		$temp_dir
	);
	$same_in_flight     = $same_race['both_in_flight'];
	$verification_stage = 'same_instance_assertions';
	$same_statuses      = array_map( static fn( array $result ): string => (string) ( $result['status'] ?? '' ), $same_race['results'] );
	$same_replays       = array_map( static fn( array $result ): bool => (bool) ( $result['replayed'] ?? false ), $same_race['results'] );
	sort( $same_replays );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact same-instance convergence assertions require authoritative reads.
	$same_active = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_activations WHERE license_id=%d AND status='active'", (int) $same['id'] ) );
	$same_events = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_events WHERE license_id=%d AND event_type=%s", (int) $same['id'], AuditEventCatalog::LICENSE_ACTIVATED ) );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$same_contract      = array( 'success', 'success' ) === $same_statuses && array( false, true ) === $same_replays && 1 === $same_active;
	$activation_events  = 1 === $different_events && 1 === $same_events;
	$verification_stage = 'combined_contract';

	if ( ! $pool_in_flight
		|| ! $pool_contract
		|| ! $pool_events_exact
		|| ! $different_in_flight
		|| ! $different_contract
		|| ! $same_in_flight
		|| ! $same_contract
		|| ! $activation_events ) {
		throw new RuntimeException( 'The live concurrency contract did not match.' );
	}
} catch ( Throwable $error ) {
	$failure       = $error;
	$failure_stage = $verification_stage;
	$failure_line  = $error->getLine();
	$failure_type  = match ( true ) {
		$error instanceof TypeError        => 'type_error',
		$error instanceof RuntimeException => 'runtime_exception',
		$error instanceof Error            => 'error',
		default                            => 'other',
	};
	if ( preg_match( '/sanitized substage: ([a-z_]+)/', $error->getMessage(), $matches ) ) {
		$failure_substage = $matches[1];
	}
} finally {
	if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$cleanup_failure = new RuntimeException( 'Cleanup transaction start failed.' );
	} else {
		try {
			foreach ( $request_ids as $request_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact null-license allocation events are owned by these synthetic request references.
				$wpdb->delete( $wpdb->prefix . 'dreamax_lm_events', array( 'request_id' => $request_id ), array( '%s' ) );
			}
			foreach ( $license_ids as $license_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact owned activation cleanup.
				$wpdb->delete( $wpdb->prefix . 'dreamax_lm_activations', array( 'license_id' => $license_id ), array( '%d' ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact owned event cleanup.
				$wpdb->delete( $wpdb->prefix . 'dreamax_lm_events', array( 'license_id' => $license_id ), array( '%d' ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact owned license cleanup.
				if ( 1 !== $wpdb->delete( $wpdb->prefix . 'dreamax_lm_licenses', array( 'id' => $license_id ), array( '%d' ) ) ) {
					throw new RuntimeException( 'Owned concurrency license cleanup failed.' );
				}
			}
			foreach ( $orders as $owned_order ) {
				if ( ! $owned_order->delete( true ) ) {
					throw new RuntimeException( 'Owned concurrency order cleanup failed.' );
				}
			}
			if ( $product instanceof WC_Product_Simple && ! $product->delete( true ) ) {
				throw new RuntimeException( 'Owned concurrency product cleanup failed.' );
			}
			$cleanup_committed = false !== $wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( ! $cleanup_committed ) {
				throw new RuntimeException( 'Owned concurrency cleanup commit failed.' );
			}
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$cleanup_failure = $error;
		}
	}

	foreach ( $keys as &$key ) {
		if ( '' !== $key && function_exists( 'sodium_memzero' ) ) {
			sodium_memzero( $key );
		}
	}
	unset( $key );
	if ( is_dir( $temp_dir ) ) {
		try {
			Filesystem::removeTree( $temp_dir, sys_get_temp_dir() );
		} catch ( Throwable $error ) {
			$cleanup_failure = $cleanup_failure ?? $error;
		}
	}
	if ( $product_id > 0 ) {
		clean_post_cache( $product_id );
	}
	if ( function_exists( 'wp_cache_flush_runtime' ) ) {
		wp_cache_flush_runtime();
	}
}

$after             = $snapshot();
$fixture_rows_left = 0;
if ( $product_id > 0 ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact product cleanup evidence.
	$fixture_rows_left += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID=%d", $product_id ) );
}
foreach ( $order_ids as $order_id ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact HPOS order cleanup evidence.
	$fixture_rows_left += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE id=%d", $order_id ) );
}
foreach ( $license_ids as $license_id ) {
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact plugin fixture cleanup evidence.
	$fixture_rows_left += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_licenses WHERE id=%d", $license_id ) );
	$fixture_rows_left += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_activations WHERE license_id=%d", $license_id ) );
	$fixture_rows_left += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_events WHERE license_id=%d", $license_id ) );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
}
foreach ( $request_ids as $request_id ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact null-license event cleanup evidence.
	$fixture_rows_left += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_events WHERE request_id=%s", $request_id ) );
}

if ( $cleanup_failure instanceof Throwable || ! $cleanup_committed || $before !== $after || 0 !== $fixture_rows_left ) {
	dreamax_lm_f0607_fail( 'The owned concurrency fixtures were not completely removed.' );
}
if ( $failure instanceof Throwable ) {
	dreamax_lm_f0607_fail( 'The guarded concurrency verification failed at sanitized stage: ' . $failure_stage . '; substage: ' . $failure_substage . '; type: ' . $failure_type . '; line: ' . $failure_line . '.' );
}

echo wp_json_encode(
	array(
		'classification'                          => 'live_disposable_wordpress_woocommerce_innodb_parallel_workers',
		'parallel_workers_per_race'               => 2,
		'pool_contenders_in_flight'               => $pool_in_flight,
		'pool_single_assignment'                  => $pool_contract,
		'pool_other_order_recoverable_failure'    => $pool_contract,
		'pool_assignment_delivery_events_exact'   => $pool_events_exact,
		'different_instances_in_flight'           => $different_in_flight,
		'different_instances_last_slot_used_once' => $different_contract,
		'same_instance_contenders_in_flight'      => $same_in_flight,
		'same_instance_converged'                 => $same_contract,
		'activation_events_exact'                 => $activation_events,
		'cleanup_transaction_committed'           => $cleanup_committed,
		'plugin_aggregates_unchanged'             => $before === $after,
		'owned_fixture_rows_remaining'            => $fixture_rows_left,
		'outbound_email_sent'                     => false,
		'sensitive_output'                        => false,
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
