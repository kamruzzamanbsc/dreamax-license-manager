<?php
/**
 * Verifies read-only inventory sorting and indicators on a disposable WordPress site.
 *
 * Run with wp eval-file scripts/verify-live-admin-inventory.php --path=<disposable-site>.
 *
 * @package DreamaxLicenseManager
 */

use Dreamax\LicenseManager\Admin\Admin;
use Dreamax\LicenseManager\Admin\InventoryInsights;
use Dreamax\LicenseManager\ReleaseTools\DisposableEnvironmentGuard;
use Dreamax\LicenseManager\Support\PublicId;

require_once __DIR__ . '/lib/release-tools.php';

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'DB_NAME' ) ) {
	throw new RuntimeException( 'Run this verifier through WP-CLI on a disposable site.' );
}
DisposableEnvironmentGuard::assertSafe(
	array(
		'marker'   => 'DREAMAX_LM_DISPOSABLE_TEST',
		'database' => (string) DB_NAME,
		'site_url' => home_url( '/' ),
	)
);

global $wpdb;
$administrators = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
if ( array() === $administrators ) {
	throw new RuntimeException( 'No administrator is available for the guarded verifier.' );
}
$previous_user = get_current_user_id();
$previous_get  = $_GET;
wp_set_current_user( (int) $administrators[0] );

$table = $wpdb->prefix . 'dreamax_lm_licenses';
foreach ( array( $table, $wpdb->posts, $wpdb->postmeta ) as $checked_table ) {
	$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s', (string) DB_NAME, $checked_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Guarded storage-engine check.
	if ( ! is_string( $engine ) || 'INNODB' !== strtoupper( $engine ) ) {
		throw new RuntimeException( 'A required disposable table is not transactional.' );
	}
}
$product_a    = PublicId::generate( 'prd' );
$pool_product = PublicId::generate( 'prd' );
$product_b    = $pool_product;
$ids        = array( PublicId::generate( 'lic' ), PublicId::generate( 'lic' ), PublicId::generate( 'lic' ) );
$fingerprints = array( random_bytes( 32 ), random_bytes( 32 ), random_bytes( 32 ) );
$now        = gmdate( 'Y-m-d H:i:s' );
$baseline   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Disposable baseline only.
$initial_upcoming = ( new InventoryInsights() )->summary()['expiring'];
$passed     = array();
$rolled_back = false;
$failed     = false;
$pool_post_id = 0;

try {
	if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Guarded disposable transaction.
		throw new RuntimeException( 'The disposable transaction could not start.' );
	}
	foreach ( $ids as $index => $public_id ) {
		$inserted = $wpdb->insert(
			$table,
			array(
				'public_id'             => $public_id,
				'key_ciphertext'        => 'nonsecret-test-placeholder',
				'key_fingerprint'       => $fingerprints[ $index ],
				'normalization_profile' => 'generated-ascii-v1',
				'lifecycle_status'      => 'assigned',
				'product_public_id'     => 2 === $index ? $product_b : $product_a,
				'activation_limit'      => 1,
				'expires_at'            => gmdate( 'Y-m-d H:i:s', time() + ( 0 === $index ? 10 : 80 + $index ) * DAY_IN_SECONDS ),
				'created_at'            => $now,
				'updated_at'            => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
		if ( 1 !== $inserted ) {
			throw new RuntimeException( 'A disposable inventory row could not be inserted.' );
		}
	}
	if ( 1 !== $wpdb->insert( $wpdb->posts, array( 'post_title' => 'Disposable pool fixture', 'post_name' => 'dreamax-pool-' . strtolower( substr( $pool_product, -12 ) ), 'post_type' => 'product', 'post_status' => 'publish', 'post_date' => $now, 'post_date_gmt' => $now, 'post_modified' => $now, 'post_modified_gmt' => $now ), array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) ) ) {
		throw new RuntimeException( 'A disposable product row could not be inserted.' );
	}
	$pool_post_id = (int) $wpdb->insert_id;
	if ( 1 !== $wpdb->update( $table, array( 'product_id' => $pool_post_id ), array( 'public_id' => $ids[2] ), array( '%d' ), array( '%s' ) ) ) {
		throw new RuntimeException( 'The disposable product reference could not be linked.' );
	}
	foreach ( array( '_dreamax_lm_enabled' => 'yes', '_dreamax_lm_source' => 'pool', '_dreamax_lm_product_public_id' => $pool_product ) as $meta_key => $meta_value ) {
		if ( 1 !== $wpdb->insert( $wpdb->postmeta, array( 'post_id' => $pool_post_id, 'meta_key' => $meta_key, 'meta_value' => $meta_value ), array( '%d', '%s', '%s' ) ) ) {
			throw new RuntimeException( 'A disposable product configuration could not be inserted.' );
		}
	}
	$fixture_product = wc_get_product( $pool_post_id );
	$fixture_policy  = $fixture_product ? ( new Dreamax\LicenseManager\Integrations\WooCommerce\ProductPolicy() )->resolve( $fixture_product ) : null;
	$passed['pool_product_loaded'] = (bool) $fixture_product;
	$passed['pool_policy_enabled'] = is_array( $fixture_policy ) && $fixture_policy['enabled'];
	$passed['pool_policy_source'] = is_array( $fixture_policy ) && 'pool' === $fixture_policy['source'];

	$admin = new Admin();
	$_GET = array( 'page' => 'dreamax-license-manager', 'orderby' => 'expiry', 'order' => 'asc' );
	ob_start();
	$admin->licenses_page();
	$ascending = (string) ob_get_clean();
	$_GET['order'] = 'desc';
	ob_start();
	$admin->licenses_page();
	$descending = (string) ob_get_clean();
	$_GET = array( 'page' => 'dreamax-license-manager', 'product' => $product_a );
	ob_start();
	$admin->licenses_page();
	$filtered = (string) ob_get_clean();
	$_GET = array( 'page' => 'dreamax-license-manager', 'product' => $product_b );
	ob_start();
	$admin->licenses_page();
	$linked = (string) ob_get_clean();

	$ascending_first  = strpos( $ascending, $ids[0] );
	$ascending_second = strpos( $ascending, $ids[1] );
	$descending_first = strpos( $descending, $ids[0] );
	$descending_second = strpos( $descending, $ids[1] );
	$passed['expiry_ascending'] = false !== $ascending_first && false !== $ascending_second && $ascending_first < $ascending_second;
	$passed['expiry_descending'] = false !== $descending_first && false !== $descending_second && $descending_second < $descending_first;
	$passed['exact_product_filter'] = str_contains( $filtered, $ids[0] ) && str_contains( $filtered, $ids[1] ) && ! str_contains( $filtered, $ids[2] );
	$product_url = get_edit_post_link( $pool_post_id, '' );
	$passed['authorized_product_link'] = is_string( $product_url ) && '' !== $product_url && str_contains( $linked, esc_url( $product_url ) );
	$passed['sort_ui'] = str_contains( $ascending, 'aria-sort="ascending"' ) && str_contains( $ascending, 'dreamax-lm-sort-link' );
	$insights = ( new InventoryInsights() )->summary();
	$passed['expiring_indicator'] = $insights['expiring'] === $initial_upcoming + 1;
	$passed['zero_stock_pool'] = 1 === count( array_filter( $insights['low_pools'], static fn( array $pool ): bool => $pool['public_id'] === $pool_product && 0 === $pool['available'] ) );
	$passed['no_sql_error'] = '' === $wpdb->last_error;
} catch ( Throwable $error ) {
	$failed = true;
} finally {
	$rolled_back = false !== $wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reverts all verifier-owned rows.
	$_GET = $previous_get;
	wp_set_current_user( $previous_user );
}

$passed['rollback'] = $rolled_back && $baseline === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact post-rollback aggregate.
$passed['product_rollback'] = $rolled_back && $pool_post_id > 0 && null === $wpdb->get_row( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID=%d", $pool_post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact post-rollback fixture check.
$passed['runtime'] = ! $failed;
echo wp_json_encode( $passed ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI emits only fixed boolean fields.
if ( in_array( false, $passed, true ) ) {
	exit( 1 );
}
