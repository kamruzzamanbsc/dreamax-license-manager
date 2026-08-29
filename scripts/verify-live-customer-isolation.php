<?php
/**
 * Verifies customer list, reveal, and registered-order isolation.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

use Dreamax\LicenseManager\CustomerPortal\AccountEndpoint;
use Dreamax\LicenseManager\Integrations\WooCommerce\OrderLicensing;
use Dreamax\LicenseManager\Licenses\LicenseRepository;
use Dreamax\LicenseManager\ReleaseTools\DisposableEnvironmentGuard;

require_once __DIR__ . '/lib/release-tools.php';

/**
 * Stops with a sanitized failure.
 *
 * @param string $message Sanitized failure message.
 */
function dreamax_lm_f09_fail( string $message ): never {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI verifier reports only sanitized failures.
	fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
	exit( 1 );
}

/**
 * Captures one reveal response without printing its sensitive success payload.
 *
 * @param AccountEndpoint $endpoint Endpoint under test.
 * @param int             $user_id Current actor.
 * @param string          $public_id Presented license identity.
 * @throws RuntimeException When the reveal response cannot be captured safely.
 * @return array<string,mixed>
 */
function dreamax_lm_f09_reveal( AccountEndpoint $endpoint, int $user_id, string $public_id ): array {
	// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- The verifier snapshots and supplies its own actor-bound nonce to the production handler.
	$post_before    = $_POST;
	$request_before = $_REQUEST;
	wp_set_current_user( $user_id );
	$nonce    = wp_create_nonce( 'dreamax_lm_reveal' );
	$_POST    = array(
		'action'  => 'dreamax_lm_reveal',
		'nonce'   => $nonce,
		'license' => $public_id,
	);
	$_REQUEST = $_POST;
	// phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
	$handler = static function (): callable {
		return static function (): void {
			throw new RuntimeException( 'dreamax_lm_f09_json_exit' );
		};
	};
	add_filter( 'wp_die_ajax_handler', $handler, PHP_INT_MAX );
	$body = '';
	ob_start();
	try {
		$endpoint->reveal();
		throw new RuntimeException( 'A reveal response did not terminate.' );
	} catch ( RuntimeException $error ) {
		$body = (string) ob_get_clean();
		if ( 'dreamax_lm_f09_json_exit' !== $error->getMessage() ) {
			throw $error;
		}
	} finally {
		if ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		remove_filter( 'wp_die_ajax_handler', $handler, PHP_INT_MAX );
		$_POST    = $post_before;
		$_REQUEST = $request_before;
	}
	$data = json_decode( $body, true );
	if ( ! is_array( $data ) ) {
		throw new RuntimeException( 'A reveal response was not valid JSON.' );
	}
	return $data;
}

$options = getopt( '', array( 'environment-marker:', 'wp-root:' ) );
$marker  = (string) ( $options['environment-marker'] ?? '' );
$wp_root = realpath( (string) ( $options['wp-root'] ?? '' ) );
if ( false === $wp_root || ! is_file( $wp_root . '/wp-load.php' ) ) {
	dreamax_lm_f09_fail( 'A valid WordPress root is required.' );
}
if ( ! defined( 'DISABLE_WP_CRON' ) ) {
	define( 'DISABLE_WP_CRON', true );
}
if ( ! defined( 'WP_USE_THEMES' ) ) {
	define( 'WP_USE_THEMES', false );
}
if ( ! defined( 'DOING_AJAX' ) ) {
	define( 'DOING_AJAX', true );
}
require_once $wp_root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
if ( ! defined( 'DB_NAME' ) ) {
	dreamax_lm_f09_fail( 'The database identity is unavailable.' );
}
DisposableEnvironmentGuard::assertSafe(
	array(
		'marker'   => $marker,
		'database' => (string) DB_NAME,
		'site_url' => home_url( '/' ),
	)
);
if ( ! is_plugin_active( 'dreamax-license-manager/dreamax-license-manager.php' ) || ! function_exists( 'WC' ) ) {
	dreamax_lm_f09_fail( 'The required WordPress plugins are not active.' );
}

global $wpdb;
$repository = new LicenseRepository();
$candidates = array();
foreach (
	wc_get_orders(
		array(
			'limit'   => 25,
			'status'  => array( 'processing' ),
			'orderby' => 'date',
			'order'   => 'DESC',
		)
	) as $candidate_order
) {
	if ( ! $candidate_order instanceof WC_Order
		|| ! $candidate_order->is_paid()
		|| (int) $candidate_order->get_customer_id() < 1
		|| ! str_ends_with( strtolower( (string) $candidate_order->get_billing_email() ), '@example.invalid' ) ) {
		continue;
	}
	$order_licenses = $repository->for_order( (int) $candidate_order->get_id() );
	if ( 1 !== count( $order_licenses )
		|| 'assigned' !== (string) $order_licenses[0]['lifecycle_status']
		|| (int) $candidate_order->get_customer_id() !== (int) $order_licenses[0]['customer_id']
		|| ! get_user_by( 'id', (int) $candidate_order->get_customer_id() ) ) {
		continue;
	}
	$candidates[] = array(
		'order'   => $candidate_order,
		'license' => $order_licenses[0],
	);
}
if ( 1 !== count( $candidates ) ) {
	dreamax_lm_f09_fail( 'Exactly one sanitized registered-order fixture is required.' );
}

$fixture_order  = $candidates[0]['order'];
$license        = $candidates[0]['license'];
$owner_id       = (int) $fixture_order->get_customer_id();
$license_key    = $repository->decrypt_key( $license );
$license_public = (string) $license['public_id'];
$login          = 'dlmf09_' . substr( hash( 'sha256', random_bytes( 32 ) ), 0, 16 );
$tables         = array( $wpdb->users, $wpdb->usermeta, $wpdb->prefix . 'dreamax_lm_licenses', $wpdb->prefix . 'dreamax_lm_events' );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction safety must be authoritative before fixture creation.
$transactional = (int) $wpdb->get_var(
	$wpdb->prepare(
		'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME IN (%s,%s,%s,%s) AND ENGINE=%s',
		(string) DB_NAME,
		$wpdb->users,
		$wpdb->usermeta,
		$wpdb->prefix . 'dreamax_lm_licenses',
		$wpdb->prefix . 'dreamax_lm_events',
		'InnoDB'
	)
);
if ( count( $tables ) !== $transactional ) {
	dreamax_lm_f09_fail( 'A customer-isolation fixture table is not safely transactional.' );
}

$counts = static function () use ( $wpdb ): array {
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact restoration evidence requires fresh aggregates.
	return array(
		'users'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ),
		'usermeta' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->usermeta}" ),
		'licenses' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_licenses" ),
		'events'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_events" ),
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
};

$before = $counts();
// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- The verifier snapshots request globals and restores them in finally.
$post_before    = $_POST;
$request_before = $_REQUEST;
$get_before     = $_GET;
// phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
$error_log_before = ini_get( 'error_log' );
$attacker_id      = 0;
$failure          = null;
$rolled_back      = false;
$checks           = array();
if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	dreamax_lm_f09_fail( 'The disposable fixture transaction could not start.' );
}

try {
	$created = wp_insert_user(
		array(
			'user_login' => $login,
			'user_pass'  => bin2hex( random_bytes( 32 ) ),
			'user_email' => $login . '@example.invalid',
			'role'       => 'customer',
		)
	);
	if ( is_wp_error( $created ) ) {
		throw new RuntimeException( 'The synthetic attacker account could not be created.' );
	}
	$attacker_id = (int) $created;
	// phpcs:ignore WordPress.PHP.IniSet.Risky -- Suppress opaque runtime identifiers and restore the exact setting in finally.
	ini_set( 'error_log', 'NUL' );

	$endpoint = new AccountEndpoint();
	wp_set_current_user( $attacker_id );
	ob_start();
	$endpoint->render();
	$attacker_list                  = (string) ob_get_clean();
	$checks['attacker_list_denied'] = ! str_contains( $attacker_list, $license_public ) && ! str_contains( $attacker_list, $license_key );

	wp_set_current_user( $owner_id );
	ob_start();
	$endpoint->render();
	$owner_list                  = (string) ob_get_clean();
	$checks['owner_list_masked'] = str_contains( $owner_list, $license_public ) && ! str_contains( $owner_list, $license_key );

	$attacker_reveal                  = dreamax_lm_f09_reveal( $endpoint, $attacker_id, $license_public );
	$encoded_denial                   = wp_json_encode( $attacker_reveal );
	$checks['attacker_reveal_denied'] = false === ( $attacker_reveal['success'] ?? true )
		&& ! str_contains( (string) $encoded_denial, $license_key )
		&& ! str_contains( (string) $encoded_denial, $license_public );

	$owner_reveal                   = dreamax_lm_f09_reveal( $endpoint, $owner_id, $license_public );
	$owner_data                     = $owner_reveal['data'] ?? null;
	$checks['owner_reveal_allowed'] = true === ( $owner_reveal['success'] ?? false )
		&& is_array( $owner_data )
		&& hash_equals( hash( 'sha256', $license_key ), hash( 'sha256', (string) ( $owner_data['key'] ?? '' ) ) );

	$orders = new OrderLicensing();
	wp_set_current_user( $attacker_id );
	ob_start();
	$orders->order_licenses( $fixture_order );
	$attacker_order                  = (string) ob_get_clean();
	$checks['attacker_order_denied'] = ! str_contains( $attacker_order, $license_key ) && ! str_contains( $attacker_order, $license_public );

	wp_set_current_user( $owner_id );
	ob_start();
	$orders->order_licenses( $fixture_order );
	$owner_order                   = (string) ob_get_clean();
	$checks['owner_order_allowed'] = str_contains( $owner_order, $license_key );

	if ( in_array( false, $checks, true ) ) {
		throw new RuntimeException( 'A customer isolation assertion failed.' );
	}
} catch ( Throwable $error ) {
	$failure = $error;
} finally {
	while ( ob_get_level() > 0 ) {
		ob_end_clean();
	}
	wp_set_current_user( 0 );
	$_POST    = $post_before;
	$_REQUEST = $request_before;
	$_GET     = $get_before;
	if ( false !== $error_log_before ) {
		// phpcs:ignore WordPress.PHP.IniSet.Risky -- Restore the exact pre-run CLI logging destination.
		ini_set( 'error_log', (string) $error_log_before );
	}
	$rolled_back = false !== $wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
}

if ( $attacker_id > 0 ) {
	clean_user_cache( $attacker_id );
}
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact owned-fixture cleanup verification.
$remaining = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login=%s", $login ) );
$after     = $counts();
$restored  = $rolled_back && 0 === $remaining && $before === $after;

if ( function_exists( 'sodium_memzero' ) ) {
	sodium_memzero( $license_key );
}
unset( $owner_data, $owner_reveal, $attacker_reveal, $owner_list, $attacker_list, $owner_order, $attacker_order );
if ( ! $restored ) {
	dreamax_lm_f09_fail( 'The customer-isolation fixtures were not fully restored.' );
}
if ( $failure instanceof Throwable ) {
	dreamax_lm_f09_fail( $failure->getMessage() );
}

echo wp_json_encode(
	array(
		'classification'            => 'live_disposable_wordpress_woocommerce_innodb',
		'actors_verified'           => 2,
		'isolation_checks_verified' => count( $checks ),
		'account_list_isolated'     => true,
		'reveal_idor_denied'        => true,
		'owner_reveal_allowed'      => true,
		'registered_order_isolated' => true,
		'synthetic_users_left'      => $remaining,
		'database_rolled_back'      => $rolled_back,
		'database_state_unchanged'  => $before === $after,
		'sensitive_output'          => false,
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
