<?php
/**
 * Verifies live capability isolation in an unmistakably disposable WordPress site.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

use Dreamax\LicenseManager\ReleaseTools\DisposableEnvironmentGuard;
use Dreamax\LicenseManager\Support\Capabilities;

require_once __DIR__ . '/lib/release-tools.php';

/**
 * Stops with a sanitized error that does not disclose paths or fixture values.
 *
 * @param string $message Sanitized failure message.
 */
function dreamax_lm_f10_fail( string $message ): never {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI verifier must report a sanitized failure to STDERR.
	fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
	exit( 1 );
}

$options = getopt( '', array( 'environment-marker:', 'wp-root:' ) );
$marker  = (string) ( $options['environment-marker'] ?? '' );
$wp_root = realpath( (string) ( $options['wp-root'] ?? '' ) );

if ( false === $wp_root || ! is_file( $wp_root . '/wp-load.php' ) ) {
	dreamax_lm_f10_fail( 'A valid WordPress root is required.' );
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
	dreamax_lm_f10_fail( 'The database identity is unavailable.' );
}

DisposableEnvironmentGuard::assertSafe(
	array(
		'marker'   => $marker,
		'database' => (string) DB_NAME,
		'site_url' => home_url( '/' ),
	)
);

if ( ! is_plugin_active( 'dreamax-license-manager/dreamax-license-manager.php' ) || ! class_exists( Capabilities::class ) ) {
	dreamax_lm_f10_fail( 'Dreamax License Manager is not active.' );
}

global $wpdb;

$capabilities      = Capabilities::all();
$fixture_user_ids  = array();
$fixture_logins    = array();
$user_count_before = (int) $wpdb->get_var( "SELECT COUNT(ID) FROM {$wpdb->users}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$checks            = array();
$failure           = null;
$rolled_back       = false;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Live verifier must confirm transaction safety before creating fixtures.
$transactional_tables = (int) $wpdb->get_var(
	$wpdb->prepare(
		'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME IN (%s, %s) AND ENGINE = %s',
		(string) DB_NAME,
		$wpdb->users,
		$wpdb->usermeta,
		'InnoDB'
	)
);
if ( 2 !== $transactional_tables ) {
	dreamax_lm_f10_fail( 'The WordPress user tables are not safely transactional.' );
}

if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	dreamax_lm_f10_fail( 'The disposable fixture transaction could not start.' );
}

try {
	$administrator_ids = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ids',
		)
	);
	if ( array() === $administrator_ids ) {
		throw new RuntimeException( 'An administrator fixture is required.' );
	}

	$fixture_suffix = substr( hash( 'sha256', random_bytes( 32 ) ), 0, 12 );
	foreach ( array( 'shop_manager', 'customer' ) as $fixture_role ) {
		if ( null === get_role( $fixture_role ) ) {
			throw new RuntimeException( 'A required WooCommerce role is unavailable.' );
		}
		$fixture_login = 'dlmf10_' . $fixture_role . '_' . $fixture_suffix;
		$fixture_id    = wp_insert_user(
			array(
				'user_login' => $fixture_login,
				'user_pass'  => bin2hex( random_bytes( 32 ) ),
				'user_email' => $fixture_login . '@example.invalid',
				'role'       => $fixture_role,
			)
		);
		if ( is_wp_error( $fixture_id ) ) {
			throw new RuntimeException( 'A synthetic role fixture could not be created.' );
		}
		$fixture_user_ids[ $fixture_role ] = (int) $fixture_id;
		$fixture_logins[]                  = $fixture_login;
	}

	wp_set_current_user( (int) $administrator_ids[0] );
	$checks['administrator_all'] = array_reduce(
		$capabilities,
		static fn( bool $allowed, string $capability ): bool => $allowed && current_user_can( $capability ),
		true
	);

	wp_set_current_user( $fixture_user_ids['shop_manager'] );
	$checks['shop_manager_narrow'] = current_user_can( Capabilities::MANAGE )
		&& current_user_can( Capabilities::DIAGNOSTICS )
		&& ! current_user_can( Capabilities::REVEAL )
		&& ! current_user_can( Capabilities::EXPORT )
		&& ! current_user_can( Capabilities::CREDENTIALS )
		&& ! current_user_can( Capabilities::DELETE )
		&& ! current_user_can( Capabilities::SECURITY );
	$shop_nonce                    = wp_create_nonce( 'dreamax_lm_f10_capability' );

	wp_set_current_user( $fixture_user_ids['customer'] );
	$checks['customer_none']     = array_reduce(
		$capabilities,
		static fn( bool $denied, string $capability ): bool => $denied && ! current_user_can( $capability ),
		true
	);
	$checks['nonce_actor_bound'] = false === wp_verify_nonce( $shop_nonce, 'dreamax_lm_f10_capability' );

	$customer = new WP_User( $fixture_user_ids['customer'] );
	$isolated = true;
	foreach ( $capabilities as $granted ) {
		$customer->add_cap( $granted );
		clean_user_cache( $fixture_user_ids['customer'] );
		wp_set_current_user( 0 );
		wp_set_current_user( $fixture_user_ids['customer'] );
		foreach ( $capabilities as $candidate ) {
			$isolated = $isolated && ( $candidate === $granted ) === current_user_can( $candidate );
		}
		$customer->remove_cap( $granted );
		clean_user_cache( $fixture_user_ids['customer'] );
		$customer = new WP_User( $fixture_user_ids['customer'] );
	}
	$checks['independent_grants'] = $isolated;

	wp_set_current_user( $fixture_user_ids['shop_manager'] );
	$checks['nonce_owner_accepts'] = false !== wp_verify_nonce( $shop_nonce, 'dreamax_lm_f10_capability' );

	if ( in_array( false, $checks, true ) ) {
		throw new RuntimeException( 'A capability or nonce isolation assertion failed.' );
	}
} catch ( Throwable $error ) {
	$failure = $error;
} finally {
	wp_set_current_user( 0 );
	$rolled_back = false !== $wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
}

$remaining_fixtures = 0;
foreach ( $fixture_logins as $fixture_login ) {
	$remaining_fixtures += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(ID) FROM {$wpdb->users} WHERE user_login = %s", $fixture_login ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
}
$cleanup_passed = $rolled_back
	&& 0 === $remaining_fixtures
	&& $user_count_before === (int) $wpdb->get_var( "SELECT COUNT(ID) FROM {$wpdb->users}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

if ( ! $cleanup_passed ) {
	dreamax_lm_f10_fail( 'Synthetic user cleanup did not restore the original user count.' );
}
if ( $failure instanceof Throwable ) {
	dreamax_lm_f10_fail( $failure->getMessage() );
}

echo wp_json_encode(
	array(
		'classification'        => 'live_disposable_wordpress',
		'roles_checked'         => 3,
		'narrow_capabilities'   => count( $capabilities ),
		'checks_passed'         => count( $checks ),
		'synthetic_users'       => count( $fixture_user_ids ),
		'synthetic_users_left'  => $remaining_fixtures,
		'user_count_restored'   => $cleanup_passed,
		'database_rolled_back'  => $rolled_back,
		'sensitive_output'      => false,
		'database_fixture_only' => true,
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
