<?php
/**
 * Verifies that repeated WooCommerce allocation hooks converge without duplicates.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

use Dreamax\LicenseManager\Events\AuditEventCatalog;
use Dreamax\LicenseManager\Integrations\WooCommerce\OrderLicensing;
use Dreamax\LicenseManager\Licenses\LicenseRepository;
use Dreamax\LicenseManager\ReleaseTools\DisposableEnvironmentGuard;
use Dreamax\LicenseManager\Support\Settings;

require_once __DIR__ . '/lib/release-tools.php';

/**
 * Stops with a sanitized error that does not disclose paths or fixture values.
 *
 * @param string $message Sanitized failure message.
 */
function dreamax_lm_f05_fail( string $message ): never {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI verifier must report a sanitized failure to STDERR.
	fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
	exit( 1 );
}

/**
 * Returns the sole registered Dreamax allocation callback for a hook.
 *
 * @param string $hook Hook name.
 * @return array{callback:callable,priority:int,accepted_args:int}
 */
function dreamax_lm_f05_callback( string $hook ): array {
	global $wp_filter;

	$registered = array();
	$wp_hook    = $wp_filter[ $hook ] ?? null;
	if ( $wp_hook instanceof WP_Hook ) {
		foreach ( $wp_hook->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $definition ) {
				$callback = $definition['function'] ?? null;
				if ( is_array( $callback )
					&& isset( $callback[0], $callback[1] )
					&& $callback[0] instanceof OrderLicensing
					&& 'status_changed' === $callback[1]
					&& is_callable( $callback ) ) {
					$registered[] = array(
						'callback'      => $callback,
						'priority'      => (int) $priority,
						'accepted_args' => (int) ( $definition['accepted_args'] ?? 1 ),
					);
				}
			}
		}
	}

	if ( 1 !== count( $registered ) ) {
		dreamax_lm_f05_fail( 'Exactly one Dreamax allocation callback must be registered for each status hook.' );
	}
	return $registered[0];
}

/**
 * Replays one hook while isolating the registered Dreamax callback in memory.
 *
 * @param string                                                  $to Target order status.
 * @param int                                                     $order_id Internal order identifier.
 * @param array{callback:callable,priority:int,accepted_args:int} $definition Callback definition.
 * @param int                                                     $replays Replay count.
 */
function dreamax_lm_f05_replay( string $to, int $order_id, array $definition, int $replays ): void {
	global $wp_filter;
	$hook = 'woocommerce_order_status_changed';

	$original = $wp_filter[ $hook ];
	$isolated = new WP_Hook();
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The verifier isolates only Dreamax's callback and restores the original hook in finally.
	$wp_filter[ $hook ] = $isolated;
	add_action( $hook, $definition['callback'], $definition['priority'], $definition['accepted_args'] );

	try {
		for ( $attempt = 0; $attempt < $replays; ++$attempt ) {
			$order = wc_get_order( $order_id );
			do_action( $hook, $order_id, 'pending', $to, $order );
		}
	} finally {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the exact original WordPress hook object after the isolated replay.
		$wp_filter[ $hook ] = $original;
	}
}

$options = getopt( '', array( 'environment-marker:', 'wp-root:' ) );
$marker  = (string) ( $options['environment-marker'] ?? '' );
$wp_root = realpath( (string) ( $options['wp-root'] ?? '' ) );

if ( false === $wp_root || ! is_file( $wp_root . '/wp-load.php' ) ) {
	dreamax_lm_f05_fail( 'A valid WordPress root is required.' );
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
	dreamax_lm_f05_fail( 'The database identity is unavailable.' );
}

DisposableEnvironmentGuard::assertSafe(
	array(
		'marker'   => $marker,
		'database' => (string) DB_NAME,
		'site_url' => home_url( '/' ),
	)
);

if ( ! is_plugin_active( 'dreamax-license-manager/dreamax-license-manager.php' ) || ! class_exists( OrderLicensing::class ) ) {
	dreamax_lm_f05_fail( 'Dreamax License Manager is not active.' );
}

global $wpdb;

$licenses   = new LicenseRepository();
$candidates = array();
$orders     = wc_get_orders(
	array(
		'limit'   => 25,
		'status'  => array( 'processing' ),
		'orderby' => 'date',
		'order'   => 'DESC',
	)
);

foreach ( $orders as $candidate_order ) {
	if ( ! $candidate_order instanceof WC_Order
		|| 'bacs' !== $candidate_order->get_payment_method()
		|| ! $candidate_order->is_paid()
		|| ! str_ends_with( strtolower( (string) $candidate_order->get_billing_email() ), '@example.invalid' ) ) {
		continue;
	}
	$order_licenses = $licenses->for_order( (int) $candidate_order->get_id() );
	$items          = array_values( $candidate_order->get_items( 'line_item' ) );
	if ( 1 !== count( $order_licenses )
		|| 'assigned' !== (string) $order_licenses[0]['lifecycle_status']
		|| 1 !== (int) $order_licenses[0]['quantity_slot']
		|| 1 !== count( $items )
		|| ! $items[0] instanceof WC_Order_Item_Product
		|| 1 !== (int) $items[0]->get_quantity()
		|| (int) $items[0]->get_id() !== (int) $order_licenses[0]['order_item_id']
		|| 1 !== (int) $items[0]->get_meta( '_dreamax_lm_delivery_target_slots', true ) ) {
		continue;
	}
	$product = $items[0]->get_product();
	if ( ! $product instanceof WC_Product
		|| ! $product->is_type( 'simple' )
		|| ! $product->is_virtual()
		|| 'yes' !== $product->get_meta( '_dreamax_lm_enabled', true )
		|| 'generated' !== $product->get_meta( '_dreamax_lm_source', true )
		|| 'per_quantity' !== $product->get_meta( '_dreamax_lm_issuance', true ) ) {
		continue;
	}
	$candidates[] = array(
		'order'   => $candidate_order,
		'item'    => $items[0],
		'license' => $order_licenses[0],
	);
}

if ( 1 !== count( $candidates ) ) {
	dreamax_lm_f05_fail( 'Exactly one sanitized F05 candidate order is required.' );
}

$candidate     = $candidates[0];
$fixture_order = $candidate['order'];
$item          = $candidate['item'];
$license       = $candidate['license'];
$order_id      = (int) $fixture_order->get_id();
$item_id       = (int) $item->get_id();
$license_id    = (int) $license['id'];

$callback = dreamax_lm_f05_callback( 'woocommerce_order_status_changed' );
$statuses = Settings::allocation_statuses();
if ( ! in_array( 'processing', $statuses, true ) || ! in_array( 'completed', $statuses, true ) ) {
	dreamax_lm_f05_fail( 'The duplicate replay fixture requires the default processing and completed delivery statuses.' );
}

/**
 * Captures sanitized duplicate-sensitive database state.
 *
 * @return array<string,int|string>
 */
$snapshot = static function () use ( $wpdb, $licenses, $order_id, $item_id, $license_id ): array {
	$order_licenses = $licenses->for_order( $order_id );
	$encoded        = wp_json_encode( $order_licenses );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact live replay evidence must bypass caches.
	return array(
		'order_licenses'    => count( $order_licenses ),
		'item_licenses'     => count( $licenses->for_order_item( $item_id ) ),
		'created_events'    => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_events WHERE license_id = %d AND event_type = %s", $license_id, AuditEventCatalog::LICENSE_CREATED ) ),
		'assigned_events'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_events WHERE license_id = %d AND event_type = %s", $license_id, AuditEventCatalog::LICENSE_ASSIGNED ) ),
		'delivered_events'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_events WHERE license_id = %d AND event_type = %s", $license_id, AuditEventCatalog::LICENSE_DELIVERED ) ),
		'allocation_events' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_events WHERE event_type = %s AND request_id = %s", AuditEventCatalog::ORDER_AUTOMATIC_ALLOCATION_COMPLETED, 'automatic:' . $order_id ) ),
		'license_rows'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_licenses" ),
		'event_rows'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_events" ),
		'order_notes'       => count( wc_get_order_notes( array( 'order_id' => $order_id ) ) ),
		'license_digest'    => is_string( $encoded ) ? hash( 'sha256', $encoded ) : '',
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
};

$before = $snapshot();
if ( 1 !== $before['order_licenses']
	|| 1 !== $before['item_licenses']
	|| 1 !== $before['created_events']
	|| 1 !== $before['assigned_events']
	|| 1 !== $before['delivered_events']
	|| 1 !== $before['allocation_events'] ) {
	dreamax_lm_f05_fail( 'The candidate does not have the required exact pre-replay aggregate.' );
}

$required_tables = array(
	$wpdb->prefix . 'dreamax_lm_licenses',
	$wpdb->prefix . 'dreamax_lm_events',
	$wpdb->comments,
	$wpdb->commentmeta,
);
foreach ( $required_tables as $required_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction safety must be proven against live table engines.
	$engine = $wpdb->get_var(
		$wpdb->prepare(
			'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
			(string) DB_NAME,
			$required_table
		)
	);
	if ( 'InnoDB' !== $engine ) {
		dreamax_lm_f05_fail( 'A required replay table is not safely transactional.' );
	}
}

if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	dreamax_lm_f05_fail( 'The replay transaction could not start.' );
}

$failure     = null;
$during      = array();
$rolled_back = false;
try {
	dreamax_lm_f05_replay( 'processing', $order_id, $callback, 2 );
	dreamax_lm_f05_replay( 'completed', $order_id, $callback, 2 );
	$during = $snapshot();
	if ( $before !== $during ) {
		throw new RuntimeException( 'Repeated status hooks changed license, event, or order-note state.' );
	}
} catch ( Throwable $error ) {
	$failure = $error;
} finally {
	$rolled_back = false !== $wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
}

$after = $snapshot();
if ( $failure instanceof Throwable ) {
	dreamax_lm_f05_fail( $failure->getMessage() );
}
if ( ! $rolled_back || $before !== $after ) {
	dreamax_lm_f05_fail( 'The disposable replay transaction did not restore its starting state.' );
}

echo wp_json_encode(
	array(
		'classification'              => 'live_disposable_wordpress_innodb',
		'registered_status_callbacks' => 1,
		'processing_hook_replays'     => 2,
		'completed_hook_replays'      => 2,
		'order_licenses_before'       => $before['order_licenses'],
		'order_licenses_after'        => $after['order_licenses'],
		'created_events_after'        => $after['created_events'],
		'assigned_events_after'       => $after['assigned_events'],
		'delivered_events_after'      => $after['delivered_events'],
		'allocation_events_after'     => $after['allocation_events'],
		'duplicate_slots_created'     => 0,
		'duplicate_events_created'    => 0,
		'order_notes_created'         => 0,
		'transaction_rolled_back'     => $rolled_back,
		'database_state_unchanged'    => $before === $after,
		'sensitive_output'            => false,
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
