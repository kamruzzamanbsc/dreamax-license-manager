<?php
/**
 * Verifies stable product and installation identities in a disposable site.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

use Dreamax\LicenseManager\Activations\ActivationService;
use Dreamax\LicenseManager\Integrations\WooCommerce\ProductSettings;
use Dreamax\LicenseManager\Licenses\LicenseService;
use Dreamax\LicenseManager\ReleaseTools\DisposableEnvironmentGuard;

require_once __DIR__ . '/lib/release-tools.php';

/**
 * Stops with a sanitized error that does not disclose fixture values.
 *
 * @param string $message Sanitized failure message.
 */
function dreamax_lm_f18_fail( string $message ): never {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI verifier must report a sanitized failure to STDERR.
	fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
	exit( 1 );
}

/**
 * Returns the sole registered Dreamax product-duplicate callback.
 *
 * @return array{callback:callable,priority:int,accepted_args:int}
 */
function dreamax_lm_f18_duplicate_callback(): array {
	global $wp_filter;

	$registered = array();
	$wp_hook    = $wp_filter['woocommerce_product_duplicate'] ?? null;
	if ( $wp_hook instanceof WP_Hook ) {
		foreach ( $wp_hook->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $definition ) {
				$callback = $definition['function'] ?? null;
				if ( is_array( $callback )
					&& isset( $callback[0], $callback[1] )
					&& $callback[0] instanceof ProductSettings
					&& 'duplicate' === $callback[1]
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
		dreamax_lm_f18_fail( 'Exactly one Dreamax product-duplicate callback must be registered.' );
	}
	return $registered[0];
}

/**
 * Invokes only the registered Dreamax duplicate callback through WordPress.
 *
 * @param WC_Product                                              $duplicate Duplicate product.
 * @param WC_Product                                              $source Source product.
 * @param array{callback:callable,priority:int,accepted_args:int} $definition Callback definition.
 */
function dreamax_lm_f18_duplicate( WC_Product $duplicate, WC_Product $source, array $definition ): void {
	global $wp_filter;

	$hook_name = 'woocommerce_product_duplicate';
	$original  = $wp_filter[ $hook_name ];
	$isolated  = new WP_Hook();
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The verifier restores the exact original hook in finally.
	$wp_filter[ $hook_name ] = $isolated;
	try {
		add_action( $hook_name, $definition['callback'], $definition['priority'], $definition['accepted_args'] );
		do_action( $hook_name, $duplicate, $source );
	} finally {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the original WordPress hook object after isolated invocation.
		$wp_filter[ $hook_name ] = $original;
	}
}

$options = getopt( '', array( 'environment-marker:', 'wp-root:' ) );
$marker  = (string) ( $options['environment-marker'] ?? '' );
$wp_root = realpath( (string) ( $options['wp-root'] ?? '' ) );

if ( false === $wp_root || ! is_file( $wp_root . '/wp-load.php' ) ) {
	dreamax_lm_f18_fail( 'A valid WordPress root is required.' );
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
	dreamax_lm_f18_fail( 'The database identity is unavailable.' );
}
DisposableEnvironmentGuard::assertSafe(
	array(
		'marker'   => $marker,
		'database' => (string) DB_NAME,
		'site_url' => home_url( '/' ),
	)
);
if ( ! is_plugin_active( 'dreamax-license-manager/dreamax-license-manager.php' )
	|| ! class_exists( ActivationService::class )
	|| ! class_exists( ProductSettings::class ) ) {
	dreamax_lm_f18_fail( 'Dreamax License Manager is not active.' );
}

global $wpdb;

$required_tables = array(
	$wpdb->posts,
	$wpdb->postmeta,
	$wpdb->prefix . 'dreamax_lm_licenses',
	$wpdb->prefix . 'dreamax_lm_activations',
	$wpdb->prefix . 'dreamax_lm_events',
);
foreach ( $required_tables as $required_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Live rollback and cleanup safety require authoritative table-engine checks.
	$engine = $wpdb->get_var(
		$wpdb->prepare(
			'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
			(string) DB_NAME,
			$required_table
		)
	);
	if ( 'InnoDB' !== $engine ) {
		dreamax_lm_f18_fail( 'A required identity table is not safely transactional.' );
	}
}

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
	$items = array_values( $candidate_order->get_items( 'line_item' ) );
	if ( 1 !== count( $items ) || ! $items[0] instanceof WC_Order_Item_Product ) {
		continue;
	}
	$product = $items[0]->get_product();
	if ( ! $product instanceof WC_Product
		|| ! $product->is_type( 'simple' )
		|| 'yes' !== $product->get_meta( '_dreamax_lm_enabled', true )
		|| ! preg_match( '/^prd_[A-Za-z0-9_-]{22}$/D', (string) $product->get_meta( '_dreamax_lm_product_public_id', true ) ) ) {
		continue;
	}
	$candidates[] = $product;
}
if ( 1 !== count( $candidates ) ) {
	dreamax_lm_f18_fail( 'Exactly one sanitized F18 product candidate is required.' );
}

$source_product    = $candidates[0];
$source_product_id = (int) $source_product->get_id();
$source_public_id  = (string) $source_product->get_meta( '_dreamax_lm_product_public_id', true );
$source_name       = (string) $source_product->get_name();
$duplicate_hook    = dreamax_lm_f18_duplicate_callback();

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact rollback evidence requires fresh aggregate reads.
$product_rows_before = array(
	'posts'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ),
	'postmeta' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta}" ),
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	dreamax_lm_f18_fail( 'The product identity transaction could not start.' );
}

$product_failure      = null;
$product_rolled_back  = false;
$duplicate_product_id = 0;
try {
	$source_product->set_name( 'DLM F18 temporary edited label' );
	$source_product->save();
	$edited_public_id = (string) $source_product->get_meta( '_dreamax_lm_product_public_id', true );
	if ( ! hash_equals( $source_public_id, $edited_public_id ) ) {
		throw new RuntimeException( 'Editing the product label changed its public identity.' );
	}

	$duplicate = new WC_Product_Simple();
	$duplicate->set_name( 'DLM F18 temporary duplicate' );
	$duplicate->set_status( 'draft' );
	$duplicate->set_virtual( true );
	$duplicate_product_id = (int) $duplicate->save();
	foreach ( array( '_dreamax_lm_enabled', '_dreamax_lm_product_public_id', '_dreamax_lm_source', '_dreamax_lm_issuance', '_dreamax_lm_activation_limit', '_dreamax_lm_valid_days', '_dreamax_lm_refund_policy', '_dreamax_lm_cancellation_policy' ) as $meta_key ) {
		$duplicate->update_meta_data( $meta_key, $source_product->get_meta( $meta_key, true ) );
	}
	$duplicate->save_meta_data();
	if ( ! hash_equals( $source_public_id, (string) $duplicate->get_meta( '_dreamax_lm_product_public_id', true ) ) ) {
		throw new RuntimeException( 'The duplicate fixture did not begin with the copied product identity.' );
	}

	dreamax_lm_f18_duplicate( $duplicate, $source_product, $duplicate_hook );
	$duplicate_public_id = (string) $duplicate->get_meta( '_dreamax_lm_product_public_id', true );
	if ( ! preg_match( '/^prd_[A-Za-z0-9_-]{22}$/D', $duplicate_public_id ) || hash_equals( $source_public_id, $duplicate_public_id ) ) {
		throw new RuntimeException( 'The product duplicate did not receive a distinct public identity.' );
	}
} catch ( Throwable $error ) {
	$product_failure = $error;
} finally {
	$product_rolled_back = false !== $wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	clean_post_cache( $source_product_id );
	if ( $duplicate_product_id > 0 ) {
		clean_post_cache( $duplicate_product_id );
	}
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact post-rollback evidence requires fresh reads.
$product_rows_after = array(
	'posts'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ),
	'postmeta' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta}" ),
);
$stored_source_name = (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_title FROM {$wpdb->posts} WHERE ID = %d", $source_product_id ) );
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

if ( $product_failure instanceof Throwable ) {
	dreamax_lm_f18_fail( $product_failure->getMessage() );
}
if ( ! $product_rolled_back
	|| $product_rows_before !== $product_rows_after
	|| ! hash_equals( $source_name, $stored_source_name )
	|| ! hash_equals( $source_public_id, (string) get_post_meta( $source_product_id, '_dreamax_lm_product_public_id', true ) )
	|| ( $duplicate_product_id > 0 && false !== get_post_status( $duplicate_product_id ) ) ) {
	dreamax_lm_f18_fail( 'The product identity transaction did not restore its starting state.' );
}

$plugin_snapshot = static function () use ( $wpdb ): array {
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact fixture cleanup evidence requires uncached aggregate reads.
	return array(
		'licenses'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_licenses" ),
		'activations' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_activations" ),
		'events'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_events" ),
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
};

$plugin_before      = $plugin_snapshot();
$fixture_license_id = 0;
$fixture_public_id  = '';
$fixture_key        = '';
$identity_failure   = null;
$cleanup_passed     = false;
$same_instance      = false;
$distinct_instance  = false;
$label_edit_stable  = false;

try {
	$run_suffix         = substr( hash( 'sha256', random_bytes( 32 ) ), 0, 24 );
	$created            = ( new LicenseService() )->create_generated(
		array(
			'lifecycle_status'  => 'assigned',
			'product_public_id' => $source_public_id,
			'product_id'        => $source_product_id,
			'activation_limit'  => 2,
			'actor_type'        => 'system',
			'request_id'        => 'f18-create-' . $run_suffix,
			'source'            => 'generated',
			'metadata'          => array( 'fixture' => array( 'purpose' => 'f18-stable-identity' ) ),
		)
	);
	$fixture_license_id = (int) $created['id'];
	$fixture_public_id  = (string) $created['public_id'];
	$fixture_key        = (string) $created['key'];
	$instance_a         = 'f18-instance-a-' . $run_suffix;
	$instance_b         = 'f18-instance-b-' . $run_suffix;
	$activations        = new ActivationService();

	$first  = $activations->activate( $fixture_key, $source_public_id, $instance_a, 'F18 first label', 'f18-a1-' . $run_suffix );
	$replay = $activations->activate( $fixture_key, $source_public_id, $instance_a, 'F18 replay label', 'f18-a2-' . $run_suffix );
	$activations->deactivate( $fixture_key, $source_public_id, $instance_a, 'f18-a3-' . $run_suffix );
	$edited = $activations->activate( $fixture_key, $source_public_id, $instance_a, 'F18 edited label', 'f18-a4-' . $run_suffix );
	$second = $activations->activate( $fixture_key, $source_public_id, $instance_b, 'F18 second installation', 'f18-b1-' . $run_suffix );

	$same_instance     = hash_equals( (string) $first['activation_public_id'], (string) $replay['activation_public_id'] )
		&& hash_equals( (string) $first['activation_public_id'], (string) $edited['activation_public_id'] )
		&& true === $replay['replayed'];
	$distinct_instance = ! hash_equals( (string) $first['activation_public_id'], (string) $second['activation_public_id'] );

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact live identity evidence requires fresh fixture reads.
	$fixture_activations = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_activations WHERE license_id = %d", $fixture_license_id ) );
	$fixture_events      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_events WHERE license_id = %d", $fixture_license_id ) );
	$stored_label        = (string) $wpdb->get_var( $wpdb->prepare( "SELECT instance_label FROM {$wpdb->prefix}dreamax_lm_activations WHERE public_id = %s", (string) $edited['activation_public_id'] ) );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$label_edit_stable = 'F18 edited label' === $stored_label;

	if ( ! $same_instance || ! $distinct_instance || ! $label_edit_stable || 2 !== $fixture_activations || 5 !== $fixture_events ) {
		throw new RuntimeException( 'A product or installation identity assertion failed.' );
	}
} catch ( Throwable $error ) {
	$identity_failure = $error;
} finally {
	if ( $fixture_license_id > 0 && '' !== $fixture_public_id ) {
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup is scoped to the exact owned fixture license.
		$activation_cleanup = $wpdb->delete( $wpdb->prefix . 'dreamax_lm_activations', array( 'license_id' => $fixture_license_id ), array( '%d' ) );
		$event_cleanup      = $wpdb->delete( $wpdb->prefix . 'dreamax_lm_events', array( 'license_id' => $fixture_license_id ), array( '%d' ) );
		$license_cleanup    = $wpdb->delete(
			$wpdb->prefix . 'dreamax_lm_licenses',
			array(
				'id'        => $fixture_license_id,
				'public_id' => $fixture_public_id,
			),
			array( '%d', '%s' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$cleanup_passed = false !== $activation_cleanup
			&& false !== $event_cleanup
			&& 1 === $license_cleanup
			&& false !== $wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( ! $cleanup_passed ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}
	}
	if ( '' !== $fixture_key && function_exists( 'sodium_memzero' ) ) {
		sodium_memzero( $fixture_key );
	}
}

$plugin_after = $plugin_snapshot();
if ( $identity_failure instanceof Throwable ) {
	dreamax_lm_f18_fail( $identity_failure->getMessage() );
}
if ( ! $cleanup_passed || $plugin_before !== $plugin_after ) {
	dreamax_lm_f18_fail( 'The owned identity fixtures were not completely removed.' );
}

echo wp_json_encode(
	array(
		'classification'                  => 'live_disposable_wordpress_innodb',
		'product_edit_identity_stable'    => true,
		'product_duplicate_identity_new'  => true,
		'same_instance_identity_stable'   => $same_instance,
		'instance_label_edit_stable'      => $label_edit_stable,
		'different_instance_identity_new' => $distinct_instance,
		'fixture_activation_rows'         => 2,
		'fixture_event_rows'              => 5,
		'product_transaction_rolled_back' => $product_rolled_back,
		'owned_fixture_cleanup'           => $cleanup_passed,
		'database_state_unchanged'        => $product_rows_before === $product_rows_after && $plugin_before === $plugin_after,
		'sensitive_output'                => false,
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
