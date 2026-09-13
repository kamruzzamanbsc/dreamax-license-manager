<?php
/**
 * Verifies that every allocation path rejects an unpaid WooCommerce order.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

use Dreamax\LicenseManager\Integrations\WooCommerce\OrderLicensing;
use Dreamax\LicenseManager\Licenses\LicenseRepository;
use Dreamax\LicenseManager\ReleaseTools\DisposableEnvironmentGuard;
use Dreamax\LicenseManager\Support\PublicId;
use Dreamax\LicenseManager\Support\Settings;

require_once __DIR__ . '/lib/release-tools.php';

/**
 * Stops with a fixed, non-sensitive failure message.
 *
 * @param string $message Sanitized failure message.
 */
function dreamax_lm_unpaid_fail( string $message ): never {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI verifier reports a fixed result to STDERR.
	fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
	exit( 1 );
}

$options = getopt( '', array( 'environment-marker:', 'wp-root:' ) );
$marker  = (string) ( $options['environment-marker'] ?? '' );
$wp_root = realpath( (string) ( $options['wp-root'] ?? '' ) );

if ( false === $wp_root || ! is_file( $wp_root . '/wp-load.php' ) ) {
	dreamax_lm_unpaid_fail( 'A valid WordPress root is required.' );
}
if ( ! defined( 'DISABLE_WP_CRON' ) ) {
	define( 'DISABLE_WP_CRON', true );
}
if ( ! defined( 'WP_USE_THEMES' ) ) {
	define( 'WP_USE_THEMES', false );
}
require_once $wp_root . '/wp-load.php';

DisposableEnvironmentGuard::assertSafe(
	array(
		'marker'   => $marker,
		'database' => defined( 'DB_NAME' ) ? (string) DB_NAME : '',
		'site_url' => home_url( '/' ),
	)
);

if ( ! class_exists( 'WooCommerce' ) ) {
	dreamax_lm_unpaid_fail( 'WooCommerce is not active.' );
}

global $wpdb;

$product           = null;
$fixture_order     = null;
$previous_statuses = get_option( Settings::OPTION_ALLOCATION_STATUSES, null );
$direct_rejected   = false;
$automatic_empty   = false;
$cleanup_complete  = false;
$failure           = '';

try {
	$product = new WC_Product_Simple();
	$product->set_name( 'DLM temporary unpaid guard product' );
	$product->set_status( 'publish' );
	$product->set_virtual( true );
	$product->set_regular_price( '10' );
	$product->save();
	$product->update_meta_data( '_dreamax_lm_enabled', 'yes' );
	$product->update_meta_data( '_dreamax_lm_product_public_id', PublicId::generate( 'prd' ) );
	$product->update_meta_data( '_dreamax_lm_source', 'generated' );
	$product->update_meta_data( '_dreamax_lm_issuance', 'per_quantity' );
	$product->update_meta_data( '_dreamax_lm_activation_limit', '1' );
	$product->save_meta_data();

	$fixture_order = wc_create_order( array( 'status' => 'pending' ) );
	if ( ! $fixture_order instanceof WC_Order ) {
		throw new RuntimeException( 'The temporary order could not be created.' );
	}
	$fixture_order->set_billing_email( 'dlm-unpaid-guard@example.invalid' );
	$fixture_order->set_payment_method( 'bacs' );
	$fixture_order->add_product( $product, 1 );
	$fixture_order->calculate_totals();
	$fixture_order->save();

	update_option( Settings::OPTION_ALLOCATION_STATUSES, array( 'pending' ), false );
	$licensing = new OrderLicensing();
	$licensing->status_changed( (int) $fixture_order->get_id(), 'draft', 'pending', $fixture_order );
	$automatic_empty = array() === ( new LicenseRepository() )->for_order( (int) $fixture_order->get_id() );

	try {
		$licensing->allocate_missing( (int) $fixture_order->get_id(), false, 'woocommerce', null, 'unpaid-guard' );
	} catch ( RuntimeException $error ) {
		$direct_rejected = 'Licenses can be allocated only after payment is confirmed.' === $error->getMessage();
	}
	if ( ! $automatic_empty || ! $direct_rejected ) {
		throw new RuntimeException( 'An unpaid allocation path was not rejected.' );
	}
} catch ( Throwable $error ) {
	$failure = 'The unpaid-order guard did not pass.';
} finally {
	if ( $fixture_order instanceof WC_Order ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Guarded cleanup removes only records owned by the disposable order.
		$license_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE order_id=%d', $wpdb->prefix . 'dreamax_lm_licenses', (int) $fixture_order->get_id() ) );
		foreach ( array_map( 'intval', $license_ids ) as $license_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Guarded cleanup removes only records owned by the disposable license.
			$wpdb->delete( $wpdb->prefix . 'dreamax_lm_activations', array( 'license_id' => $license_id ), array( '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Guarded cleanup removes only records owned by the disposable license.
			$wpdb->delete( $wpdb->prefix . 'dreamax_lm_events', array( 'license_id' => $license_id ), array( '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Guarded cleanup removes only records owned by the disposable license.
			$wpdb->delete( $wpdb->prefix . 'dreamax_lm_licenses', array( 'id' => $license_id ), array( '%d' ) );
		}
		$fixture_order->delete( true );
	}
	if ( $product instanceof WC_Product ) {
		$product->delete( true );
	}
	if ( null === $previous_statuses ) {
		delete_option( Settings::OPTION_ALLOCATION_STATUSES );
	} else {
		update_option( Settings::OPTION_ALLOCATION_STATUSES, $previous_statuses, false );
	}
	$cleanup_complete = ( ! $fixture_order instanceof WC_Order || ! wc_get_order( (int) $fixture_order->get_id() ) )
		&& ( ! $product instanceof WC_Product || ! wc_get_product( (int) $product->get_id() ) );
}

if ( '' !== $failure || ! $cleanup_complete ) {
	dreamax_lm_unpaid_fail( '' !== $failure ? $failure : 'Temporary fixtures were not removed.' );
}

echo wp_json_encode(
	array(
		'automatic_unpaid_blocked' => $automatic_empty,
		'direct_unpaid_blocked'    => $direct_rejected,
		'cleanup_complete'         => $cleanup_complete,
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
