<?php
/**
 * Verifies variation-specific licensing in a disposable WooCommerce site.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

use Dreamax\LicenseManager\Events\AuditEventCatalog;
use Dreamax\LicenseManager\Integrations\WooCommerce\OrderLicensing;
use Dreamax\LicenseManager\Integrations\WooCommerce\ProductPolicy;
use Dreamax\LicenseManager\Licenses\LicenseRepository;
use Dreamax\LicenseManager\ReleaseTools\DisposableEnvironmentGuard;
use Dreamax\LicenseManager\Support\PublicId;

require_once __DIR__ . '/lib/release-tools.php';

/**
 * Stops with a sanitized error that does not disclose fixture values.
 *
 * @param string $message Sanitized failure message.
 */
function dreamax_lm_f04_fail( string $message ): never {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI verifier must report a sanitized failure to STDERR.
	fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
	exit( 1 );
}

$options = getopt( '', array( 'environment-marker:', 'wp-root:' ) );
$marker  = (string) ( $options['environment-marker'] ?? '' );
$wp_root = realpath( (string) ( $options['wp-root'] ?? '' ) );

if ( false === $wp_root || ! is_file( $wp_root . '/wp-load.php' ) ) {
	dreamax_lm_f04_fail( 'A valid WordPress root is required.' );
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
	dreamax_lm_f04_fail( 'The database identity is unavailable.' );
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
	|| ! class_exists( WC_Product_Variable::class )
	|| ! class_exists( OrderLicensing::class ) ) {
	dreamax_lm_f04_fail( 'The required WordPress plugins are not active.' );
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
	$wpdb->prefix . 'dreamax_lm_events',
);

foreach ( $tables as $table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction safety requires authoritative engine checks.
	$engine = $wpdb->get_var(
		$wpdb->prepare(
			'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
			(string) DB_NAME,
			$table
		)
	);
	if ( 'InnoDB' !== $engine ) {
		dreamax_lm_f04_fail( 'A required variable-order table is not safely transactional.' );
	}
}

$plugin_snapshot = static function () use ( $wpdb ): array {
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact cleanup evidence requires fresh aggregate reads.
	return array(
		'licenses' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_licenses" ),
		'events'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_events" ),
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
};

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A stale owned marker must block a new mutation before it starts.
$stale_products = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_title = %s",
		'DLM F04 temporary variable product'
	)
);
$stale_orders   = wc_get_orders(
	array(
		'limit'         => 1,
		'billing_email' => 'dlm-f04@example.invalid',
		'return'        => 'ids',
	)
);
if ( 0 !== $stale_products || array() !== $stale_orders ) {
	dreamax_lm_f04_fail( 'A stale owned F04 fixture must be cleaned before running.' );
}

$plugin_before       = $plugin_snapshot();
$failure             = null;
$cleanup_failure     = null;
$cleanup_committed   = false;
$parent_id           = 0;
$variation_ids       = array();
$order_id            = 0;
$item_ids            = array();
$license_ids         = array();
$fixture_order       = null;
$parent              = null;
$variation_a         = null;
$variation_b         = null;
$variation_a_matches = false;
$variation_b_matches = false;
$event_contract      = false;
$preview_contract    = false;

try {
	add_filter( 'woocommerce_email_enabled_new_order', '__return_false', PHP_INT_MAX );
	add_filter( 'woocommerce_email_enabled_customer_processing_order', '__return_false', PHP_INT_MAX );
	add_filter( 'woocommerce_email_enabled_customer_completed_order', '__return_false', PHP_INT_MAX );

	$parent = new WC_Product_Variable();
	$parent->set_name( 'DLM F04 temporary variable product' );
	$parent->set_status( 'publish' );
	$parent_id = (int) $parent->save();
	$parent->update_meta_data( '_dreamax_lm_enabled', 'yes' );
	$parent->update_meta_data( '_dreamax_lm_product_public_id', PublicId::generate( 'prd' ) );
	$parent->update_meta_data( '_dreamax_lm_source', 'generated' );
	$parent->update_meta_data( '_dreamax_lm_issuance', 'per_quantity' );
	$parent->save_meta_data();

	$variation_a = new WC_Product_Variation();
	$variation_a->set_parent_id( $parent_id );
	$variation_a->set_status( 'publish' );
	$variation_a->set_virtual( true );
	$variation_a->set_regular_price( '10' );
	$variation_a_id = (int) $variation_a->save();
	$variation_a->update_meta_data( '_dreamax_lm_enabled', 'yes' );
	$variation_a->update_meta_data( '_dreamax_lm_product_public_id', PublicId::generate( 'prd' ) );
	$variation_a->update_meta_data( '_dreamax_lm_source', 'generated' );
	$variation_a->update_meta_data( '_dreamax_lm_issuance', 'per_quantity' );
	$variation_a->update_meta_data( '_dreamax_lm_activation_limit', '1' );
	$variation_a->update_meta_data( '_dreamax_lm_valid_days', '10' );
	$variation_a->save_meta_data();

	$variation_b = new WC_Product_Variation();
	$variation_b->set_parent_id( $parent_id );
	$variation_b->set_status( 'publish' );
	$variation_b->set_virtual( true );
	$variation_b->set_regular_price( '20' );
	$variation_b_id = (int) $variation_b->save();
	$variation_b->update_meta_data( '_dreamax_lm_enabled', 'yes' );
	$variation_b->update_meta_data( '_dreamax_lm_product_public_id', PublicId::generate( 'prd' ) );
	$variation_b->update_meta_data( '_dreamax_lm_source', 'generated' );
	$variation_b->update_meta_data( '_dreamax_lm_issuance', 'per_item' );
	$variation_b->update_meta_data( '_dreamax_lm_activation_limit', '3' );
	$variation_b->update_meta_data( '_dreamax_lm_valid_days', '20' );
	$variation_b->save_meta_data();
	$variation_ids = array( $variation_a_id, $variation_b_id );

	$policy_a = ( new ProductPolicy() )->resolve( $variation_a );
	$policy_b = ( new ProductPolicy() )->resolve( $variation_b );
	if ( ! $policy_a['enabled']
		|| ! $policy_b['enabled']
		|| '' === $policy_a['public_id']
		|| '' === $policy_b['public_id']
		|| hash_equals( $policy_a['public_id'], $policy_b['public_id'] )
		|| 'per_quantity' !== $policy_a['issuance']
		|| 'per_item' !== $policy_b['issuance']
		|| 1 !== $policy_a['activation_limit']
		|| 3 !== $policy_b['activation_limit']
		|| 10 !== $policy_a['valid_days']
		|| 20 !== $policy_b['valid_days'] ) {
		throw new RuntimeException( 'Variation-specific product policy resolution failed.' );
	}

	$fixture_order = wc_create_order( array( 'status' => 'pending' ) );
	if ( ! $fixture_order instanceof WC_Order ) {
		throw new RuntimeException( 'The temporary variable order could not be created.' );
	}
	$order_id = (int) $fixture_order->get_id();
	$fixture_order->set_billing_email( 'dlm-f04@example.invalid' );
	$fixture_order->set_payment_method( 'bacs' );
	$item_a_id = (int) $fixture_order->add_product( $variation_a, 2 );
	$item_b_id = (int) $fixture_order->add_product( $variation_b, 2 );
	$item_ids  = array( $item_a_id, $item_b_id );
	$fixture_order->calculate_totals();
	$fixture_order->set_date_paid( time() );
	$fixture_order->set_status( 'processing' );
	$fixture_order->save();

	$licenses = new LicenseRepository();
	$rows_a   = $licenses->for_order_item( $item_a_id );
	$rows_b   = $licenses->for_order_item( $item_b_id );
	$slots_a  = array_map( static fn( array $row ): int => (int) $row['quantity_slot'], $rows_a );
	$slots_b  = array_map( static fn( array $row ): int => (int) $row['quantity_slot'], $rows_b );
	sort( $slots_a );
	sort( $slots_b );

	$variation_a_matches = 2 === count( $rows_a )
		&& array( 1, 2 ) === $slots_a
		&& 0 === count(
			array_filter(
				$rows_a,
				static fn( array $row ): bool => (int) $row['product_id'] !== $parent_id
					|| (int) $row['variation_id'] !== $variation_a_id
					|| ! hash_equals( $policy_a['public_id'], (string) $row['product_public_id'] )
					|| 1 !== (int) $row['activation_limit']
			)
		);
	$variation_b_matches = 1 === count( $rows_b )
		&& array( 1 ) === $slots_b
		&& (int) $rows_b[0]['product_id'] === $parent_id
		&& (int) $rows_b[0]['variation_id'] === $variation_b_id
		&& hash_equals( $policy_b['public_id'], (string) $rows_b[0]['product_public_id'] )
		&& 3 === (int) $rows_b[0]['activation_limit'];

	$order_preview    = ( new OrderLicensing() )->preview( $order_id );
	$preview_contract = 3 === $order_preview['license_count']
		&& 0 === $order_preview['missing']
		&& 2 === count( $order_preview['items'] )
		&& true === $order_preview['eligible'];

	$event_contract = true;
	foreach ( array_merge( $rows_a, $rows_b ) as $row ) {
		foreach ( array( AuditEventCatalog::LICENSE_CREATED, AuditEventCatalog::LICENSE_ASSIGNED, AuditEventCatalog::LICENSE_DELIVERED ) as $event_type ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact owned-fixture audit evidence requires fresh reads.
			$count          = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_events WHERE license_id = %d AND event_type = %s",
					(int) $row['id'],
					$event_type
				)
			);
			$event_contract = $event_contract && 1 === $count;
		}
	}

	if ( ! $variation_a_matches || ! $variation_b_matches || ! $preview_contract || ! $event_contract ) {
		throw new RuntimeException( 'The variation allocation contract did not match.' );
	}
} catch ( Throwable $error ) {
	$failure = $error;
} finally {
	if ( $order_id > 0 ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup is scoped to the exact owned order.
		$license_ids = array_map(
			'intval',
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup is scoped to the exact owned order.
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}dreamax_lm_licenses WHERE order_id = %d",
					$order_id
				)
			)
		);
	}

	if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$cleanup_failure = new RuntimeException( 'Cleanup transaction start failed.' );
	} else {
		try {
			if ( $order_id > 0 ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Deletion is scoped to the exact owned automatic-allocation request.
				$wpdb->delete( $wpdb->prefix . 'dreamax_lm_events', array( 'request_id' => 'automatic:' . $order_id ), array( '%s' ) );
			}
			foreach ( $license_ids as $license_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Deletion is scoped to an exact owned license.
				$wpdb->delete( $wpdb->prefix . 'dreamax_lm_events', array( 'license_id' => $license_id ), array( '%d' ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Deletion is scoped to an exact owned license.
				if ( 1 !== $wpdb->delete( $wpdb->prefix . 'dreamax_lm_licenses', array( 'id' => $license_id ), array( '%d' ) ) ) {
					throw new RuntimeException( 'Owned license cleanup failed.' );
				}
			}
			if ( $fixture_order instanceof WC_Order && ! $fixture_order->delete( true ) ) {
				throw new RuntimeException( 'Owned order cleanup failed.' );
			}
			if ( $variation_a instanceof WC_Product_Variation && ! $variation_a->delete( true ) ) {
				throw new RuntimeException( 'Owned variation cleanup failed.' );
			}
			if ( $variation_b instanceof WC_Product_Variation && ! $variation_b->delete( true ) ) {
				throw new RuntimeException( 'Owned variation cleanup failed.' );
			}
			if ( $parent instanceof WC_Product_Variable && ! $parent->delete( true ) ) {
				throw new RuntimeException( 'Owned product cleanup failed.' );
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

	foreach ( array_merge( array( $parent_id ), $variation_ids ) as $fixture_post_id ) {
		if ( $fixture_post_id > 0 ) {
			clean_post_cache( $fixture_post_id );
		}
	}
	if ( function_exists( 'wp_cache_flush_runtime' ) ) {
		wp_cache_flush_runtime();
	}
}

$plugin_after      = $plugin_snapshot();
$fixture_rows_left = 0;
foreach ( array_filter( array_merge( array( $parent_id ), $variation_ids ) ) as $fixture_post_id ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact owned cleanup evidence requires a fresh read.
	$fixture_rows_left += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = %d", $fixture_post_id ) );
}
if ( $order_id > 0 ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact owned order cleanup evidence requires a fresh read.
	$fixture_rows_left += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE id = %d", $order_id ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact owned license cleanup evidence requires a fresh read.
	$fixture_rows_left += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_licenses WHERE order_id = %d", $order_id ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact owned event cleanup evidence requires a fresh read.
	$fixture_rows_left += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_events WHERE request_id = %s", 'automatic:' . $order_id ) );
}
foreach ( $license_ids as $license_id ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact owned event cleanup evidence requires a fresh read.
	$fixture_rows_left += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_events WHERE license_id = %d", $license_id ) );
	if ( $fixture_rows_left > 0 ) {
		break;
	}
}

if ( $cleanup_failure instanceof Throwable || ! $cleanup_committed || 0 !== $fixture_rows_left || $plugin_before !== $plugin_after ) {
	dreamax_lm_f04_fail( 'The owned variable-product fixtures were not completely removed.' );
}
if ( $failure instanceof Throwable ) {
	dreamax_lm_f04_fail( 'The guarded variable-product verification failed.' );
}

echo wp_json_encode(
	array(
		'classification'                          => 'live_disposable_wordpress_woocommerce_innodb',
		'variable_product'                        => true,
		'variations_tested'                       => 2,
		'variation_public_identities_distinct'    => true,
		'variation_policies_explicit'             => true,
		'per_quantity_purchased'                  => 2,
		'per_quantity_assigned'                   => 2,
		'per_item_purchased'                      => 2,
		'per_item_assigned'                       => 1,
		'variation_a_mapping_matched'             => $variation_a_matches,
		'variation_b_mapping_matched'             => $variation_b_matches,
		'created_assigned_delivered_events_exact' => $event_contract,
		'order_preview_missing_slots'             => 0,
		'order_preview_contract_matched'          => $preview_contract,
		'cleanup_transaction_committed'           => $cleanup_committed,
		'plugin_aggregates_unchanged'             => $plugin_before === $plugin_after,
		'owned_fixture_rows_remaining'            => $fixture_rows_left,
		'outbound_email_sent'                     => false,
		'sensitive_output'                        => false,
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
