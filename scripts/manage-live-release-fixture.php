<?php
/**
 * Creates or removes one sanitized fixture shared by release-candidate verifiers.
 *
 * @package DreamaxLicenseManager
 */

use Dreamax\LicenseManager\Licenses\LicenseRepository;
use Dreamax\LicenseManager\ReleaseTools\DisposableEnvironmentGuard;
use Dreamax\LicenseManager\Support\PublicId;

require_once __DIR__ . '/lib/release-tools.php';

const DREAMAX_LM_RC_FIXTURE_OPTION = 'dreamax_lm_rc_fixture';

/**
 * Stops without exposing fixture values.
 *
 * @param string $message Sanitized failure message.
 */
function dreamax_lm_rc_fixture_fail( string $message ): never {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI verifier reports only fixed sanitized failures.
	fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
	exit( 1 );
}

$options   = getopt( '', array( 'environment-marker:', 'wp-root:', 'action:' ) );
$marker    = (string) ( $options['environment-marker'] ?? '' );
$wp_root   = realpath( (string) ( $options['wp-root'] ?? '' ) );
$operation = (string) ( $options['action'] ?? '' );
if ( false === $wp_root || ! is_file( $wp_root . '/wp-load.php' ) || ! in_array( $operation, array( 'prepare', 'cleanup' ), true ) ) {
	dreamax_lm_rc_fixture_fail( 'A valid disposable WordPress root and action are required.' );
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
DisposableEnvironmentGuard::assertSafe(
	array(
		'marker'   => $marker,
		'database' => defined( 'DB_NAME' ) ? (string) DB_NAME : '',
		'site_url' => home_url( '/' ),
	)
);
if ( ! is_plugin_active( 'dreamax-license-manager/dreamax-license-manager.php' ) || ! function_exists( 'WC' ) ) {
	dreamax_lm_rc_fixture_fail( 'The disposable WooCommerce runtime is unavailable.' );
}

global $wpdb;
$stored = get_option( DREAMAX_LM_RC_FIXTURE_OPTION, null );
if ( 'cleanup' === $operation ) {
	if ( ! is_array( $stored ) ) {
		echo wp_json_encode(
			array(
				'cleanup'          => true,
				'fixture_found'    => false,
				'sensitive_output' => false,
			)
		);
		exit;
	}
	$license_id = (int) ( $stored['license_id'] ?? 0 );
	if ( $license_id > 0 ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Deletes only exact IDs stored by this guarded fixture manager.
		$wpdb->delete( $wpdb->prefix . 'dreamax_lm_activations', array( 'license_id' => $license_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'dreamax_lm_events', array( 'license_id' => $license_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'dreamax_lm_licenses', array( 'id' => $license_id ), array( '%d' ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}
	$fixture_order = wc_get_order( (int) ( $stored['order_id'] ?? 0 ) );
	if ( $fixture_order instanceof WC_Order ) {
		$fixture_order->delete( true );
	}
	$product = wc_get_product( (int) ( $stored['product_id'] ?? 0 ) );
	if ( $product instanceof WC_Product ) {
		$product->delete( true );
	}
	$user_id = (int) ( $stored['user_id'] ?? 0 );
	if ( $user_id > 0 && get_user_by( 'id', $user_id ) ) {
		wp_delete_user( $user_id );
	}
	delete_option( DREAMAX_LM_RC_FIXTURE_OPTION );
	$remaining = 0;
	foreach ( array( 'license_id', 'order_id', 'product_id', 'user_id' ) as $field ) {
		$remaining += (int) ( $stored[ $field ] ?? 0 ) > 0 && ( 'license_id' === $field
			? null !== $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id=%d', $wpdb->prefix . 'dreamax_lm_licenses', (int) $stored[ $field ] ) ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Checks only the exact stored fixture ID.
			: ( 'order_id' === $field ? (bool) wc_get_order( (int) $stored[ $field ] ) : ( 'product_id' === $field ? (bool) wc_get_product( (int) $stored[ $field ] ) : (bool) get_user_by( 'id', (int) $stored[ $field ] ) ) ) );
	}
	echo wp_json_encode(
		array(
			'cleanup'           => 0 === $remaining,
			'fixture_found'     => true,
			'records_remaining' => $remaining,
			'sensitive_output'  => false,
		)
	);
	exit( 0 === $remaining ? 0 : 1 );
}

if ( is_array( $stored ) ) {
	dreamax_lm_rc_fixture_fail( 'A release fixture already exists; clean it before preparing another.' );
}
$candidates = wc_get_orders(
	array(
		'limit'         => 2,
		'status'        => array( 'processing' ),
		'billing_email' => 'dreamax-rc@example.invalid',
	)
);
if ( array() !== $candidates ) {
	dreamax_lm_rc_fixture_fail( 'A stale sanitized release order exists.' );
}

$state = array(
	'user_id'    => 0,
	'product_id' => 0,
	'order_id'   => 0,
	'license_id' => 0,
);
add_filter( 'pre_wp_mail', static fn(): bool => false, PHP_INT_MAX );
try {
	$login   = 'dlm_rc_' . substr( hash( 'sha256', random_bytes( 32 ) ), 0, 16 );
	$user_id = wp_insert_user(
		array(
			'user_login' => $login,
			'user_pass'  => bin2hex( random_bytes( 32 ) ),
			'user_email' => 'dreamax-rc@example.invalid',
			'role'       => 'customer',
		)
	);
	if ( is_wp_error( $user_id ) ) {
		throw new RuntimeException( 'The sanitized customer could not be created.' );
	}
	$state['user_id'] = (int) $user_id;
	update_option( DREAMAX_LM_RC_FIXTURE_OPTION, $state, false );

	$product = new WC_Product_Simple();
	$product->set_name( 'Dreamax release verifier product' );
	$product->set_status( 'publish' );
	$product->set_virtual( true );
	$product->set_regular_price( '10' );
	$product->save();
	$product->update_meta_data( '_dreamax_lm_enabled', 'yes' );
	$product->update_meta_data( '_dreamax_lm_product_public_id', PublicId::generate( 'prd' ) );
	$product->update_meta_data( '_dreamax_lm_source', 'generated' );
	$product->update_meta_data( '_dreamax_lm_issuance', 'per_quantity' );
	$product->update_meta_data( '_dreamax_lm_activation_limit', '2' );
	$product->update_meta_data( '_dreamax_lm_valid_days', '365' );
	$product->save_meta_data();
	$state['product_id'] = (int) $product->get_id();
	update_option( DREAMAX_LM_RC_FIXTURE_OPTION, $state, false );

	$fixture_order = wc_create_order(
		array(
			'status'      => 'pending',
			'customer_id' => (int) $user_id,
		)
	);
	if ( ! $fixture_order instanceof WC_Order ) {
		throw new RuntimeException( 'The sanitized order could not be created.' );
	}
	$fixture_order->set_billing_email( 'dreamax-rc@example.invalid' );
	$fixture_order->set_payment_method( 'bacs' );
	$fixture_order->add_product( $product, 1 );
	$fixture_order->calculate_totals();
	$fixture_order->set_date_paid( time() );
	$fixture_order->set_status( 'processing' );
	$fixture_order->save();
	$state['order_id'] = (int) $fixture_order->get_id();
	update_option( DREAMAX_LM_RC_FIXTURE_OPTION, $state, false );

	$licenses = ( new LicenseRepository() )->for_order( (int) $fixture_order->get_id() );
	if ( 1 !== count( $licenses ) || 'assigned' !== (string) $licenses[0]['lifecycle_status'] || (int) $user_id !== (int) $licenses[0]['customer_id'] ) {
		throw new RuntimeException( 'The paid order did not receive exactly one assigned license.' );
	}
	$state['license_id'] = (int) $licenses[0]['id'];
	update_option( DREAMAX_LM_RC_FIXTURE_OPTION, $state, false );
} catch ( Throwable $error ) {
	dreamax_lm_rc_fixture_fail( $error->getMessage() );
}

echo wp_json_encode(
	array(
		'prepared'           => true,
		'paid_order'         => true,
		'assigned_licenses'  => 1,
		'external_mail_sent' => false,
		'sensitive_output'   => false,
	)
);
