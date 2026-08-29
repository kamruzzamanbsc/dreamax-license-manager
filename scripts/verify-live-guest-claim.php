<?php
/**
 * Verifies the guarded F25 guest-order claim contract on disposable WordPress.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

use Dreamax\LicenseManager\Api\SourceAddress;
use Dreamax\LicenseManager\CustomerPortal\GuestClaimPolicy;
use Dreamax\LicenseManager\CustomerPortal\GuestClaimService;
use Dreamax\LicenseManager\CustomerPortal\GuestClaimToken;
use Dreamax\LicenseManager\Encryption\Crypto;
use Dreamax\LicenseManager\Events\AuditEventCatalog;
use Dreamax\LicenseManager\Integrations\WooCommerce\OrderLicensing;
use Dreamax\LicenseManager\Licenses\LicenseRepository;
use Dreamax\LicenseManager\ReleaseTools\DisposableEnvironmentGuard;
use Dreamax\LicenseManager\ReleaseTools\Filesystem;
use Dreamax\LicenseManager\Support\PublicId;
use Automattic\WooCommerce\Caches\OrderCache;

require_once __DIR__ . '/lib/release-tools.php';

/**
 * Stops with a fixed sanitized failure.
 *
 * @param string $message Sanitized message.
 */
function dreamax_lm_f25_fail( string $message ): never {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI verifier reports fixed sanitized errors.
	fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
	exit( 1 );
}

/**
 * Calls the loopback-only Mailpit API.
 *
 * @param string $path API path.
 * @param string $method HTTP method.
 * @return array<string,mixed>
 * @throws RuntimeException When the local catcher is unavailable.
 */
function dreamax_lm_f25_mailpit( string $path, string $method = 'GET' ): array {
	$response = wp_remote_request(
		'http://127.0.0.1:8025/api/v1/' . ltrim( $path, '/' ),
		array(
			'method'      => $method,
			'timeout'     => 10,
			'redirection' => 0,
		)
	);
	if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 400 ) {
		throw new RuntimeException( 'The loopback mail catcher is unavailable.' );
	}
	if ( 'DELETE' === $method || '' === wp_remote_retrieve_body( $response ) ) {
		return array();
	}
	$decoded = json_decode( wp_remote_retrieve_body( $response ), true, 512, JSON_THROW_ON_ERROR );
	return is_array( $decoded ) ? $decoded : array();
}

/**
 * Collects strings recursively without retaining them beyond the current message check.
 *
 * @param mixed $value Source value.
 * @param array $strings Collected strings.
 * @phpstan-param list<string> $strings
 */
function dreamax_lm_f25_strings( $value, array &$strings ): void {
	if ( is_string( $value ) ) {
		$strings[] = $value;
		return;
	}
	if ( is_array( $value ) ) {
		foreach ( $value as $child ) {
			dreamax_lm_f25_strings( $child, $strings );
		}
	}
}

/**
 * Issues one eligible claim, proves the local email contract, and returns the proof privately.
 *
 * @param GuestClaimService $service Claim service.
 * @param int               $user_id Target user.
 * @param int               $order_id Guest order.
 * @param string            $email Synthetic recipient.
 * @return array{proof:string,mail_contract:bool}
 * @throws RuntimeException When issuance or capture fails.
 */
function dreamax_lm_f25_issue( GuestClaimService $service, int $user_id, int $order_id, string $email ): array {
	dreamax_lm_f25_mailpit( 'messages', 'DELETE' );
	if ( ! $service->issue( $user_id, $order_id, $email, GuestClaimPolicy::MIN_LIFETIME ) ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Sanitized delivery-vs-eligibility diagnosis for an exact owned order.
		$status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}dreamax_lm_guest_claims WHERE order_id=%d ORDER BY id DESC LIMIT 1", $order_id ) );
		throw new RuntimeException( 'invalidated' === $status ? 'The local claim delivery was rejected.' : 'An eligible guest claim was not issued.' );
	}
	usleep( 400000 );
	$list     = dreamax_lm_f25_mailpit( 'messages' );
	$messages = isset( $list['messages'] ) && is_array( $list['messages'] ) ? $list['messages'] : array();
	if ( 1 !== count( $messages ) ) {
		throw new RuntimeException( 'The local catcher did not receive exactly one claim email.' );
	}
	$message_id = (string) ( $messages[0]['ID'] ?? '' );
	if ( 1 !== preg_match( '/^[A-Za-z0-9_-]+$/D', $message_id ) ) {
		throw new RuntimeException( 'The local message reference was invalid.' );
	}
	$message = dreamax_lm_f25_mailpit( 'message/' . rawurlencode( $message_id ) );
	$strings = array();
	dreamax_lm_f25_strings( $message, $strings );
	$proofs       = array();
	$email_match  = false;
	$contains_url = false;
	foreach ( $strings as $string ) {
		$email_match  = $email_match || str_contains( strtolower( $string ), strtolower( $email ) );
		$contains_url = $contains_url || 1 === preg_match( '/https?:\/\//i', $string );
		if ( preg_match_all( '/Enter this one-time code[\s\S]*?([A-Za-z0-9_-]{43})/i', $string, $matches ) ) {
			foreach ( $matches[1] as $proof ) {
				$proofs[] = (string) $proof;
			}
		}
	}
	$proofs = array_values( array_unique( $proofs ) );
	if ( 1 !== count( $proofs ) || ! ( new GuestClaimToken() )->valid_format( $proofs[0] ) || ! $email_match || $contains_url ) {
		throw new RuntimeException( 'The captured claim email contract did not match.' );
	}
	dreamax_lm_f25_mailpit( 'messages', 'DELETE' );
	return array(
		'proof'         => $proofs[0],
		'mail_contract' => true,
	);
}

/**
 * Creates a paid guest order with exactly one assigned generated license.
 *
 * @param WC_Product_Simple $product Licensed product.
 * @param string            $email Synthetic billing email.
 * @return array{order:WC_Order,license_id:int}
 * @throws RuntimeException When the fixture cannot be created exactly.
 */
function dreamax_lm_f25_order( WC_Product_Simple $product, string $email ): array {
	$order = wc_create_order( array( 'status' => 'pending' ) );
	if ( ! $order instanceof WC_Order ) {
		throw new RuntimeException( 'A synthetic guest order could not be created.' );
	}
	$order->set_billing_email( $email );
	$order->set_customer_id( 0 );
	$order->set_payment_method( 'bacs' );
	$item_id = (int) $order->add_product( $product, 1 );
	$order->calculate_totals();
	$order->set_date_paid( time() );
	$order->set_status( 'processing' );
	$order->save();
	$rows        = ( new LicenseRepository() )->for_order_item( $item_id );
	$customer_id = 1 === count( $rows ) ? $rows[0]['customer_id'] : null;
	if ( 1 !== count( $rows ) || 'assigned' !== (string) $rows[0]['lifecycle_status'] || ( null !== $customer_id && 0 !== (int) $customer_id ) ) {
		throw new RuntimeException( 'The guest-order license fixture was not exact.' );
	}
	return array(
		'order'      => $order,
		'license_id' => (int) $rows[0]['id'],
	);
}

/**
 * Runs two real processes against one locked claim row.
 *
 * @param int                       $claim_id Exact claim gate.
 * @param list<array<string,mixed>> $payloads Worker inputs.
 * @param string                    $wp_root WordPress root.
 * @param string                    $marker Disposable marker.
 * @param string                    $temp_dir Owned barrier directory.
 * @return array{results:list<array<string,mixed>>,both_in_flight:bool}
 * @throws RuntimeException When the race cannot be proven.
 */
function dreamax_lm_f25_race( int $claim_id, array $payloads, string $wp_root, string $marker, string $temp_dir ): array {
	global $wpdb;
	$processes      = array();
	$transaction    = false;
	$go_file        = $temp_dir . DIRECTORY_SEPARATOR . 'claim.go';
	$both_in_flight = false;
	try {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Holds only the exact owned claim as the race gate.
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			throw new RuntimeException( 'The race gate could not start.' );
		}
		$transaction = true;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Locks only the exact owned claim.
		$locked = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}dreamax_lm_guest_claims WHERE id=%d FOR UPDATE", $claim_id ) );
		if ( $claim_id !== $locked ) {
			throw new RuntimeException( 'The exact race gate was unavailable.' );
		}
		foreach ( $payloads as $index => $payload ) {
			$ready_file            = $temp_dir . DIRECTORY_SEPARATOR . 'claim-' . $index . '.ready';
			$payload['ready_file'] = $ready_file;
			$payload['go_file']    = $go_file;
			$descriptor            = array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			);
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Runs only the committed worker; the proof travels through STDIN.
			$process = proc_open(
				array( PHP_BINARY, __DIR__ . '/lib/guest-claim-worker.php', '--environment-marker=' . $marker, '--wp-root=' . $wp_root ),
				$descriptor,
				$pipes,
				__DIR__
			);
			if ( ! is_resource( $process ) ) {
				throw new RuntimeException( 'A guest-claim worker could not start.' );
			}
			$encoded = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );
			if ( ! is_string( $encoded ) ) {
				throw new RuntimeException( 'A worker input could not be encoded.' );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Private child pipe avoids proof-bearing arguments and files.
			fwrite( $pipes[0], $encoded );
			fclose( $pipes[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes owned pipe.
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
		$deadline = microtime( true ) + 20.0;
		do {
			$ready = true;
			foreach ( $processes as $worker ) {
				$status = proc_get_status( $worker['process'] );
				$ready  = $ready && $status['running'] && is_file( $worker['ready_file'] );
			}
			if ( ! $ready ) {
				usleep( 20000 );
			}
		} while ( ! $ready && microtime( true ) < $deadline );
		if ( ! $ready || false === file_put_contents( $go_file, 'go' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Owned empty barrier marker.
			throw new RuntimeException( 'The claim workers did not reach the barrier.' );
		}
		usleep( 400000 );
		$both_in_flight = true;
		foreach ( $processes as $worker ) {
			$both_in_flight = $both_in_flight && proc_get_status( $worker['process'] )['running'];
		}
		if ( ! $both_in_flight || false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases the exact gate.
			throw new RuntimeException( 'Both claim contenders were not proven in flight.' );
		}
		$transaction = false;
		$deadline    = microtime( true ) + 30.0;
		do {
			$running = false;
			foreach ( $processes as $worker ) {
				$running = $running || proc_get_status( $worker['process'] )['running'];
			}
			if ( $running ) {
				usleep( 20000 );
			}
		} while ( $running && microtime( true ) < $deadline );
		if ( $running ) {
			throw new RuntimeException( 'A claim worker exceeded its bounded runtime.' );
		}
		$results = array();
		foreach ( $processes as $worker ) {
			stream_set_blocking( $worker['pipes'][1], true );
			stream_set_blocking( $worker['pipes'][2], true );
			$stdout = stream_get_contents( $worker['pipes'][1] );
			$stderr = stream_get_contents( $worker['pipes'][2] );
			fclose( $worker['pipes'][1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes owned pipe.
			fclose( $worker['pipes'][2] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes owned pipe.
			proc_close( $worker['process'] );
			$result = is_string( $stdout ) ? json_decode( trim( $stdout ), true ) : null;
			if ( '' !== trim( (string) $stderr ) || ! is_array( $result ) || 'completed' !== ( $result['status'] ?? '' ) ) {
				throw new RuntimeException( 'A claim worker returned an invalid result.' );
			}
			$results[] = $result;
		}
		return array(
			'results'        => $results,
			'both_in_flight' => $both_in_flight,
		);
	} catch ( Throwable $error ) {
		if ( $transaction ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases only the exact race gate.
		}
		foreach ( $processes as $worker ) {
			if ( is_resource( $worker['process'] ) && proc_get_status( $worker['process'] )['running'] ) {
				proc_terminate( $worker['process'] );
			}
			foreach ( $worker['pipes'] as $pipe ) {
				if ( is_resource( $pipe ) ) {
					fclose( $pipe ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Failure cleanup of owned pipe.
				}
			}
			if ( is_resource( $worker['process'] ) ) {
				proc_close( $worker['process'] );
			}
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The fixed message is safe; the chained exception is never rendered.
		throw new RuntimeException( 'The guarded claim race failed.', 0, $error );
	}
}

$options = getopt( '', array( 'environment-marker:', 'wp-root:', 'diagnose-only', 'diagnose-orphaned', 'diagnose-orphaned-events', 'cleanup-owned', 'cleanup-orphaned', 'cleanup-orphaned-events' ) );
$marker  = (string) ( $options['environment-marker'] ?? '' );
$wp_root = realpath( (string) ( $options['wp-root'] ?? '' ) );
if ( false === $wp_root || ! is_file( $wp_root . '/wp-load.php' ) ) {
	dreamax_lm_f25_fail( 'A valid disposable WordPress root is required.' );
}
if ( ! defined( 'DISABLE_WP_CRON' ) ) {
	define( 'DISABLE_WP_CRON', true );
}
if ( ! defined( 'WP_USE_THEMES' ) ) {
	define( 'WP_USE_THEMES', false );
}
require_once $wp_root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
if ( ! defined( 'DB_NAME' ) ) {
	dreamax_lm_f25_fail( 'The database identity is unavailable.' );
}
DisposableEnvironmentGuard::assertSafe(
	array(
		'marker'   => $marker,
		'database' => (string) DB_NAME,
		'site_url' => home_url( '/' ),
	)
);
if ( ! is_plugin_active( 'dreamax-license-manager/dreamax-license-manager.php' ) || ! function_exists( 'WC' ) || ! class_exists( GuestClaimService::class ) ) {
	dreamax_lm_f25_fail( 'The required disposable runtime is unavailable.' );
}

global $wpdb;
$wpdb->suppress_errors( true );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounds only this verifier process's lock wait.
$wpdb->query( 'SET SESSION innodb_lock_wait_timeout=10' );
$required_tables = array(
	$wpdb->users,
	$wpdb->usermeta,
	$wpdb->posts,
	$wpdb->postmeta,
	$wpdb->prefix . 'wc_orders',
	$wpdb->prefix . 'wc_order_addresses',
	$wpdb->prefix . 'woocommerce_order_items',
	$wpdb->prefix . 'woocommerce_order_itemmeta',
	$wpdb->prefix . 'dreamax_lm_licenses',
	$wpdb->prefix . 'dreamax_lm_events',
	$wpdb->prefix . 'dreamax_lm_guest_claims',
	$wpdb->prefix . 'dreamax_lm_order_owners',
	$wpdb->prefix . 'dreamax_lm_rate_limits',
);
foreach ( $required_tables as $table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only mutation-safety preflight.
	$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s', (string) DB_NAME, $table ) );
	if ( 'InnoDB' !== $engine ) {
		dreamax_lm_f25_fail( 'A required F25 table is not safely transactional.' );
	}
}
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only stale-fixture preflight.
$stale_users    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'dlm_f25_%'" );
$stale_products = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product' AND post_title LIKE 'DLM F25 temporary%'" );
$stale_orders   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_order_addresses WHERE address_type='billing' AND email LIKE 'dlm-f25-%@example.invalid'" );
// phpcs:enable
if ( isset( $options['diagnose-only'] ) ) {
	try {
		$mailpit       = dreamax_lm_f25_mailpit( 'messages' );
		$mailpit_ready = isset( $mailpit['messages'] ) && is_array( $mailpit['messages'] );
	} catch ( Throwable $error ) {
		unset( $error );
		$mailpit_ready = false;
	}
	echo wp_json_encode(
		array(
			'classification'          => 'sanitized_f25_read_only_diagnosis',
			'disposable_guard_passed' => true,
			'required_tables_innodb'  => true,
			'mailpit_loopback_ready'  => $mailpit_ready,
			'stale_users'             => $stale_users,
			'stale_products'          => $stale_products,
			'stale_orders'            => $stale_orders,
			'sensitive_output'        => false,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . PHP_EOL;
	exit( $mailpit_ready ? 0 : 1 );
}
if ( isset( $options['diagnose-orphaned'] ) || isset( $options['cleanup-orphaned'] ) ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Selects recent orphan candidates without exposing their values.
	$orphan_rows      = $wpdb->get_results(
		"SELECT l.id,l.order_id FROM {$wpdb->prefix}dreamax_lm_licenses l LEFT JOIN {$wpdb->prefix}wc_orders o ON o.id=l.order_id LEFT JOIN {$wpdb->posts} p ON p.ID=l.order_id AND p.post_type='shop_order' LEFT JOIN {$wpdb->users} u ON u.ID=l.customer_id WHERE l.created_at >= UTC_TIMESTAMP() - INTERVAL 30 MINUTE AND l.order_id IS NOT NULL AND o.id IS NULL AND p.ID IS NULL AND (l.customer_id IS NULL OR u.ID IS NULL)",
		ARRAY_A
	);
	$orphan_ids       = is_array( $orphan_rows ) ? array_map( static fn( array $row ): int => (int) $row['id'], $orphan_rows ) : array();
	$orphan_order_ids = is_array( $orphan_rows ) ? array_values( array_unique( array_map( static fn( array $row ): int => (int) $row['order_id'], $orphan_rows ) ) ) : array();
	$event_types      = array();
	$orphan_event_ids = array();
	foreach ( $orphan_ids as $orphan_id ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Counts only events linked to exact candidate rows.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,event_type FROM {$wpdb->prefix}dreamax_lm_events WHERE license_id=%d", $orphan_id ), ARRAY_A );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$orphan_event_ids[]         = (int) $row['id'];
			$event_type                 = (string) $row['event_type'];
			$event_types[ $event_type ] = ( $event_types[ $event_type ] ?? 0 ) + 1;
		}
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Finds only recent null-license events belonging to exact candidate order groups.
	$null_events = $wpdb->get_results( "SELECT id,event_type,metadata FROM {$wpdb->prefix}dreamax_lm_events WHERE license_id IS NULL AND occurred_at >= UTC_TIMESTAMP() - INTERVAL 30 MINUTE", ARRAY_A );
	foreach ( is_array( $null_events ) ? $null_events : array() as $row ) {
		$metadata = json_decode( (string) $row['metadata'], true );
		if ( ! is_array( $metadata ) || ! in_array( (int) ( $metadata['order_id'] ?? 0 ), $orphan_order_ids, true ) ) {
			continue;
		}
		$orphan_event_ids[]         = (int) $row['id'];
		$event_type                 = (string) $row['event_type'];
		$event_types[ $event_type ] = ( $event_types[ $event_type ] ?? 0 ) + 1;
	}
	ksort( $event_types );
	$expected_types = array(
		AuditEventCatalog::LICENSE_ASSIGNED,
		AuditEventCatalog::LICENSE_CREATED,
		AuditEventCatalog::LICENSE_DELIVERED,
		AuditEventCatalog::ORDER_AUTOMATIC_ALLOCATION_COMPLETED,
	);
	$safe_contract  = 0 < count( $orphan_ids )
		&& count( $orphan_event_ids ) === 4 * count( $orphan_ids )
		&& array() === array_diff( array_keys( $event_types ), $expected_types );
	if ( isset( $options['cleanup-orphaned'] ) && $safe_contract ) {
		foreach ( $orphan_event_ids as $event_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Deletes exact contracted orphan event.
			$wpdb->delete( $wpdb->prefix . 'dreamax_lm_events', array( 'id' => $event_id ), array( '%d' ) );
		}
		foreach ( $orphan_ids as $orphan_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Deletes exact contracted orphan license.
			$wpdb->delete( $wpdb->prefix . 'dreamax_lm_licenses', array( 'id' => $orphan_id ), array( '%d' ) );
		}
	}
	echo wp_json_encode(
		array(
			'classification'            => isset( $options['cleanup-orphaned'] ) ? 'sanitized_f25_orphan_cleanup' : 'sanitized_f25_orphan_diagnosis',
			'orphan_license_candidates' => count( $orphan_ids ),
			'orphan_order_groups'       => count( $orphan_order_ids ),
			'linked_event_types'        => $event_types,
			'linked_event_count'        => count( $orphan_event_ids ),
			'exact_cleanup_contract'    => $safe_contract,
			'cleanup_attempted'         => isset( $options['cleanup-orphaned'] ) && $safe_contract,
			'sensitive_output'          => false,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . PHP_EOL;
	exit( $safe_contract ? 0 : 1 );
}
if ( isset( $options['diagnose-orphaned-events'] ) || isset( $options['cleanup-orphaned-events'] ) ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Selects only recent null-license allocation events for orphan validation.
	$recent_events    = $wpdb->get_results( $wpdb->prepare( "SELECT id,metadata FROM {$wpdb->prefix}dreamax_lm_events WHERE license_id IS NULL AND event_type=%s AND occurred_at >= UTC_TIMESTAMP() - INTERVAL 30 MINUTE", AuditEventCatalog::ORDER_AUTOMATIC_ALLOCATION_COMPLETED ), ARRAY_A );
	$orphan_event_ids = array();
	foreach ( is_array( $recent_events ) ? $recent_events : array() as $recent_event ) {
		$metadata = json_decode( (string) $recent_event['metadata'], true );
		$order_id = is_array( $metadata ) ? (int) ( $metadata['order_id'] ?? 0 ) : 0;
		if ( $order_id < 1 || wc_get_order( $order_id ) instanceof WC_Order ) {
			continue;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Confirms no business row remains for the orphan event.
		$license_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_licenses WHERE order_id=%d", $order_id ) );
		if ( 0 === $license_count ) {
			$orphan_event_ids[] = (int) $recent_event['id'];
		}
	}
	$safe_event_contract = 0 < count( $orphan_event_ids );
	if ( isset( $options['cleanup-orphaned-events'] ) && $safe_event_contract ) {
		foreach ( $orphan_event_ids as $event_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Deletes only the exact validated orphan event.
			$wpdb->delete( $wpdb->prefix . 'dreamax_lm_events', array( 'id' => $event_id ), array( '%d' ) );
		}
	}
	echo wp_json_encode(
		array(
			'classification'          => isset( $options['cleanup-orphaned-events'] ) ? 'sanitized_f25_orphan_event_cleanup' : 'sanitized_f25_orphan_event_diagnosis',
			'orphan_event_candidates' => count( $orphan_event_ids ),
			'exact_cleanup_contract'  => $safe_event_contract,
			'cleanup_attempted'       => isset( $options['cleanup-orphaned-events'] ) && $safe_event_contract,
			'sensitive_output'        => false,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . PHP_EOL;
	exit( $safe_event_contract ? 0 : 1 );
}
if ( isset( $options['cleanup-owned'] ) ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Selects only uniquely named verifier-owned orders after a failed run.
	$owned_order_ids = $wpdb->get_col( "SELECT order_id FROM {$wpdb->prefix}wc_order_addresses WHERE address_type='billing' AND email REGEXP '^dlm-f25-(a|changed)-[a-f0-9]{16}@example\\.invalid$'" );
	foreach ( is_array( $owned_order_ids ) ? $owned_order_ids : array() as $owned_order_id ) {
		$owned_order = wc_get_order( (int) $owned_order_id );
		if ( $owned_order instanceof WC_Order ) {
			$owned_order->delete( true );
		}
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Selects only exact verifier-owned user names.
	$owned_user_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->users} WHERE user_login REGEXP '^dlm_f25_[ab]_[a-f0-9]{16}$'" );
	foreach ( is_array( $owned_user_ids ) ? $owned_user_ids : array() as $owned_user_id ) {
		wp_delete_user( (int) $owned_user_id );
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Selects only exact verifier-owned product names.
	$owned_product_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND post_title REGEXP '^DLM F25 temporary claim product [a-f0-9]{16}$'" );
	foreach ( is_array( $owned_product_ids ) ? $owned_product_ids : array() as $owned_product_id ) {
		$owned_product = wc_get_product( (int) $owned_product_id );
		if ( $owned_product instanceof WC_Product ) {
			$owned_product->delete( true );
		}
	}
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact post-cleanup residue counts.
	$remaining  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_order_addresses WHERE address_type='billing' AND email REGEXP '^dlm-f25-(a|changed)-[a-f0-9]{16}@example\\.invalid$'" );
	$remaining += (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login REGEXP '^dlm_f25_[ab]_[a-f0-9]{16}$'" );
	$remaining += (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product' AND post_title REGEXP '^DLM F25 temporary claim product [a-f0-9]{16}$'" );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	echo wp_json_encode(
		array(
			'classification'                => 'sanitized_f25_exact_owned_cleanup',
			'owned_orders_selected'         => is_array( $owned_order_ids ) ? count( $owned_order_ids ) : 0,
			'owned_users_selected'          => is_array( $owned_user_ids ) ? count( $owned_user_ids ) : 0,
			'owned_products_selected'       => is_array( $owned_product_ids ) ? count( $owned_product_ids ) : 0,
			'owned_named_residue_remaining' => $remaining,
			'sensitive_output'              => false,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . PHP_EOL;
	exit( 0 === $remaining ? 0 : 1 );
}
if ( 0 !== $stale_users || 0 !== $stale_products || 0 !== $stale_orders ) {
	dreamax_lm_f25_fail( 'A stale owned F25 fixture must be resolved before mutation.' );
}

$snapshot = static function () use ( $wpdb ): array {
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact cleanup evidence.
	return array(
		'users'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ),
		'usermeta' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->usermeta}" ),
		'licenses' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_licenses" ),
		'events'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_events" ),
		'claims'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_guest_claims" ),
		'owners'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_order_owners" ),
		'rates'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_rate_limits" ),
	);
	// phpcs:enable
};

$before             = $snapshot();
$suffix             = substr( hash( 'sha256', random_bytes( 32 ) ), 0, 16 );
$temp_dir           = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dreamax-lm-f25-' . $suffix;
$user_ids           = array();
$orders             = array();
$order_ids          = array();
$license_ids        = array();
$claim_public_ids   = array();
$event_ids          = array();
$rate_hashes        = array();
$rate_before        = array();
$proofs             = array();
$product            = null;
$product_id         = 0;
$product_public_id  = '';
$event_floor        = 0;
$failure            = null;
$cleanup_failure    = null;
$cleanup_complete   = false;
$mail_contract      = false;
$email_only_blocked = false;
$generic_failures   = false;
$concurrency_passed = false;
$single_use_passed  = false;
$expiry_passed      = false;
$ownership_passed   = false;
$admin_passed       = false;
$audit_safe         = false;
$verification_stage = 'fixture_setup';
$failure_stage      = 'none';
$mail_failure_kind  = 'none';
$single_use_checks  = array(
	'order_owner'    => false,
	'claim_consumed' => false,
	'owner_row'      => false,
	'license_owner'  => false,
);

if ( ! mkdir( $temp_dir, 0700 ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Random permission-restricted barrier.
	dreamax_lm_f25_fail( 'The private F25 barrier could not be created.' );
}

try {
	$verification_stage = 'mail_setup';
	dreamax_lm_f25_mailpit( 'messages', 'DELETE' );
	add_filter( 'woocommerce_email_enabled_new_order', '__return_false', PHP_INT_MAX );
	add_filter( 'woocommerce_email_enabled_customer_processing_order', '__return_false', PHP_INT_MAX );
	add_filter( 'woocommerce_email_enabled_customer_completed_order', '__return_false', PHP_INT_MAX );
	add_filter( 'woocommerce_email_log_enabled', '__return_false', PHP_INT_MAX );
	add_filter( 'wp_mail_from', static fn(): string => 'wordpress@example.com', PHP_INT_MAX );
	add_action(
		'wp_mail_failed',
		static function ( WP_Error $error ) use ( &$mail_failure_kind ): void {
			$message           = strtolower( $error->get_error_message() );
			$kinds             = array(
				'smtp connect'          => 'smtp_connect',
				'could not connect'     => 'smtp_connect',
				'invalid address'       => 'invalid_address',
				'recipients failed'     => 'recipient_rejected',
				'data not accepted'     => 'message_rejected',
				'authenticate'          => 'authentication',
				'mail function'         => 'mail_function',
				'could not instantiate' => 'mail_function',
			);
			$mail_failure_kind = 'unclassified';
			foreach ( $kinds as $fragment => $kind ) {
				if ( str_contains( $message, $fragment ) ) {
					$mail_failure_kind = $kind;
					break;
				}
			}
		},
		PHP_INT_MAX
	);
	add_action(
		'phpmailer_init',
		static function ( PHPMailer\PHPMailer\PHPMailer $mailer ): void {
			$mailer->isSMTP();
			// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer public API.
			$mailer->Host        = '127.0.0.1';
			$mailer->Port        = 1025;
			$mailer->SMTPAuth    = false;
			$mailer->SMTPAutoTLS = false;
			$mailer->SMTPSecure  = '';
			// phpcs:enable
		},
		PHP_INT_MAX
	);

	$verification_stage = 'user_setup';
	$users              = array();
	foreach ( array( 'a', 'b' ) as $label ) {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'dlm_f25_' . $label . '_' . $suffix,
				'user_pass'  => wp_generate_password( 32, true, true ),
				'user_email' => 'dlm-f25-' . $label . '-' . $suffix . '@example.invalid',
				'role'       => 'customer',
			)
		);
		if ( is_wp_error( $user_id ) ) {
			throw new RuntimeException( 'A synthetic claim user could not be created.' );
		}
		$user_ids[]      = (int) $user_id;
		$users[ $label ] = get_user_by( 'id', (int) $user_id );
	}
	if ( ! $users['a'] instanceof WP_User || ! $users['b'] instanceof WP_User ) {
		throw new RuntimeException( 'Synthetic claim users could not be loaded.' );
	}
	$administrators = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ids',
		)
	);
	if ( array() === $administrators ) {
		throw new RuntimeException( 'A disposable administrator was unavailable.' );
	}
	$administrator_id = (int) $administrators[0];

	$verification_stage = 'product_setup';
	$product            = new WC_Product_Simple();
	$product->set_name( 'DLM F25 temporary claim product ' . $suffix );
	$product->set_status( 'publish' );
	$product->set_virtual( true );
	$product->set_regular_price( '10' );
	$product_id = (int) $product->save();
	$product->update_meta_data( '_dreamax_lm_enabled', 'yes' );
	$product_public_id = PublicId::generate( 'prd' );
	$product->update_meta_data( '_dreamax_lm_product_public_id', $product_public_id );
	$product->update_meta_data( '_dreamax_lm_source', 'generated' );
	$product->update_meta_data( '_dreamax_lm_issuance', 'per_quantity' );
	$product->update_meta_data( '_dreamax_lm_activation_limit', '1' );
	$product->save_meta_data();

	$verification_stage = 'order_setup';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact owned-event boundary.
	$event_floor = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$wpdb->prefix}dreamax_lm_events" );
	foreach ( array( 'main', 'expired', 'changed' ) as $label ) {
		$fixture          = dreamax_lm_f25_order( $product, (string) $users['a']->user_email );
		$orders[ $label ] = $fixture['order'];
		$order_ids[]      = (int) $fixture['order']->get_id();
		$license_ids[]    = $fixture['license_id'];
	}
	$main_order    = $orders['main'];
	$expired_order = $orders['expired'];
	$changed_order = $orders['changed'];
	$unknown_order = 900000000 + random_int( 1, 9999999 );

	$verification_stage = 'rate_snapshot';
	$crypto             = new Crypto();
	$network            = ( new SourceAddress() )->network();
	foreach ( array( 'issue', 'verify' ) as $operation ) {
		foreach ( $user_ids as $user_id ) {
			$rate_hashes[] = $crypto->keyed_hash( 'guest-claim:' . $operation . ':user:' . $user_id, 'rate-limit' );
		}
		$rate_hashes[] = $crypto->keyed_hash( 'guest-claim:' . $operation . ':network:' . $network, 'rate-limit' );
		foreach ( array_merge( $order_ids, array( $unknown_order ) ) as $order_id ) {
			$rate_hashes[] = $crypto->keyed_hash( 'guest-claim:' . $operation . ':order:' . $order_id, 'rate-limit' );
		}
	}
	$rate_hashes = array_values( array_unique( $rate_hashes, SORT_REGULAR ) );
	foreach ( $rate_hashes as $hash ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact relevant-bucket snapshot.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT HEX(bucket_hash) AS bucket_hash,tokens,updated_microtime,expires_at FROM {$wpdb->prefix}dreamax_lm_rate_limits WHERE bucket_hash=UNHEX(%s)", bin2hex( $hash ) ), ARRAY_A );
		if ( is_array( $row ) ) {
			$rate_before[ bin2hex( $hash ) ] = $row;
		}
	}

	$verification_stage = 'email_only_block';
	$service            = new GuestClaimService();
	$failure_shape      = ( new GuestClaimPolicy() )->public_failure();
	dreamax_lm_f25_mailpit( 'messages', 'DELETE' );
	$email_only_blocked = false === $service->issue( (int) $users['b']->ID, (int) $main_order->get_id(), (string) $users['a']->user_email, GuestClaimPolicy::MIN_LIFETIME );
	$blocked_mail       = dreamax_lm_f25_mailpit( 'messages' );
	$email_only_blocked = $email_only_blocked && array() === ( $blocked_mail['messages'] ?? array() );

	$verification_stage = 'main_issue';
	$issued_main        = dreamax_lm_f25_issue( $service, (int) $users['a']->ID, (int) $main_order->get_id(), (string) $users['a']->user_email );
	$proofs[]           = $issued_main['proof'];
	$mail_contract      = $issued_main['mail_contract'];
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact issued claim assertion.
	$main_claim = $wpdb->get_row( $wpdb->prepare( "SELECT id,public_id,status,LENGTH(token_hash) AS hash_length FROM {$wpdb->prefix}dreamax_lm_guest_claims WHERE order_id=%d ORDER BY id DESC LIMIT 1", (int) $main_order->get_id() ), ARRAY_A );
	if ( ! is_array( $main_claim ) || 'issued' !== $main_claim['status'] || 32 !== (int) $main_claim['hash_length'] ) {
		throw new RuntimeException( 'The issued claim storage contract did not match.' );
	}
	$claim_public_ids[] = (string) $main_claim['public_id'];
	$wrong_account      = $service->verify( (int) $users['b']->ID, (int) $main_order->get_id(), $issued_main['proof'] );
	$wrong_order        = $service->verify( (int) $users['a']->ID, $unknown_order, $issued_main['proof'] );
	$generic_failures   = $failure_shape === $wrong_account && $failure_shape === $wrong_order;

	$verification_stage = 'parallel_verification';
	$race               = dreamax_lm_f25_race(
		(int) $main_claim['id'],
		array(
			array(
				'user_id'  => (int) $users['a']->ID,
				'order_id' => (int) $main_order->get_id(),
				'proof'    => $issued_main['proof'],
			),
			array(
				'user_id'  => (int) $users['b']->ID,
				'order_id' => (int) $main_order->get_id(),
				'proof'    => $issued_main['proof'],
			),
		),
		$wp_root,
		$marker,
		$temp_dir
	);
	$outcomes           = array_map( static fn( array $result ): bool => true === ( $result['success'] ?? false ), $race['results'] );
	sort( $outcomes );
	$concurrency_passed = $race['both_in_flight'] && array( false, true ) === $outcomes;
	wc_get_container()->get( OrderCache::class )->remove( (int) $main_order->get_id() );
	clean_post_cache( (int) $main_order->get_id() );
	$claimed_order = wc_get_order( (int) $main_order->get_id() );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact post-race outcome.
	$claim_consumed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_guest_claims WHERE id=%d AND status='consumed' AND token_hash IS NULL AND active_order_id IS NULL", (int) $main_claim['id'] ) );
	$owner_exact    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_order_owners WHERE order_id=%d AND customer_id=%d AND claim_id=%d", (int) $main_order->get_id(), (int) $users['a']->ID, (int) $main_claim['id'] ) );
	$license_owned  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_licenses WHERE id=%d AND customer_id=%d", $license_ids[0], (int) $users['a']->ID ) );
	// phpcs:enable
	$single_use_checks = array(
		'order_owner'    => $claimed_order instanceof WC_Order && (int) $claimed_order->get_customer_id() === (int) $users['a']->ID,
		'claim_consumed' => 1 === $claim_consumed,
		'owner_row'      => 1 === $owner_exact,
		'license_owner'  => 1 === $license_owned,
	);
	$single_use_passed = ! in_array( false, $single_use_checks, true );
	$replay            = $service->verify( (int) $users['a']->ID, (int) $main_order->get_id(), $issued_main['proof'] );
	$generic_failures  = $generic_failures && $failure_shape === $replay;

	$verification_stage = 'administrator_paths';
	$service->admin_release( (int) $main_order->get_id(), $administrator_id );
	$service->admin_override( (int) $main_order->get_id(), (int) $users['b']->ID, $administrator_id );
	wc_get_container()->get( OrderCache::class )->remove( (int) $main_order->get_id() );
	clean_post_cache( (int) $main_order->get_id() );
	$admin_order = wc_get_order( (int) $main_order->get_id() );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact administrative final ownership.
	$admin_owner   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_order_owners WHERE order_id=%d AND customer_id=%d AND source='admin_override'", (int) $main_order->get_id(), (int) $users['b']->ID ) );
	$admin_license = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_licenses WHERE id=%d AND customer_id=%d", $license_ids[0], (int) $users['b']->ID ) );
	// phpcs:enable
	$admin_passed = $admin_order instanceof WC_Order && (int) $admin_order->get_customer_id() === (int) $users['b']->ID && 1 === $admin_owner && 1 === $admin_license;

	$verification_stage = 'expiry_path';
	$issued_expired     = dreamax_lm_f25_issue( $service, (int) $users['a']->ID, (int) $expired_order->get_id(), (string) $users['a']->user_email );
	$proofs[]           = $issued_expired['proof'];
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact owned expiry injection.
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}dreamax_lm_guest_claims SET expires_at=%s WHERE order_id=%d AND status='issued'", gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS ), (int) $expired_order->get_id() ) );
	$expired_result = $service->verify( (int) $users['a']->ID, (int) $expired_order->get_id(), $issued_expired['proof'] );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact expiry outcome.
	$expiry_passed = $failure_shape === $expired_result && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_guest_claims WHERE order_id=%d AND status='expired' AND token_hash IS NULL", (int) $expired_order->get_id() ) );

	$verification_stage = 'ownership_change_path';
	$issued_changed     = dreamax_lm_f25_issue( $service, (int) $users['a']->ID, (int) $changed_order->get_id(), (string) $users['a']->user_email );
	$proofs[]           = $issued_changed['proof'];
	$changed_order->set_billing_email( 'dlm-f25-changed-' . $suffix . '@example.invalid' );
	$changed_order->save();
	$changed_result = $service->verify( (int) $users['a']->ID, (int) $changed_order->get_id(), $issued_changed['proof'] );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact authoritative-change outcome.
	$ownership_passed = $failure_shape === $changed_result && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_guest_claims WHERE order_id=%d AND status='invalidated' AND token_hash IS NULL", (int) $changed_order->get_id() ) );

	$verification_stage = 'audit_contract';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded owned audit set.
	$audit_rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,event_type,request_id,metadata FROM {$wpdb->prefix}dreamax_lm_events WHERE id>%d ORDER BY id", $event_floor ), ARRAY_A );
	$counts     = array();
	$audit_safe = true;
	foreach ( is_array( $audit_rows ) ? $audit_rows : array() as $row ) {
		$metadata = json_decode( (string) $row['metadata'], true );
		$order_id = is_array( $metadata ) ? (int) ( $metadata['order_id'] ?? 0 ) : 0;
		if ( ! in_array( $order_id, $order_ids, true ) ) {
			continue;
		}
		$event_ids[]           = (int) $row['id'];
		$event_type            = (string) $row['event_type'];
		$counts[ $event_type ] = ( $counts[ $event_type ] ?? 0 ) + 1;
		$encoded               = wp_json_encode( $metadata, JSON_UNESCAPED_SLASHES );
		$audit_safe            = $audit_safe
			&& is_string( $encoded )
			&& 0 === preg_match( '/claim_code|claim_token|token_hash|billing_email|license_key|authorization/i', $encoded );
		foreach ( $proofs as $proof ) {
			$audit_safe = $audit_safe && ! str_contains( $encoded, $proof );
		}
	}
	$audit_safe = $audit_safe
		&& 3 === ( $counts[ AuditEventCatalog::GUEST_CLAIM_ISSUED ] ?? 0 )
		&& 1 === ( $counts[ AuditEventCatalog::GUEST_CLAIM_SUCCEEDED ] ?? 0 )
		&& 1 <= ( $counts[ AuditEventCatalog::GUEST_CLAIM_REPLAY_FAILED ] ?? 0 )
		&& 1 === ( $counts[ AuditEventCatalog::GUEST_CLAIM_EXPIRED_FAILED ] ?? 0 )
		&& 2 <= ( $counts[ AuditEventCatalog::GUEST_CLAIM_CONFLICT_FAILED ] ?? 0 )
		&& 1 === ( $counts[ AuditEventCatalog::GUEST_CLAIM_RELEASED ] ?? 0 )
		&& 1 === ( $counts[ AuditEventCatalog::GUEST_CLAIM_ADMINISTRATOR_OVERRIDE ] ?? 0 );

	if ( ! $email_only_blocked || ! $mail_contract || ! $generic_failures || ! $concurrency_passed || ! $single_use_passed || ! $expiry_passed || ! $ownership_passed || ! $admin_passed || ! $audit_safe ) {
		throw new RuntimeException( 'The live F25 contract did not match.' );
	}
} catch ( Throwable $error ) {
	$failure         = $error;
	$failure_reasons = array(
		'An eligible guest claim was not issued.'          => 'claim_issue_rejected',
		'The local claim delivery was rejected.'           => 'mail_delivery_rejected',
		'The local catcher did not receive exactly one claim email.' => 'mail_count_mismatch',
		'The local message reference was invalid.'         => 'mail_reference_invalid',
		'The captured claim email contract did not match.' => 'mail_content_mismatch',
	);
	$failure_stage   = $verification_stage . ( isset( $failure_reasons[ $error->getMessage() ] ) ? ':' . $failure_reasons[ $error->getMessage() ] : '' );
} finally {
	try {
		dreamax_lm_f25_mailpit( 'messages', 'DELETE' );
	} catch ( Throwable $error ) {
		$cleanup_failure = $error;
	}
	foreach ( $order_ids as $order_id ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact owned owner cleanup.
		$wpdb->delete( $wpdb->prefix . 'dreamax_lm_order_owners', array( 'order_id' => $order_id ), array( '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact owned claim cleanup.
		$wpdb->delete( $wpdb->prefix . 'dreamax_lm_guest_claims', array( 'order_id' => $order_id ), array( '%d' ) );
	}
	foreach ( $orders as $fixture_order_object ) {
		if ( $fixture_order_object instanceof WC_Order ) {
			$fixture_order_object->delete( true );
		}
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Final fallback selects only uniquely named owned orders before license cleanup.
	$owned_order_ids = $wpdb->get_col( "SELECT order_id FROM {$wpdb->prefix}wc_order_addresses WHERE address_type='billing' AND email REGEXP '^dlm-f25-(a|changed)-[a-f0-9]{16}@example\\.invalid$'" );
	foreach ( is_array( $owned_order_ids ) ? $owned_order_ids : array() as $owned_order_id ) {
		$owned_order = wc_get_order( (int) $owned_order_id );
		if ( $owned_order instanceof WC_Order ) {
			$owned_order->delete( true );
		}
	}
	if ( $product instanceof WC_Product_Simple ) {
		$product->delete( true );
	}
	foreach ( $order_ids as $order_id ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Finds every license created for only the exact owned order.
		$owned_license_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}dreamax_lm_licenses WHERE order_id=%d", $order_id ) );
		if ( is_array( $owned_license_ids ) ) {
			$license_ids = array_merge( $license_ids, array_map( 'intval', $owned_license_ids ) );
		}
	}
	if ( '' !== $product_public_id ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Finds detached rows belonging only to the exact random verifier product identity.
		$product_license_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}dreamax_lm_licenses WHERE product_public_id=%s", $product_public_id ) );
		if ( is_array( $product_license_ids ) ) {
			$license_ids = array_merge( $license_ids, array_map( 'intval', $product_license_ids ) );
		}
	}
	foreach ( array_values( array_unique( $license_ids ) ) as $license_id ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact owned license-event cleanup after order callbacks finish.
		$wpdb->delete( $wpdb->prefix . 'dreamax_lm_events', array( 'license_id' => $license_id ), array( '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact owned license cleanup after order callbacks finish.
		$wpdb->delete( $wpdb->prefix . 'dreamax_lm_licenses', array( 'id' => $license_id ), array( '%d' ) );
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Captures late callbacks only after the exact event boundary.
	$late_events = $wpdb->get_results( $wpdb->prepare( "SELECT id,metadata FROM {$wpdb->prefix}dreamax_lm_events WHERE id>%d", $event_floor ), ARRAY_A );
	foreach ( is_array( $late_events ) ? $late_events : array() as $late_event ) {
		$metadata = json_decode( (string) $late_event['metadata'], true );
		if ( is_array( $metadata ) && in_array( (int) ( $metadata['order_id'] ?? 0 ), $order_ids, true ) ) {
			$event_ids[] = (int) $late_event['id'];
		}
	}
	foreach ( array_values( array_unique( $event_ids ) ) as $event_id ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact bounded owned event cleanup.
		$wpdb->delete( $wpdb->prefix . 'dreamax_lm_events', array( 'id' => $event_id ), array( '%d' ) );
	}
	foreach ( $rate_hashes as $hash ) {
		$hex = bin2hex( $hash );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Restores only exact touched buckets.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}dreamax_lm_rate_limits WHERE bucket_hash=UNHEX(%s)", $hex ) );
		if ( isset( $rate_before[ $hex ] ) ) {
			$row = $rate_before[ $hex ];
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Restores exact pre-run bucket state.
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->prefix}dreamax_lm_rate_limits (bucket_hash,tokens,updated_microtime,expires_at) VALUES (UNHEX(%s),%f,%f,%s)", $hex, (float) $row['tokens'], (float) $row['updated_microtime'], (string) $row['expires_at'] ) );
		}
	}
	foreach ( $user_ids as $user_id ) {
		if ( ! wp_delete_user( $user_id ) ) {
			$cleanup_failure = $cleanup_failure ?? new RuntimeException( 'An owned user could not be removed.' );
		}
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Final sweep runs after every callback-capable cleanup operation.
	$final_events = $wpdb->get_results( $wpdb->prepare( "SELECT id,event_type,metadata FROM {$wpdb->prefix}dreamax_lm_events WHERE id>%d", $event_floor ), ARRAY_A );
	foreach ( is_array( $final_events ) ? $final_events : array() as $final_event ) {
		$metadata             = json_decode( (string) $final_event['metadata'], true );
		$order_id             = is_array( $metadata ) ? (int) ( $metadata['order_id'] ?? 0 ) : 0;
		$owned_order_event    = in_array( $order_id, $order_ids, true );
		$bounded_orphan_event = false;
		if ( ! $owned_order_event && $order_id > 0 && AuditEventCatalog::ORDER_AUTOMATIC_ALLOCATION_COMPLETED === (string) $final_event['event_type'] && ! wc_get_order( $order_id ) instanceof WC_Order ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Confirms a bounded post-callback event has no remaining license row.
			$bounded_orphan_event = 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_licenses WHERE order_id=%d", $order_id ) );
		}
		if ( $owned_order_event || $bounded_orphan_event ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Deletes only a post-callback event for an exact owned order.
			$wpdb->delete( $wpdb->prefix . 'dreamax_lm_events', array( 'id' => (int) $final_event['id'] ), array( '%d' ) );
		}
	}
	foreach ( $proofs as &$proof ) {
		if ( function_exists( 'sodium_memzero' ) ) {
			sodium_memzero( $proof );
		}
	}
	unset( $proof );
	if ( is_dir( $temp_dir ) ) {
		try {
			Filesystem::removeTree( $temp_dir, sys_get_temp_dir() );
		} catch ( Throwable $error ) {
			$cleanup_failure = $cleanup_failure ?? $error;
		}
	}
	$cleanup_complete = ! $cleanup_failure instanceof Throwable;
}

$after = $snapshot();
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact residue diagnosis.
$fixture_rows_left  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'dlm_f25_%'" );
$fixture_rows_left += (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product' AND post_title LIKE 'DLM F25 temporary%'" );
$fixture_rows_left += (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_order_addresses WHERE address_type='billing' AND email LIKE 'dlm-f25-%@example.invalid'" );
// phpcs:enable
if ( ! $cleanup_complete || $before !== $after || 0 !== $fixture_rows_left ) {
	$deltas = array();
	foreach ( $before as $name => $count ) {
		$deltas[ $name ] = (int) ( $after[ $name ] ?? 0 ) - (int) $count;
	}
	echo wp_json_encode(
		array(
			'classification'   => 'sanitized_f25_cleanup_diagnosis',
			'count_deltas'     => $deltas,
			'named_residue'    => $fixture_rows_left,
			'sensitive_output' => false,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . PHP_EOL;
	dreamax_lm_f25_fail( 'The exact F25 cleanup contract did not match.' );
}
if ( $failure instanceof Throwable ) {
	echo wp_json_encode(
		array(
			'classification'                => 'sanitized_f25_contract_diagnosis',
			'failure_stage'                 => $failure_stage,
			'mail_failure_kind'             => $mail_failure_kind,
			'mail_contract'                 => $mail_contract,
			'email_only_disclosure_blocked' => $email_only_blocked,
			'uniform_public_failures'       => $generic_failures,
			'concurrency_passed'            => $concurrency_passed,
			'single_use_passed'             => $single_use_passed,
			'single_use_checks'             => $single_use_checks,
			'expiry_passed'                 => $expiry_passed,
			'ownership_change_passed'       => $ownership_passed,
			'admin_paths_passed'            => $admin_passed,
			'audit_contract_passed'         => $audit_safe,
			'cleanup_passed'                => $before === $after,
			'sensitive_output'              => false,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . PHP_EOL;
	dreamax_lm_f25_fail( 'The guarded F25 verification failed.' );
}

echo wp_json_encode(
	array(
		'classification'                     => 'live_disposable_wordpress_woocommerce_innodb_mailpit_parallel_workers',
		'eligible_claim_emails'              => 3,
		'mail_recipient_and_no_url_proof'    => $mail_contract,
		'email_only_disclosure_blocked'      => $email_only_blocked,
		'uniform_public_failures'            => $generic_failures,
		'parallel_workers'                   => 2,
		'parallel_contenders_in_flight'      => $concurrency_passed,
		'exactly_one_parallel_success'       => $concurrency_passed,
		'single_use_consumption'             => $single_use_passed,
		'expiry_failed_closed'               => $expiry_passed,
		'ownership_change_invalidated_proof' => $ownership_passed,
		'admin_release_and_override'         => $admin_passed,
		'audit_payloads_sanitized'           => $audit_safe,
		'cleanup_complete'                   => $cleanup_complete,
		'database_aggregates_unchanged'      => $before === $after,
		'owned_fixture_rows_remaining'       => $fixture_rows_left,
		'mail_messages_after_cleanup'        => 0,
		'outbound_email_external'            => false,
		'sensitive_output'                   => false,
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
