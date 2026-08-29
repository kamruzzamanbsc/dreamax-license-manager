<?php
/**
 * Verifies the F13 order-policy and lifecycle acceptance matrix.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

use Dreamax\LicenseManager\Events\AuditEventCatalog;
use Dreamax\LicenseManager\Integrations\WooCommerce\OrderLicensing;
use Dreamax\LicenseManager\Integrations\WooCommerce\OrderPolicyService;
use Dreamax\LicenseManager\Licenses\KeyNormalizer;
use Dreamax\LicenseManager\Licenses\LicenseRepository;
use Dreamax\LicenseManager\Licenses\LicenseService;
use Dreamax\LicenseManager\Licenses\LifecycleService;
use Dreamax\LicenseManager\ReleaseTools\DisposableEnvironmentGuard;
use Dreamax\LicenseManager\Support\PublicId;

require_once __DIR__ . '/lib/release-tools.php';

/**
 * Stops with a sanitized error.
 *
 * @param string $message Sanitized failure message.
 */
function dreamax_lm_f13_fail( string $message ): never {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI verifier reports only fixed sanitized messages.
	fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
	exit( 1 );
}

/**
 * Creates an owned product with an explicit licensing policy.
 *
 * @param string $name Product name.
 * @param bool   $enabled Licensing enabled state.
 * @param string $source License source.
 * @return WC_Product_Simple
 */
function dreamax_lm_f13_product( string $name, bool $enabled, string $source = 'generated' ): WC_Product_Simple {
	$product = new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_status( 'publish' );
	$product->set_virtual( true );
	$product->set_regular_price( '10' );
	$product->save();
	$product->update_meta_data( '_dreamax_lm_enabled', $enabled ? 'yes' : 'no' );
	$product->update_meta_data( '_dreamax_lm_product_public_id', PublicId::generate( 'prd' ) );
	$product->update_meta_data( '_dreamax_lm_source', $source );
	$product->update_meta_data( '_dreamax_lm_issuance', 'per_quantity' );
	$product->update_meta_data( '_dreamax_lm_activation_limit', '2' );
	$product->update_meta_data( '_dreamax_lm_valid_days', '30' );
	$product->update_meta_data( '_dreamax_lm_refund_policy', 'suspend' );
	$product->update_meta_data( '_dreamax_lm_cancellation_policy', 'retain' );
	$product->save_meta_data();
	return $product;
}

/**
 * Creates an owned order and optionally moves it to the paid Processing state.
 *
 * @param WC_Product $product Product fixture.
 * @param int        $quantity Purchased quantity.
 * @param int        $customer_id Customer identifier.
 * @param bool       $paid Paid-order state.
 * @return array{order:WC_Order,item_id:int}
 * @throws RuntimeException When the order cannot be created.
 */
function dreamax_lm_f13_order( WC_Product $product, int $quantity, int $customer_id, bool $paid ): array {
	$order = wc_create_order( array( 'status' => 'pending' ) );
	if ( ! $order instanceof WC_Order ) {
		throw new RuntimeException( 'The owned order could not be created.' );
	}
	$order->set_customer_id( $customer_id );
	$order->set_billing_email( 'dlm-f13@example.invalid' );
	$order->set_payment_method( 'bacs' );
	$item_id = (int) $order->add_product( $product, $quantity );
	$order->calculate_totals();
	if ( $paid ) {
		$order->set_date_paid( time() );
		$order->set_status( 'processing' );
	}
	$order->save();
	return array(
		'order'   => $order,
		'item_id' => $item_id,
	);
}

/**
 * Creates a policy-bearing assigned license.
 *
 * @param int        $order_id Order identifier.
 * @param int        $item_id Order-item identifier.
 * @param int        $slot Quantity slot.
 * @param int        $customer_id Customer identifier.
 * @param string     $product_public_id Product public identifier.
 * @param string     $refund_policy Refund policy.
 * @param string     $cancellation_policy Cancellation policy.
 * @param string     $source License source.
 * @param array<int> $owned_license_ids Owned license identifiers.
 * @return array{id:int,public_id:string,key:string}
 */
function dreamax_lm_f13_assigned_license( int $order_id, int $item_id, int $slot, int $customer_id, string $product_public_id, string $refund_policy, string $cancellation_policy, string $source, array &$owned_license_ids ): array {
	$result              = ( new LicenseService() )->create_generated(
		array(
			'lifecycle_status'  => 'assigned',
			'product_public_id' => $product_public_id,
			'order_id'          => $order_id,
			'order_item_id'     => $item_id,
			'quantity_slot'     => $slot,
			'customer_id'       => $customer_id,
			'activation_limit'  => 2,
			'expires_at'        => gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ),
			'actor_type'        => 'woocommerce',
			'source'            => $source,
			'request_id'        => 'f13-policy-fixture',
			'metadata'          => array(
				'woocommerce' => array(
					'order_id'            => $order_id,
					'order_item_id'       => $item_id,
					'quantity_slot'       => $slot,
					'issuance'            => 'per_quantity',
					'source'              => $source,
					'refund_policy'       => $refund_policy,
					'cancellation_policy' => $cancellation_policy,
					'delivered_at'        => gmdate( 'Y-m-d H:i:s' ),
				),
			),
		)
	);
	$owned_license_ids[] = (int) $result['id'];
	return $result;
}

/**
 * Creates an assigned but never-delivered pool fixture for eligible release.
 *
 * @param int        $order_id Order identifier.
 * @param int        $item_id Order-item identifier.
 * @param int        $slot Quantity slot.
 * @param int        $customer_id Customer identifier.
 * @param string     $product_public_id Product public identifier.
 * @param string     $refund_policy Refund policy.
 * @param string     $cancellation_policy Cancellation policy.
 * @param int        $operator_id Synthetic administrator identifier.
 * @param array<int> $owned_license_ids Owned license identifiers.
 * @return array{id:int,public_id:string,key:string}
 * @throws RuntimeException When the fixture cannot be assigned.
 */
function dreamax_lm_f13_unused_pool_license( int $order_id, int $item_id, int $slot, int $customer_id, string $product_public_id, string $refund_policy, string $cancellation_policy, int $operator_id, array &$owned_license_ids ): array {
	global $wpdb;
	$key = 'F13-' . strtoupper( bin2hex( random_bytes( 18 ) ) );
	try {
		$result = ( new LicenseService() )->import(
			$key,
			KeyNormalizer::IMPORTED,
			null,
			array(
				'product_public_id' => $product_public_id,
				'lifecycle_status'  => 'available',
				'actor_type'        => 'administrator',
				'actor_id'          => $operator_id,
				'source'            => 'import',
				'request_id'        => 'f13-policy-fixture',
				'metadata'          => array( 'fixture' => 'f13' ),
			)
		);
	} catch ( Throwable $error ) {
		unset( $error );
		throw new RuntimeException( 'F13 unused pool import step failed.' );
	}
	$metadata = wp_json_encode(
		array(
			'fixture'     => 'f13',
			'woocommerce' => array(
				'order_id'            => $order_id,
				'order_item_id'       => $item_id,
				'quantity_slot'       => $slot,
				'issuance'            => 'per_quantity',
				'source'              => 'pool',
				'refund_policy'       => $refund_policy,
				'cancellation_policy' => $cancellation_policy,
			),
		)
	);
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Creates an exact no-delivery owned acceptance fixture.
	if ( ! is_string( $metadata ) || 1 !== $wpdb->update(
		$wpdb->prefix . 'dreamax_lm_licenses',
		array(
			'lifecycle_status' => 'assigned',
			'order_id'         => $order_id,
			'order_item_id'    => $item_id,
			'quantity_slot'    => $slot,
			'customer_id'      => $customer_id,
			'metadata'         => $metadata,
			'updated_at'       => gmdate( 'Y-m-d H:i:s' ),
		),
		array( 'id' => (int) $result['id'] ),
		array( '%s', '%d', '%d', '%d', '%d', '%s', '%s' ),
		array( '%d' )
	) ) {
		throw new RuntimeException( 'The unused pool fixture could not be assigned.' );
	}
	$owned_license_ids[] = (int) $result['id'];
	return $result;
}

/**
 * Returns the current status of an owned license.
 *
 * @param int $license_id License identifier.
 */
function dreamax_lm_f13_status( int $license_id ): string {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Acceptance assertions require a fresh row.
	return (string) $wpdb->get_var( $wpdb->prepare( "SELECT lifecycle_status FROM {$wpdb->prefix}dreamax_lm_licenses WHERE id=%d", $license_id ) );
}

$options = getopt( '', array( 'environment-marker:', 'wp-root:', 'diagnose-only', 'cleanup-only' ) );
$marker  = (string) ( $options['environment-marker'] ?? '' );
$wp_root = realpath( (string) ( $options['wp-root'] ?? '' ) );
if ( false === $wp_root || ! is_file( $wp_root . '/wp-load.php' ) ) {
	dreamax_lm_f13_fail( 'A valid WordPress root is required.' );
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
	dreamax_lm_f13_fail( 'The database identity is unavailable.' );
}
DisposableEnvironmentGuard::assertSafe(
	array(
		'marker'   => $marker,
		'database' => (string) DB_NAME,
		'site_url' => home_url( '/' ),
	)
);
if ( ! is_plugin_active( 'dreamax-license-manager/dreamax-license-manager.php' ) || ! function_exists( 'WC' ) || ! class_exists( OrderLicensing::class ) ) {
	dreamax_lm_f13_fail( 'The required WordPress plugins are not active.' );
}

global $wpdb;
$recovery_backup_file = trailingslashit( sys_get_temp_dir() ) . 'dreamax-lm-f13-orphan-events-' . hash( 'sha256', (string) DB_NAME . '|' . home_url( '/' ) ) . '.bak';
$tables               = array(
	$wpdb->users,
	$wpdb->usermeta,
	$wpdb->posts,
	$wpdb->postmeta,
	$wpdb->comments,
	$wpdb->commentmeta,
	$wpdb->prefix . 'wc_orders',
	$wpdb->prefix . 'wc_order_addresses',
	$wpdb->prefix . 'wc_order_operational_data',
	$wpdb->prefix . 'woocommerce_order_items',
	$wpdb->prefix . 'woocommerce_order_itemmeta',
	$wpdb->prefix . 'dreamax_lm_licenses',
	$wpdb->prefix . 'dreamax_lm_activations',
	$wpdb->prefix . 'dreamax_lm_events',
	$wpdb->prefix . 'dreamax_lm_idempotency',
);
foreach ( $tables as $table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Mutation begins only after authoritative engine checks.
	$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s', (string) DB_NAME, $table ) );
	if ( 'InnoDB' !== $engine ) {
		dreamax_lm_f13_fail( 'A required F13 table is not safely transactional.' );
	}
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Stale guards and exact aggregate checks require fresh reads.
$stale_products = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product' AND post_title LIKE 'DLM F13 temporary %'" );
$stale_users    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'dlm_f13_%'" );
$stale_orders   = wc_get_orders(
	array(
		'limit'         => 1,
		'billing_email' => 'dlm-f13@example.invalid',
		'return'        => 'ids',
	)
);
$snapshot       = static function () use ( $wpdb ): array {
	return array(
		'licenses'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_licenses" ),
		'activations' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_activations" ),
		'events'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_events" ),
		'idempotency' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_idempotency" ),
		'users'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ),
	);
};
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
if ( isset( $options['diagnose-only'] ) ) {
	$owned_order_ids   = array_map(
		'intval',
		wc_get_orders(
			array(
				'limit'         => -1,
				'billing_email' => 'dlm-f13@example.invalid',
				'return'        => 'ids',
			)
		)
	);
	$owned_licenses    = 0;
	$owned_events      = 0;
	$owned_activations = 0;
	$owned_operations  = 0;
	foreach ( $owned_order_ids as $owned_order_id ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only exact owned-fixture diagnosis.
		$owned_licenses   += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_licenses WHERE order_id=%d", $owned_order_id ) );
		$owned_events     += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_events WHERE request_id=%s", 'automatic:' . $owned_order_id ) );
		$owned_operations += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_idempotency WHERE operation='order_resend' AND payload_digest=UNHEX(%s)", hash( 'sha256', 'order_resend|' . $owned_order_id ) ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only exact owned-fixture diagnosis.
	$owned_licenses                += (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_licenses WHERE metadata LIKE '%\"fixture\":\"f13\"%' AND (order_id IS NULL OR order_id=0)" );
	$owned_events                  += (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_events WHERE request_id LIKE 'f13-%'" );
	$owned_activations              = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_activations a INNER JOIN {$wpdb->prefix}dreamax_lm_licenses l ON l.id=a.license_id WHERE l.metadata LIKE '%\"fixture\":\"f13\"%' OR l.order_id IN (SELECT id FROM {$wpdb->prefix}wc_orders WHERE billing_email='dlm-f13@example.invalid')" );
	$fully_orphaned_licenses        = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_licenses l LEFT JOIN {$wpdb->users} u ON u.ID=l.customer_id LEFT JOIN {$wpdb->prefix}wc_orders o ON o.id=l.order_id LEFT JOIN {$wpdb->postmeta} pm ON pm.meta_key='_dreamax_lm_product_public_id' AND pm.meta_value=l.product_public_id WHERE l.customer_id IS NOT NULL AND u.ID IS NULL AND l.order_id IS NOT NULL AND o.id IS NULL AND pm.post_id IS NULL" );
	$recent_fully_orphaned_licenses = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_licenses l LEFT JOIN {$wpdb->users} u ON u.ID=l.customer_id LEFT JOIN {$wpdb->prefix}wc_orders o ON o.id=l.order_id LEFT JOIN {$wpdb->postmeta} pm ON pm.meta_key='_dreamax_lm_product_public_id' AND pm.meta_value=l.product_public_id WHERE l.customer_id IS NOT NULL AND u.ID IS NULL AND l.order_id IS NOT NULL AND o.id IS NULL AND pm.post_id IS NULL AND l.created_at >= UTC_TIMESTAMP() - INTERVAL 2 HOUR" );
	$fully_orphaned_order_events    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_events e LEFT JOIN {$wpdb->prefix}wc_orders o ON o.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(e.metadata,'$.order_id')) AS UNSIGNED) WHERE JSON_EXTRACT(e.metadata,'$.order_id') IS NOT NULL AND o.id IS NULL" );
	$recent_orphan_event_rows       = $wpdb->get_results( "SELECT e.event_type,COUNT(*) AS event_count FROM {$wpdb->prefix}dreamax_lm_events e LEFT JOIN {$wpdb->prefix}wc_orders o ON o.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(e.metadata,'$.order_id')) AS UNSIGNED) WHERE JSON_EXTRACT(e.metadata,'$.order_id') IS NOT NULL AND o.id IS NULL AND e.occurred_at >= UTC_TIMESTAMP() - INTERVAL 2 HOUR GROUP BY e.event_type ORDER BY e.event_type", ARRAY_A );
	$recent_orphan_event_types      = array();
	$recent_orphan_event_count      = 0;
	foreach ( $recent_orphan_event_rows as $recent_orphan_event_row ) {
		$event_type                               = (string) $recent_orphan_event_row['event_type'];
		$event_count                              = (int) $recent_orphan_event_row['event_count'];
		$recent_orphan_event_types[ $event_type ] = $event_count;
		$recent_orphan_event_count               += $event_count;
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	echo wp_json_encode(
		array(
			'classification'                 => 'sanitized_read_only_f13_owned_fixture_diagnosis',
			'owned_products'                 => $stale_products,
			'owned_users'                    => $stale_users,
			'owned_orders'                   => count( $owned_order_ids ),
			'owned_licenses'                 => $owned_licenses,
			'owned_activations'              => $owned_activations,
			'owned_events'                   => $owned_events,
			'owned_operations'               => $owned_operations,
			'fully_orphaned_licenses'        => $fully_orphaned_licenses,
			'recent_fully_orphaned_licenses' => $recent_fully_orphaned_licenses,
			'fully_orphaned_order_events'    => $fully_orphaned_order_events,
			'recent_orphan_order_events'     => $recent_orphan_event_count,
			'recent_orphan_event_types'      => $recent_orphan_event_types,
			'sensitive_output'               => false,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . PHP_EOL;
	exit( 0 );
}
if ( isset( $options['cleanup-only'] ) ) {
	if ( 0 !== $stale_products || 0 !== $stale_users || array() !== $stale_orders ) {
		dreamax_lm_f13_fail( 'Recovery cleanup found non-event F13 fixtures.' );
	}
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Recovery is restricted to the verifier's exact request marker.
	$other_owned_rows  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_licenses WHERE metadata LIKE '%\"fixture\":\"f13\"%'" );
	$owned_event_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_events WHERE request_id LIKE 'f13-%'" );
	if ( 0 !== $other_owned_rows ) {
		dreamax_lm_f13_fail( 'Recovery cleanup found non-event F13 fixtures.' );
	}
	$fully_orphaned_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_licenses l LEFT JOIN {$wpdb->users} u ON u.ID=l.customer_id LEFT JOIN {$wpdb->prefix}wc_orders o ON o.id=l.order_id LEFT JOIN {$wpdb->postmeta} pm ON pm.meta_key='_dreamax_lm_product_public_id' AND pm.meta_value=l.product_public_id WHERE l.customer_id IS NOT NULL AND u.ID IS NULL AND l.order_id IS NOT NULL AND o.id IS NULL AND pm.post_id IS NULL" );
	$orphaned_license_ids = array_map( 'intval', $wpdb->get_col( "SELECT l.id FROM {$wpdb->prefix}dreamax_lm_licenses l LEFT JOIN {$wpdb->users} u ON u.ID=l.customer_id LEFT JOIN {$wpdb->prefix}wc_orders o ON o.id=l.order_id LEFT JOIN {$wpdb->postmeta} pm ON pm.meta_key='_dreamax_lm_product_public_id' AND pm.meta_value=l.product_public_id WHERE l.customer_id IS NOT NULL AND u.ID IS NULL AND l.order_id IS NOT NULL AND o.id IS NULL AND pm.post_id IS NULL AND l.created_at >= UTC_TIMESTAMP() - INTERVAL 2 HOUR" ) );
	if ( count( $orphaned_license_ids ) !== $fully_orphaned_count || ! in_array( $fully_orphaned_count, array( 0, 1, 3 ), true ) ) {
		dreamax_lm_f13_fail( 'Recovery cleanup found an ambiguous orphan set.' );
	}
	$fully_orphaned_order_event_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_events e LEFT JOIN {$wpdb->prefix}wc_orders o ON o.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(e.metadata,'$.order_id')) AS UNSIGNED) WHERE JSON_EXTRACT(e.metadata,'$.order_id') IS NOT NULL AND o.id IS NULL" );
	$orphaned_order_event_ids         = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT e.id FROM {$wpdb->prefix}dreamax_lm_events e LEFT JOIN {$wpdb->prefix}wc_orders o ON o.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(e.metadata,'$.order_id')) AS UNSIGNED) WHERE JSON_EXTRACT(e.metadata,'$.order_id') IS NOT NULL AND o.id IS NULL AND e.event_type=%s AND e.occurred_at >= UTC_TIMESTAMP() - INTERVAL 2 HOUR", AuditEventCatalog::ORDER_REFUNDED ) ) );
	if ( count( $orphaned_order_event_ids ) !== $fully_orphaned_order_event_count || ! in_array( $fully_orphaned_order_event_count, array( 0, 1 ), true ) ) {
		dreamax_lm_f13_fail( 'Recovery cleanup found an ambiguous orphan event set.' );
	}
	$owned_event_rows          = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}dreamax_lm_events WHERE request_id LIKE 'f13-%'", ARRAY_A );
	$orphaned_order_event_rows = array();
	foreach ( $orphaned_order_event_ids as $orphaned_order_event_id ) {
		$orphaned_order_event_rows[] = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}dreamax_lm_events WHERE id=%d", $orphaned_order_event_id ), ARRAY_A );
	}
	$orphaned_license_rows = array();
	$orphaned_child_rows   = array(
		'activations' => array(),
		'events'      => array(),
	);
	foreach ( $orphaned_license_ids as $orphaned_license_id ) {
		$orphaned_license_rows[]              = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}dreamax_lm_licenses WHERE id=%d", $orphaned_license_id ), ARRAY_A );
		$orphaned_child_rows['activations'][] = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}dreamax_lm_activations WHERE license_id=%d", $orphaned_license_id ), ARRAY_A );
		$orphaned_child_rows['events'][]      = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}dreamax_lm_events WHERE license_id=%d", $orphaned_license_id ), ARRAY_A );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Preserves an earlier private local recovery backup across a repeated recovery.
	$previous_backup = is_file( $recovery_backup_file ) ? file_get_contents( $recovery_backup_file ) : null;
	$backup_payload  = wp_json_encode(
		array(
			'previous_recovery_backup' => is_string( $previous_backup ) ? $previous_backup : null,
			'owned_events'             => $owned_event_rows,
			'orphaned_order_events'    => $orphaned_order_event_rows,
			'orphaned_licenses'        => $orphaned_license_rows,
			'orphaned_children'        => $orphaned_child_rows,
		),
		JSON_UNESCAPED_SLASHES
	);
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A private local recovery backup must not use web transports.
	$backup_written = is_string( $backup_payload ) ? file_put_contents( $recovery_backup_file, $backup_payload, LOCK_EX ) : false;
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads back the private local recovery backup for exact verification.
	$backup_read = file_get_contents( $recovery_backup_file );
	if ( false === $backup_written || $backup_payload !== $backup_read ) {
		dreamax_lm_f13_fail( 'Recovery backup could not be verified.' );
	}
	if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
		dreamax_lm_f13_fail( 'Recovery cleanup transaction could not start.' );
	}
	foreach ( $orphaned_license_ids as $orphaned_license_id ) {
		$wpdb->delete( $wpdb->prefix . 'dreamax_lm_activations', array( 'license_id' => $orphaned_license_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'dreamax_lm_events', array( 'license_id' => $orphaned_license_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'dreamax_lm_licenses', array( 'id' => $orphaned_license_id ), array( '%d' ) );
	}
	foreach ( $orphaned_order_event_ids as $orphaned_order_event_id ) {
		$wpdb->delete( $wpdb->prefix . 'dreamax_lm_events', array( 'id' => $orphaned_order_event_id ), array( '%d' ) );
	}
	$deleted = $wpdb->query( "DELETE FROM {$wpdb->prefix}dreamax_lm_events WHERE request_id LIKE 'f13-%'" );
	if ( false === $deleted || false === $wpdb->query( 'COMMIT' ) ) {
		$wpdb->query( 'ROLLBACK' );
		dreamax_lm_f13_fail( 'Recovery cleanup could not be committed.' );
	}
	$remaining                       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_events WHERE request_id LIKE 'f13-%'" );
	$orphaned_remaining              = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_licenses l LEFT JOIN {$wpdb->users} u ON u.ID=l.customer_id LEFT JOIN {$wpdb->prefix}wc_orders o ON o.id=l.order_id LEFT JOIN {$wpdb->postmeta} pm ON pm.meta_key='_dreamax_lm_product_public_id' AND pm.meta_value=l.product_public_id WHERE l.customer_id IS NOT NULL AND u.ID IS NULL AND l.order_id IS NOT NULL AND o.id IS NULL AND pm.post_id IS NULL" );
	$orphaned_order_events_remaining = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_events e LEFT JOIN {$wpdb->prefix}wc_orders o ON o.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(e.metadata,'$.order_id')) AS UNSIGNED) WHERE JSON_EXTRACT(e.metadata,'$.order_id') IS NOT NULL AND o.id IS NULL" );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	if ( 0 !== $remaining || 0 !== $orphaned_remaining || 0 !== $orphaned_order_events_remaining || $owned_event_count !== (int) $deleted ) {
		dreamax_lm_f13_fail( 'Recovery cleanup proof failed.' );
	}
	echo wp_json_encode(
		array(
			'classification'                  => 'exact_owned_f13_orphan_recovery',
			'recovery_backup_verified'        => true,
			'owned_events_removed'            => (int) $deleted,
			'orphaned_order_events_removed'   => count( $orphaned_order_event_ids ),
			'orphaned_licenses_removed'       => count( $orphaned_license_ids ),
			'owned_event_rows_remaining'      => $remaining,
			'orphaned_license_rows_remaining' => $orphaned_remaining,
			'orphaned_order_events_remaining' => $orphaned_order_events_remaining,
			'non_event_fixtures_encountered'  => false,
			'sensitive_output'                => false,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . PHP_EOL;
	exit( 0 );
}
if ( 0 !== $stale_products || 0 !== $stale_users || array() !== $stale_orders ) {
	dreamax_lm_f13_fail( 'A stale owned F13 fixture must be cleaned before running.' );
}

$before                = $snapshot();
$products              = array();
$orders                = array();
$refunds               = array();
$users                 = array();
$owned_license_ids     = array();
$owned_request_ids     = array( 'f13-policy-fixture' );
$resend_operations     = array();
$failure               = null;
$cleanup_failure       = null;
$cleanup_committed     = false;
$checks                = array();
$mail_mode             = 'success';
$mail_calls            = 0;
$mail_contract         = true;
$expected_mail_include = array();
$expected_mail_exclude = array();
$current_stage         = 'bootstrap';
$failure_stage         = null;
$failure_code          = null;
$cleanup_order_ids     = array();
$diagnostic_counts     = array();

try {
	add_filter( 'woocommerce_email_enabled_new_order', '__return_false', PHP_INT_MAX );
	add_filter( 'woocommerce_email_enabled_customer_processing_order', '__return_false', PHP_INT_MAX );
	add_filter( 'woocommerce_email_enabled_customer_completed_order', '__return_false', PHP_INT_MAX );
	add_filter(
		'pre_wp_mail',
		static function ( $short_circuit, array $attributes ) use ( &$mail_mode, &$mail_calls, &$mail_contract, &$expected_mail_include, &$expected_mail_exclude ) {
			unset( $short_circuit );
			++$mail_calls;
			$body = (string) ( $attributes['message'] ?? '' );
			foreach ( $expected_mail_include as $value ) {
				$mail_contract = $mail_contract && str_contains( $body, $value );
			}
			foreach ( $expected_mail_exclude as $value ) {
				$mail_contract = $mail_contract && ! str_contains( $body, $value );
			}
			return 'success' === $mail_mode;
		},
		PHP_INT_MAX,
		2
	);

	$current_stage = 'temporary_users';
	$owner_id      = wp_insert_user(
		array(
			'user_login' => 'dlm_f13_owner_' . strtolower( bin2hex( random_bytes( 4 ) ) ),
			'user_pass'  => wp_generate_password( 32, true, true ),
			'user_email' => 'dlm-f13-owner@example.invalid',
			'role'       => 'customer',
		)
	);
	if ( is_wp_error( $owner_id ) ) {
		throw new RuntimeException( 'Owned customer creation failed.' );
	}
	$owner_id  = (int) $owner_id;
	$users[]   = $owner_id;
	$target_id = wp_insert_user(
		array(
			'user_login' => 'dlm_f13_target_' . strtolower( bin2hex( random_bytes( 4 ) ) ),
			'user_pass'  => wp_generate_password( 32, true, true ),
			'user_email' => 'dlm-f13-target@example.invalid',
			'role'       => 'customer',
		)
	);
	if ( is_wp_error( $target_id ) ) {
		throw new RuntimeException( 'Owned customer creation failed.' );
	}
	$target_id   = (int) $target_id;
	$users[]     = $target_id;
	$operator_id = wp_insert_user(
		array(
			'user_login' => 'dlm_f13_operator_' . strtolower( bin2hex( random_bytes( 4 ) ) ),
			'user_pass'  => wp_generate_password( 32, true, true ),
			'user_email' => 'dlm-f13-operator@example.invalid',
			'role'       => 'administrator',
		)
	);
	if ( is_wp_error( $operator_id ) ) {
		throw new RuntimeException( 'Owned operator creation failed.' );
	}
	$operator_id = (int) $operator_id;
	$users[]     = $operator_id;

	$licensing = new OrderLicensing();
	$policies  = new OrderPolicyService();
	$licenses  = new LicenseRepository();
	$lifecycle = new LifecycleService();

	$current_stage              = 'quantity_and_refund_boundaries';
	$quantity_product           = dreamax_lm_f13_product( 'DLM F13 temporary quantity product', true );
	$products[]                 = $quantity_product;
	$quantity_fixture           = dreamax_lm_f13_order( $quantity_product, 5, $owner_id, true );
	$quantity_order             = $quantity_fixture['order'];
	$quantity_item_id           = $quantity_fixture['item_id'];
	$orders[]                   = $quantity_order;
	$quantity_rows              = $licenses->for_order_item( $quantity_item_id );
	$owned_license_ids          = array_merge( $owned_license_ids, array_map( static fn( array $row ): int => (int) $row['id'], $quantity_rows ) );
	$owned_request_ids[]        = 'automatic:' . (int) $quantity_order->get_id();
	$checks['initial_quantity'] = 5 === count( $quantity_rows );

	$quantity_item = $quantity_order->get_item( $quantity_item_id );
	if ( ! $quantity_item instanceof WC_Order_Item_Product ) {
		throw new RuntimeException( 'The quantity item is unavailable.' );
	}
	$quantity_item->set_quantity( 6 );
	$quantity_item->save();
	$licensing->quantity_saved( (int) $quantity_order->get_id() );
	$checks['increase_requires_confirmation'] = 5 === count( $licenses->for_order_item( $quantity_item_id ) ) && 1 === $licensing->preview( (int) $quantity_order->get_id() )['missing'];
	$backfill_operation                       = 'f13-backfill-' . bin2hex( random_bytes( 12 ) );
	$current_stage                            = 'explicit_backfill_first_call';
	$backfill                                 = $licensing->allocate_missing( (int) $quantity_order->get_id(), true, 'administrator', $operator_id, $backfill_operation );
	$owned_request_ids[]                      = $backfill_operation;
	$quantity_rows                            = $licenses->for_order_item( $quantity_item_id );
	$owned_license_ids                        = array_values( array_unique( array_merge( $owned_license_ids, array_map( static fn( array $row ): int => (int) $row['id'], $quantity_rows ) ) ) );
	$current_stage                            = 'explicit_backfill_replay';
	$backfill_replay                          = $licensing->allocate_missing( (int) $quantity_order->get_id(), true, 'administrator', $operator_id, $backfill_operation );
	$checks['explicit_backfill']              = 1 === $backfill['allocated'] && 6 === count( $quantity_rows ) && 0 === $licensing->preview( (int) $quantity_order->get_id() )['missing'] && 0 === $backfill_replay['allocated'];
	$current_stage                            = 'quantity_decrease_and_refunds';

	$quantity_item->set_quantity( 4 );
	$quantity_item->save();
	$licensing->quantity_saved( (int) $quantity_order->get_id() );
	$checks['decrease_retains'] = 6 === count( $licenses->for_order_item( $quantity_item_id ) );
	$licensing->before_delete_order_item( $quantity_item_id );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact acceptance event count.
	$checks['delete_retains_history'] = 6 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_events WHERE license_id IN (" . implode( ',', array_fill( 0, count( $quantity_rows ), '%d' ) ) . ') AND event_type=%s', array_merge( array_map( static fn( array $row ): int => (int) $row['id'], $quantity_rows ), array( AuditEventCatalog::ORDER_ITEM_DELETED_AFTER_DELIVERY ) ) ) );

	$refund_one = wc_create_refund(
		array(
			'amount'         => 0,
			'reason'         => 'F13 partial refund one',
			'order_id'       => (int) $quantity_order->get_id(),
			'line_items'     => array(
				$quantity_item_id => array(
					'qty'          => 2,
					'refund_total' => 0,
					'refund_tax'   => array(),
				),
			),
			'restock_items'  => false,
			'refund_payment' => false,
		)
	);
	if ( ! $refund_one instanceof WC_Order_Refund ) {
		throw new RuntimeException( 'The first partial refund could not be created.' );
	}
	$refunds[]           = $refund_one;
	$owned_request_ids[] = 'refund:' . (int) $refund_one->get_id();
	$refund_one_replay   = $policies->refund( (int) $quantity_order->get_id(), (int) $refund_one->get_id() );
	$quantity_rows       = $licenses->for_order_item( $quantity_item_id );
	$status_by_slot      = array();
	foreach ( $quantity_rows as $row ) {
		$status_by_slot[ (int) $row['quantity_slot'] ] = (string) $row['lifecycle_status'];
	}
	$checks['partial_refund_high_slots'] = 'suspended' === ( $status_by_slot[5] ?? '' ) && 'suspended' === ( $status_by_slot[6] ?? '' ) && 2 === $refund_one_replay['replayed'];

	$refund_two = wc_create_refund(
		array(
			'amount'         => 0,
			'reason'         => 'F13 partial refund two',
			'order_id'       => (int) $quantity_order->get_id(),
			'line_items'     => array(
				$quantity_item_id => array(
					'qty'          => 1,
					'refund_total' => 0,
					'refund_tax'   => array(),
				),
			),
			'restock_items'  => false,
			'refund_payment' => false,
		)
	);
	if ( ! $refund_two instanceof WC_Order_Refund ) {
		throw new RuntimeException( 'The second partial refund could not be created.' );
	}
	$refunds[]                           = $refund_two;
	$owned_request_ids[]                 = 'refund:' . (int) $refund_two->get_id();
	$refund_two_replay                   = $policies->refund( (int) $quantity_order->get_id(), (int) $refund_two->get_id() );
	$slot_four                           = $licenses->by_order_slot( $quantity_item_id, 4 );
	$checks['partial_refund_no_overlap'] = is_array( $slot_four ) && 'suspended' === $slot_four['lifecycle_status'] && 1 === $refund_two_replay['replayed'];
	$quantity_item->set_quantity( 7 );
	$quantity_item->save();
	$increase_blocked = false;
	try {
		$licensing->allocate_missing( (int) $quantity_order->get_id(), true, 'administrator', $operator_id, 'f13-refunded-increase' );
	} catch ( Throwable $error ) {
		$increase_blocked = true;
	}
	$checks['refunded_increase_blocked'] = $increase_blocked && 6 === count( $licenses->for_order_item( $quantity_item_id ) );

	$no_item_refund = new WC_Order_Refund();
	$no_item_refund->set_parent_id( (int) $quantity_order->get_id() );
	$no_item_refund->set_reason( 'F13 unmapped refund' );
	$no_item_refund->set_amount( 0 );
	$no_item_refund->save();
	$refunds[]                 = $no_item_refund;
	$owned_request_ids[]       = 'refund:' . (int) $no_item_refund->get_id();
	$unmapped                  = $policies->refund( (int) $quantity_order->get_id(), (int) $no_item_refund->get_id() );
	$checks['unmapped_refund'] = 1 === $unmapped['unmapped'];

	$assigned_rows         = array_values( array_filter( $licenses->for_order( (int) $quantity_order->get_id() ), static fn( array $row ): bool => 'assigned' === $row['lifecycle_status'] ) );
	$suspended_rows        = array_values( array_filter( $licenses->for_order( (int) $quantity_order->get_id() ), static fn( array $row ): bool => 'suspended' === $row['lifecycle_status'] ) );
	$mail_calls            = 0;
	$mail_contract         = true;
	$expected_mail_include = array_map( static fn( array $row ): string => $licenses->decrypt_key( $row ), $assigned_rows );
	$expected_mail_exclude = array_map( static fn( array $row ): string => $licenses->decrypt_key( $row ), $suspended_rows );
	$resend_one            = 'f13-resend-one-' . bin2hex( random_bytes( 10 ) );
	$resend_fail           = 'f13-resend-retry-' . bin2hex( random_bytes( 10 ) );
	$resend_operations     = array( $resend_one, $resend_fail );
	$first_resend          = $licensing->resend( (int) $quantity_order->get_id(), $resend_one, 'administrator', $operator_id );
	$replayed_resend       = $licensing->resend( (int) $quantity_order->get_id(), $resend_one, 'administrator', $operator_id );
	$mail_mode             = 'failure';
	$failure_released      = false;
	try {
		$licensing->resend( (int) $quantity_order->get_id(), $resend_fail, 'administrator', $operator_id );
	} catch ( Throwable $error ) {
		$failure_released = true;
	}
	$mail_mode                               = 'success';
	$recovered_resend                        = $licensing->resend( (int) $quantity_order->get_id(), $resend_fail, 'administrator', $operator_id );
	$checks['resend_first_count']            = count( $assigned_rows ) === $first_resend['sent'];
	$checks['resend_replayed']               = $replayed_resend['replayed'];
	$checks['resend_failure_claim_released'] = $failure_released;
	$checks['resend_recovered_count']        = count( $assigned_rows ) === $recovered_resend['sent'];
	$checks['resend_mail_calls']             = 3 === $mail_calls;
	$checks['resend_mail_contract']          = $mail_contract;
	$checks['resend_replay_recovery']        = $checks['resend_first_count'] && $checks['resend_replayed'] && $checks['resend_failure_claim_released'] && $checks['resend_recovered_count'] && $checks['resend_mail_calls'] && $checks['resend_mail_contract'];

	$current_stage  = 'refund_and_cancellation_policies';
	$policy_product = dreamax_lm_f13_product( 'DLM F13 temporary policy product', false );
	$products[]     = $policy_product;
	$policy_public  = (string) $policy_product->get_meta( '_dreamax_lm_product_public_id', true );

	$refund_fixture                = dreamax_lm_f13_order( $policy_product, 1, $owner_id, true );
	$refund_order                  = $refund_fixture['order'];
	$orders[]                      = $refund_order;
	$refund_item_id                = $refund_fixture['item_id'];
	$refund_policy_rows            = array();
	$current_stage                 = 'refund_policy_retain_fixture';
	$refund_policy_rows['retain']  = dreamax_lm_f13_assigned_license( (int) $refund_order->get_id(), $refund_item_id, 1, $owner_id, $policy_public, 'retain', 'retain', 'generated', $owned_license_ids );
	$current_stage                 = 'refund_policy_suspend_fixture';
	$refund_policy_rows['suspend'] = dreamax_lm_f13_assigned_license( (int) $refund_order->get_id(), $refund_item_id, 2, $owner_id, $policy_public, 'suspend', 'retain', 'generated', $owned_license_ids );
	$current_stage                 = 'refund_policy_revoke_fixture';
	$refund_policy_rows['revoke']  = dreamax_lm_f13_assigned_license( (int) $refund_order->get_id(), $refund_item_id, 3, $owner_id, $policy_public, 'revoke', 'retain', 'generated', $owned_license_ids );
	$current_stage                 = 'refund_policy_release_fixture';
	$refund_policy_rows['release'] = dreamax_lm_f13_unused_pool_license( (int) $refund_order->get_id(), $refund_item_id, 4, $owner_id, $policy_public, 'release_unused', 'retain', $operator_id, $owned_license_ids );
	$current_stage                 = 'refund_policy_blocked_fixture';
	$refund_policy_rows['blocked'] = dreamax_lm_f13_assigned_license( (int) $refund_order->get_id(), $refund_item_id, 5, $owner_id, $policy_public, 'release_unused', 'retain', 'generated', $owned_license_ids );
	$current_stage                 = 'refund_policy_application';
	$full_refund                   = wc_create_refund(
		array(
			'amount'         => (float) $refund_order->get_total(),
			'reason'         => 'F13 full refund policy matrix',
			'order_id'       => (int) $refund_order->get_id(),
			'refund_payment' => false,
			'restock_items'  => false,
		)
	);
	if ( is_wp_error( $full_refund ) || ! $full_refund instanceof WC_Order_Refund ) {
		throw new RuntimeException( 'The standard full refund fixture could not be created.' );
	}
	$refunds[]           = $full_refund;
	$owned_request_ids[] = 'refund:' . (int) $full_refund->get_id();
	$diagnostic_counts['refund_attached_before_first'] = count( ( new LicenseRepository() )->for_order( (int) $refund_order->get_id() ) );
	$full_result                                       = $policies->refund( (int) $refund_order->get_id(), (int) $full_refund->get_id() );
	$full_replay                                       = $policies->refund( (int) $refund_order->get_id(), (int) $full_refund->get_id() );
	$diagnostic_counts['refund_first_affected']        = (int) $full_result['affected'];
	$diagnostic_counts['refund_first_replayed']        = (int) $full_result['replayed'];
	$diagnostic_counts['refund_attached_replayed']     = (int) $full_replay['replayed'];
	$diagnostic_counts['refund_attached_after_first']  = count( ( new LicenseRepository() )->for_order( (int) $refund_order->get_id() ) );
	$released_row                                      = ( new LicenseRepository() )->by_public_id( (string) $refund_policy_rows['release']['public_id'] );
	$refund_policy_event_counts                        = array();
	foreach ( $refund_policy_rows as $refund_policy_name => $refund_policy_row ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact per-fixture policy audit proof.
		$event_count                  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_events WHERE license_id=%d AND event_type=%s", (int) $refund_policy_row['id'], AuditEventCatalog::ORDER_REFUND_POLICY_APPLIED ) );
		$refund_policy_event_counts[] = $event_count;
		$diagnostic_counts['refund_policy_events'][ $refund_policy_name ] = $event_count;
	}
	$refund_release_events                           = $refund_policy_event_counts[3];
	$checks['refund_policy_automatic_release']       = 4 === $diagnostic_counts['refund_attached_before_first'] && 1 === $refund_release_events;
	$checks['refund_policy_first_explicit_replayed'] = 0 === $full_result['affected'] && 4 === $full_result['replayed'];
	$checks['refund_policy_attached_replayed']       = 0 === $full_replay['affected'] && 4 === $full_replay['replayed'];
	$checks['refund_policy_events_once']             = 5 === count( array_filter( $refund_policy_event_counts, static fn ( int $count ): bool => 1 === $count ) );
	$checks['refund_policy_retain']                  = 'assigned' === dreamax_lm_f13_status( (int) $refund_policy_rows['retain']['id'] );
	$checks['refund_policy_suspend']                 = 'suspended' === dreamax_lm_f13_status( (int) $refund_policy_rows['suspend']['id'] );
	$checks['refund_policy_revoke']                  = 'revoked' === dreamax_lm_f13_status( (int) $refund_policy_rows['revoke']['id'] );
	$checks['refund_policy_release']                 = is_array( $released_row ) && 'available' === $released_row['lifecycle_status'] && null === $released_row['order_id'] && 1 === $refund_release_events;
	$checks['refund_policy_blocked']                 = 'suspended' === dreamax_lm_f13_status( (int) $refund_policy_rows['blocked']['id'] );
	$checks['all_refund_policies']                   = $checks['refund_policy_automatic_release'] && $checks['refund_policy_first_explicit_replayed'] && $checks['refund_policy_attached_replayed'] && $checks['refund_policy_events_once'] && $checks['refund_policy_retain'] && $checks['refund_policy_suspend'] && $checks['refund_policy_revoke'] && $checks['refund_policy_release'] && $checks['refund_policy_blocked'];

	$cancel_fixture         = dreamax_lm_f13_order( $policy_product, 1, $owner_id, true );
	$cancel_order           = $cancel_fixture['order'];
	$orders[]               = $cancel_order;
	$cancel_item_id         = $cancel_fixture['item_id'];
	$cancel_rows            = array();
	$current_stage          = 'cancellation_policy_retain_fixture';
	$cancel_rows['retain']  = dreamax_lm_f13_assigned_license( (int) $cancel_order->get_id(), $cancel_item_id, 1, $owner_id, $policy_public, 'retain', 'retain', 'generated', $owned_license_ids );
	$current_stage          = 'cancellation_policy_suspend_fixture';
	$cancel_rows['suspend'] = dreamax_lm_f13_assigned_license( (int) $cancel_order->get_id(), $cancel_item_id, 2, $owner_id, $policy_public, 'retain', 'suspend', 'generated', $owned_license_ids );
	$current_stage          = 'cancellation_policy_revoke_fixture';
	$cancel_rows['revoke']  = dreamax_lm_f13_assigned_license( (int) $cancel_order->get_id(), $cancel_item_id, 3, $owner_id, $policy_public, 'retain', 'revoke', 'generated', $owned_license_ids );
	$current_stage          = 'cancellation_policy_release_fixture';
	$cancel_rows['release'] = dreamax_lm_f13_unused_pool_license( (int) $cancel_order->get_id(), $cancel_item_id, 4, $owner_id, $policy_public, 'retain', 'release_unused', $operator_id, $owned_license_ids );
	$current_stage          = 'cancellation_policy_blocked_fixture';
	$cancel_rows['blocked'] = dreamax_lm_f13_assigned_license( (int) $cancel_order->get_id(), $cancel_item_id, 5, $owner_id, $policy_public, 'retain', 'release_unused', 'generated', $owned_license_ids );
	$current_stage          = 'cancellation_policy_first_application';
	$cancel_result          = $policies->cancellation( (int) $cancel_order->get_id() );
	$current_stage          = 'cancellation_policy_replay';
	$cancel_replay          = $policies->cancellation( (int) $cancel_order->get_id() );
	$diagnostic_counts['cancellation_first_affected']    = (int) $cancel_result['affected'];
	$diagnostic_counts['cancellation_attached_replayed'] = (int) $cancel_replay['replayed'];
	$cancel_released                                     = ( new LicenseRepository() )->by_public_id( (string) $cancel_rows['release']['public_id'] );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact released-policy audit proof.
	$cancel_release_events                           = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_events WHERE license_id=%d AND event_type=%s", (int) $cancel_rows['release']['id'], AuditEventCatalog::ORDER_CANCELLATION_POLICY_APPLIED ) );
	$checks['cancellation_policy_first_affected']    = 5 === $cancel_result['affected'];
	$checks['cancellation_policy_attached_replayed'] = 4 === $cancel_replay['replayed'];
	$checks['cancellation_policy_retain']            = 'assigned' === dreamax_lm_f13_status( (int) $cancel_rows['retain']['id'] );
	$checks['cancellation_policy_suspend']           = 'suspended' === dreamax_lm_f13_status( (int) $cancel_rows['suspend']['id'] );
	$checks['cancellation_policy_revoke']            = 'revoked' === dreamax_lm_f13_status( (int) $cancel_rows['revoke']['id'] );
	$checks['cancellation_policy_release']           = is_array( $cancel_released ) && 'available' === $cancel_released['lifecycle_status'] && null === $cancel_released['order_id'] && 1 === $cancel_release_events;
	$checks['cancellation_policy_blocked']           = 'suspended' === dreamax_lm_f13_status( (int) $cancel_rows['blocked']['id'] );
	$checks['all_cancellation_policies']             = $checks['cancellation_policy_first_affected'] && $checks['cancellation_policy_attached_replayed'] && $checks['cancellation_policy_retain'] && $checks['cancellation_policy_suspend'] && $checks['cancellation_policy_revoke'] && $checks['cancellation_policy_release'] && $checks['cancellation_policy_blocked'];

	$current_stage  = 'before_delivery_quantity_edit';
	$before_product = dreamax_lm_f13_product( 'DLM F13 temporary before-delivery product', true );
	$products[]     = $before_product;
	$before_fixture = dreamax_lm_f13_order( $before_product, 2, $owner_id, false );
	$before_order   = $before_fixture['order'];
	$orders[]       = $before_order;
	$before_item    = $before_order->get_item( $before_fixture['item_id'] );
	if ( ! $before_item instanceof WC_Order_Item_Product ) {
		throw new RuntimeException( 'The before-delivery item is unavailable.' );
	}
	$before_item->set_quantity( 3 );
	$before_item->save();
	$before_order->set_date_paid( time() );
	$before_order->set_status( 'processing' );
	$before_order->save();
	$before_rows                    = $licenses->for_order_item( $before_fixture['item_id'] );
	$owned_license_ids              = array_values( array_unique( array_merge( $owned_license_ids, array_map( static fn( array $row ): int => (int) $row['id'], $before_rows ) ) ) );
	$owned_request_ids[]            = 'automatic:' . (int) $before_order->get_id();
	$checks['before_delivery_edit'] = 3 === count( $before_rows );

	$current_stage       = 'pool_exhaustion';
	$pool_product        = dreamax_lm_f13_product( 'DLM F13 temporary empty pool product', true, 'pool' );
	$products[]          = $pool_product;
	$pool_fixture        = dreamax_lm_f13_order( $pool_product, 1, $owner_id, true );
	$pool_order          = $pool_fixture['order'];
	$orders[]            = $pool_order;
	$owned_request_ids[] = 'automatic:' . (int) $pool_order->get_id();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact pool-exhaustion evidence.
	$pool_failures             = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_events WHERE event_type=%s AND request_id=%s", AuditEventCatalog::ORDER_ALLOCATION_FAILED, 'automatic:' . (int) $pool_order->get_id() ) );
	$checks['pool_exhaustion'] = 0 === count( $licenses->for_order( (int) $pool_order->get_id() ) ) && 1 === $licensing->preview( (int) $pool_order->get_id() )['missing'] && 1 === $pool_failures;

	$current_stage     = 'lifecycle_edits';
	$lifecycle_fixture = dreamax_lm_f13_order( $policy_product, 1, $owner_id, false );
	$lifecycle_order   = $lifecycle_fixture['order'];
	$orders[]          = $lifecycle_order;
	$lifecycle_row     = dreamax_lm_f13_assigned_license( (int) $lifecycle_order->get_id(), $lifecycle_fixture['item_id'], 1, $owner_id, $policy_public, 'retain', 'retain', 'generated', $owned_license_ids );
	$extension_id      = 'f13-extend-' . bin2hex( random_bytes( 10 ) );
	$extension         = $lifecycle->extend( (string) $lifecycle_row['public_id'], 30, 'F13 acceptance extension', $extension_id, 'administrator', $operator_id );
	$extension_replay  = $lifecycle->extend( (string) $lifecycle_row['public_id'], 30, 'F13 acceptance extension', $extension_id, 'administrator', $operator_id );
	$now               = gmdate( 'Y-m-d H:i:s' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Creates an exact owned activation fixture.
	$wpdb->insert(
		$wpdb->prefix . 'dreamax_lm_activations',
		array(
			'public_id'            => PublicId::generate( 'act' ),
			'license_id'           => (int) $lifecycle_row['id'],
			'instance_fingerprint' => random_bytes( 32 ),
			'instance_label'       => 'F13 owned activation',
			'status'               => 'active',
			'first_activated_at'   => $now,
			'activated_at'         => $now,
			'updated_at'           => $now,
			'metadata'             => '{}',
		)
	);
	$reset_id       = 'f13-reset-' . bin2hex( random_bytes( 10 ) );
	$reset          = $lifecycle->reset_activations( (string) $lifecycle_row['public_id'], 'F13 acceptance reset', $reset_id, 'administrator', $operator_id );
	$reset_replay   = $lifecycle->reset_activations( (string) $lifecycle_row['public_id'], 'F13 acceptance reset', $reset_id, 'administrator', $operator_id );
	$target_fixture = dreamax_lm_f13_order( $policy_product, 1, $target_id, false );
	$target_order   = $target_fixture['order'];
	$orders[]       = $target_order;
	$now            = gmdate( 'Y-m-d H:i:s' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Creates a second exact owned activation for reassignment-reset verification.
	$wpdb->insert(
		$wpdb->prefix . 'dreamax_lm_activations',
		array(
			'public_id'            => PublicId::generate( 'act' ),
			'license_id'           => (int) $lifecycle_row['id'],
			'instance_fingerprint' => random_bytes( 32 ),
			'instance_label'       => 'F13 owned reassignment activation',
			'status'               => 'active',
			'first_activated_at'   => $now,
			'activated_at'         => $now,
			'updated_at'           => $now,
			'metadata'             => '{}',
		)
	);
	$reassign_id     = 'f13-reassign-' . bin2hex( random_bytes( 10 ) );
	$reassigned      = $lifecycle->reassign(
		(string) $lifecycle_row['public_id'],
		array(
			'customer_id'       => $target_id,
			'order_id'          => (int) $target_order->get_id(),
			'product_public_id' => $policy_public,
		),
		true,
		false,
		'F13 acceptance reassignment',
		$reassign_id,
		'administrator',
		$operator_id
	);
	$reassign_replay = $lifecycle->reassign(
		(string) $lifecycle_row['public_id'],
		array(
			'customer_id'       => $target_id,
			'order_id'          => (int) $target_order->get_id(),
			'product_public_id' => $policy_public,
		),
		true,
		false,
		'F13 acceptance reassignment',
		$reassign_id,
		'administrator',
		$operator_id
	);
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact activation-state evidence.
	$active_after              = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_activations WHERE license_id=%d AND status='active'", (int) $lifecycle_row['id'] ) );
	$current_lifecycle         = ( new LicenseRepository() )->by_public_id( (string) $lifecycle_row['public_id'] );
	$checks['lifecycle_edits'] = ! $extension['replayed'] && $extension_replay['replayed'] && 1 === $reset['reset_count'] && $reset_replay['replayed'] && 1 === $reassigned['reset_count'] && $reassign_replay['replayed'] && 0 === $active_after && is_array( $current_lifecycle ) && $target_id === (int) $current_lifecycle['customer_id'] && (int) $target_order->get_id() === (int) $current_lifecycle['order_id'];

	if ( in_array( false, $checks, true ) ) {
		$current_stage = 'contract_assertions';
		throw new RuntimeException( 'One or more F13 acceptance contracts failed.' );
	}
} catch ( Throwable $error ) {
	$failure       = $error;
	$failure_stage = $current_stage;
	$failure_code  = match ( $error->getMessage() ) {
		'F13 unused pool import step failed.' => 'unused_pool_import',
		'The unused pool fixture could not be assigned.' => 'unused_pool_assignment_update',
		default => 'unclassified',
	};
} finally {
	$expected_mail_include = array();
	$expected_mail_exclude = array();
	remove_all_filters( 'pre_wp_mail', PHP_INT_MAX );

	if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$cleanup_failure = new RuntimeException( 'Cleanup transaction start failed.' );
	} else {
		try {
			foreach ( $orders as $owned_order ) {
				if ( ! $owned_order instanceof WC_Order ) {
					continue;
				}
				$cleanup_order_ids[] = (int) $owned_order->get_id();
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Finds every license attached to an exact verifier-owned order before its deletion.
				$owned_license_ids = array_merge( $owned_license_ids, array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}dreamax_lm_licenses WHERE order_id=%d", (int) $owned_order->get_id() ) ) ) );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Finds exact detached pool fixtures released by this verifier.
			$owned_license_ids = array_merge( $owned_license_ids, array_map( 'intval', $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}dreamax_lm_licenses WHERE metadata LIKE '%\"fixture\":\"f13\"%'" ) ) );
			$owned_license_ids = array_values( array_unique( $owned_license_ids ) );
			foreach ( array_values( array_unique( $owned_license_ids ) ) as $license_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact owned cleanup.
				$wpdb->delete( $wpdb->prefix . 'dreamax_lm_activations', array( 'license_id' => $license_id ), array( '%d' ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact owned cleanup.
				$wpdb->delete( $wpdb->prefix . 'dreamax_lm_events', array( 'license_id' => $license_id ), array( '%d' ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact owned cleanup.
				$wpdb->delete( $wpdb->prefix . 'dreamax_lm_licenses', array( 'id' => $license_id ), array( '%d' ) );
			}
			foreach ( array_values( array_unique( $owned_request_ids ) ) as $request_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact owned cleanup.
				$wpdb->delete( $wpdb->prefix . 'dreamax_lm_events', array( 'request_id' => $request_id ), array( '%s' ) );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact verifier-owned request prefix cleanup.
			$wpdb->query( "DELETE FROM {$wpdb->prefix}dreamax_lm_events WHERE request_id LIKE 'f13-%'" );
			if ( isset( $quantity_order ) && $quantity_order instanceof WC_Order ) {
				foreach ( $resend_operations as $operation_id ) {
					$scope = hash( 'sha256', 'woocommerce-order-operation|v1|order_resend|' . (int) $quantity_order->get_id() . '|' . $operation_id );
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact owned operation cleanup.
					$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}dreamax_lm_idempotency WHERE scope_hash=UNHEX(%s)", $scope ) );
				}
			}
			foreach ( array_reverse( $refunds ) as $refund ) {
				if ( $refund instanceof WC_Order_Refund ) {
					$refund->delete( true );
				}
			}
			foreach ( array_reverse( $orders ) as $owned_order ) {
				if ( $owned_order instanceof WC_Order ) {
					$owned_order->delete( true );
				}
			}
			foreach ( array_reverse( $products ) as $product ) {
				if ( $product instanceof WC_Product ) {
					$product->delete( true );
				}
			}
			foreach ( $users as $user_id ) {
				wp_delete_user( $user_id );
			}
			foreach ( array_values( array_unique( $owned_license_ids ) ) as $license_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Removes events emitted by owned-object deletion hooks.
				$wpdb->delete( $wpdb->prefix . 'dreamax_lm_events', array( 'license_id' => $license_id ), array( '%d' ) );
			}
			foreach ( array_values( array_unique( $cleanup_order_ids ) ) as $cleanup_order_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Removes only audit rows whose JSON metadata names an exact verifier-owned order.
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}dreamax_lm_events WHERE CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata,'$.order_id')) AS UNSIGNED)=%d", $cleanup_order_id ) );
			}
			$cleanup_committed = false !== $wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( ! $cleanup_committed ) {
				throw new RuntimeException( 'Owned cleanup commit failed.' );
			}
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$cleanup_failure = $error;
		}
	}
	if ( function_exists( 'wp_cache_flush_runtime' ) ) {
		wp_cache_flush_runtime();
	}
}

$after = $snapshot();
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact post-cleanup proof.
$owned_rows_remaining  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product' AND post_title LIKE 'DLM F13 temporary %'" );
$owned_rows_remaining += (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'dlm_f13_%'" );
$owned_rows_remaining += count(
	wc_get_orders(
		array(
			'limit'         => -1,
			'billing_email' => 'dlm-f13@example.invalid',
			'return'        => 'ids',
		)
	)
);
$owned_rows_remaining += (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_events WHERE request_id LIKE 'f13-%'" );
foreach ( array_values( array_unique( $owned_license_ids ) ) as $license_id ) {
	$owned_rows_remaining += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_licenses WHERE id=%d", $license_id ) );
	$owned_rows_remaining += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_activations WHERE license_id=%d", $license_id ) );
	$owned_rows_remaining += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_events WHERE license_id=%d", $license_id ) );
}
foreach ( array_values( array_unique( $cleanup_order_ids ) ) as $cleanup_order_id ) {
	$owned_rows_remaining += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_events WHERE CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata,'$.order_id')) AS UNSIGNED)=%d", $cleanup_order_id ) );
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

if ( $cleanup_failure instanceof Throwable || ! $cleanup_committed || $before !== $after || 0 !== $owned_rows_remaining ) {
	$aggregate_deltas = array();
	$failed_contracts = array();
	foreach ( $before as $aggregate => $count ) {
		$aggregate_deltas[ $aggregate ] = $after[ $aggregate ] - $count;
	}
	foreach ( $checks as $contract => $passed ) {
		if ( ! $passed ) {
			$failed_contracts[] = $contract;
		}
	}
	echo wp_json_encode(
		array(
			'classification'               => 'sanitized_f13_cleanup_proof_failure',
			'cleanup_error'                => $cleanup_failure instanceof Throwable,
			'cleanup_committed'            => $cleanup_committed,
			'aggregate_deltas'             => $aggregate_deltas,
			'failed_contracts'             => $failed_contracts,
			'owned_fixture_rows_remaining' => $owned_rows_remaining,
			'acceptance_contract_failed'   => $failure instanceof Throwable,
			'sensitive_output'             => false,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . PHP_EOL;
	dreamax_lm_f13_fail( 'The owned F13 fixtures were not completely removed.' );
}
if ( $failure instanceof Throwable ) {
	$failed_contracts = array();
	foreach ( $checks as $contract => $passed ) {
		if ( ! $passed ) {
			$failed_contracts[] = $contract;
		}
	}
	echo wp_json_encode(
		array(
			'classification'       => 'sanitized_f13_acceptance_failure',
			'failure_stage'        => $failure_stage,
			'failure_code'         => $failure_code,
			'failed_contracts'     => $failed_contracts,
			'diagnostic_counts'    => $diagnostic_counts,
			'contracts_recorded'   => count( $checks ),
			'cleanup_committed'    => $cleanup_committed,
			'aggregates_unchanged' => $before === $after,
			'sensitive_output'     => false,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . PHP_EOL;
	dreamax_lm_f13_fail( 'The guarded F13 verification failed.' );
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes only the exact obsolete local recovery backup after full success.
if ( is_file( $recovery_backup_file ) && ! unlink( $recovery_backup_file ) ) {
	dreamax_lm_f13_fail( 'The obsolete recovery backup could not be removed.' );
}

echo wp_json_encode(
	array(
		'classification'                     => 'live_disposable_wordpress_woocommerce_hpos_innodb',
		'contracts_passed'                   => count( $checks ),
		'initial_quantity_allocation'        => $checks['initial_quantity'],
		'post_delivery_increase_confirmed'   => $checks['increase_requires_confirmation'] && $checks['explicit_backfill'],
		'post_delivery_decrease_retained'    => $checks['decrease_retains'],
		'item_delete_history_retained'       => $checks['delete_retains_history'],
		'partial_refund_mapping_replay'      => $checks['partial_refund_high_slots'] && $checks['partial_refund_no_overlap'],
		'refunded_increase_blocked'          => $checks['refunded_increase_blocked'],
		'unmapped_refund_audited'            => $checks['unmapped_refund'],
		'all_refund_policies'                => $checks['all_refund_policies'],
		'all_cancellation_policies'          => $checks['all_cancellation_policies'],
		'eligible_and_blocked_release'       => $checks['all_refund_policies'] && $checks['all_cancellation_policies'],
		'before_delivery_edit'               => $checks['before_delivery_edit'],
		'pool_exhaustion_recoverable'        => $checks['pool_exhaustion'],
		'lifecycle_extend_reset_reassign'    => $checks['lifecycle_edits'],
		'resend_replay_and_failure_recovery' => $checks['resend_replay_recovery'],
		'cleanup_committed'                  => $cleanup_committed,
		'aggregates_unchanged'               => $before === $after,
		'owned_fixture_rows_remaining'       => $owned_rows_remaining,
		'outbound_email_sent'                => false,
		'sensitive_output'                   => false,
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
