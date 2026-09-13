<?php
/**
 * Verifies the standalone customer dashboard against a sanitized release fixture.
 *
 * @package DreamaxLicenseManager
 */

use Dreamax\LicenseManager\Activations\ActivationService;
use Dreamax\LicenseManager\CustomerPortal\LicenseDashboard;
use Dreamax\LicenseManager\Licenses\LicenseRepository;
use Dreamax\LicenseManager\ReleaseTools\DisposableEnvironmentGuard;

require_once __DIR__ . '/lib/release-tools.php';

/**
 * Stops with a fixed privacy-safe message.
 *
 * @param string $message Sanitized failure message.
 */
function dreamax_lm_dashboard_fail( string $message ): never {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI verifier emits only sanitized results.
	fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
	exit( 1 );
}

$options = getopt( '', array( 'environment-marker:', 'wp-root:' ) );
$marker  = (string) ( $options['environment-marker'] ?? '' );
$wp_root = realpath( (string) ( $options['wp-root'] ?? '' ) );
if ( false === $wp_root || ! is_file( $wp_root . '/wp-load.php' ) ) {
	dreamax_lm_dashboard_fail( 'A valid disposable WordPress root is required.' );
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
	dreamax_lm_dashboard_fail( 'The disposable WooCommerce runtime is unavailable.' );
}

global $wpdb;
$fixture = get_option( 'dreamax_lm_rc_fixture', null );
if ( ! is_array( $fixture ) ) {
	dreamax_lm_dashboard_fail( 'Prepare the guarded release fixture first.' );
}
$owner_id = (int) ( $fixture['user_id'] ?? 0 );
$license  = ( new LicenseRepository() )->by_public_id(
	(string) $wpdb->get_var( $wpdb->prepare( 'SELECT public_id FROM %i WHERE id=%d', $wpdb->prefix . 'dreamax_lm_licenses', (int) ( $fixture['license_id'] ?? 0 ) ) ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads only the exact fixture ID.
);
if ( $owner_id < 1 || ! get_user_by( 'id', $owner_id ) || ! is_array( $license ) || $owner_id !== (int) $license['customer_id'] ) {
	dreamax_lm_dashboard_fail( 'The release fixture ownership is invalid.' );
}
$page_id     = absint( get_option( 'dreamax_lm_customer_portal_page_id', 0 ) );
$portal_page = get_post( $page_id );
if ( ! $portal_page instanceof WP_Post || 'publish' !== $portal_page->post_status || ! has_shortcode( $portal_page->post_content, 'dreamax_license_dashboard' ) ) {
	dreamax_lm_dashboard_fail( 'The standalone portal page is not published and connected.' );
}

$counts      = static function () use ( $wpdb ): array {
	return array(
		'users'       => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->users ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact rollback baseline.
		'activations' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . 'dreamax_lm_activations' ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact rollback baseline.
		'events'      => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . 'dreamax_lm_events' ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact rollback baseline.
	);
};
$license_id  = (int) $license['id'];
$stale_users = $wpdb->get_col(
	$wpdb->prepare(
		'SELECT ID FROM %i WHERE user_login LIKE %s AND user_email LIKE %s',
		$wpdb->users,
		$wpdb->esc_like( 'dlm_dashboard_' ) . '%',
		'%@example.invalid'
	)
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Removes only verifier-owned disposable users.
$wpdb->query(
	$wpdb->prepare(
		'DELETE FROM %i WHERE license_id=%d AND request_id LIKE %s',
		$wpdb->prefix . 'dreamax_lm_events',
		$license_id,
		$wpdb->esc_like( 'dashboard:' ) . '%'
	)
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Removes only verifier-owned fixture events.
$wpdb->query(
	$wpdb->prepare(
		'DELETE FROM %i WHERE license_id=%d AND instance_label LIKE %s',
		$wpdb->prefix . 'dreamax_lm_activations',
		$license_id,
		$wpdb->esc_like( 'Release verifier installation' ) . '%'
	)
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Removes only verifier-owned fixture activations.
foreach ( $stale_users as $stale_user_id ) {
	wp_delete_user( (int) $stale_user_id );
}

$before        = $counts();
$previous_user = get_current_user_id();
$attacker_id   = 0;
$checks        = array();
$failure       = null;
$cleanup_ok    = false;
$request_id    = 'dashboard:' . wp_generate_uuid4();
$activation_id = '';
$key           = ( new LicenseRepository() )->decrypt_key( $license );

try {
	$login    = 'dlm_dashboard_' . substr( hash( 'sha256', random_bytes( 32 ) ), 0, 12 );
	$attacker = wp_insert_user(
		array(
			'user_login' => $login,
			'user_pass'  => bin2hex( random_bytes( 32 ) ),
			'user_email' => $login . '@example.invalid',
			'role'       => 'customer',
		)
	);
	if ( is_wp_error( $attacker ) ) {
		throw new RuntimeException( 'The isolated customer could not be created.' );
	}
	$attacker_id   = (int) $attacker;
	$instance      = 'dashboard-instance-' . substr( hash( 'sha256', random_bytes( 32 ) ), 0, 20 );
	$label         = 'Release verifier installation ' . substr( hash( 'sha256', $request_id ), 0, 8 );
	$activation    = ( new ActivationService() )->activate( $key, (string) $license['product_public_id'], $instance, $label, $request_id, 'customer', $owner_id );
	$activation_id = (string) ( $activation['activation_public_id'] ?? '' );
	if ( '' === $activation_id ) {
		throw new RuntimeException( 'The dashboard activation could not be tracked.' );
	}

	$dashboard = new LicenseDashboard();
	wp_set_current_user( 0 );
	$guest_html                 = $dashboard->shortcode();
	$checks['guest_login_only'] = str_contains( $guest_html, 'Sign in to view your licenses' ) && ! str_contains( $guest_html, (string) $license['public_id'] ) && ! str_contains( $guest_html, $key );

	wp_set_current_user( $owner_id );
	$owner_html                    = $dashboard->shortcode();
	$checks['standalone_shell']    = str_contains( $owner_html, 'data-dreamax-license-dashboard' ) && str_contains( $owner_html, 'License Portal' ) && str_contains( $owner_html, 'data-dreamax-dashboard-target="licenses"' );
	$checks['owner_masked']        = str_contains( $owner_html, (string) $license['public_id'] ) && str_contains( $owner_html, 'dreamax-lm-key' ) && ! str_contains( $owner_html, $key );
	$checks['activation_visible']  = str_contains( $owner_html, $label ) && str_contains( $owner_html, 'dreamax_lm_customer_deactivate' );
	$checks['assets_enqueued']     = wp_style_is( 'dreamax-lm-dashboard', 'enqueued' ) && wp_script_is( 'dreamax-lm-account', 'enqueued' );
	$checks['claim_forms_guarded'] = str_contains( $owner_html, 'dreamax_lm_guest_claim_issue' ) && str_contains( $owner_html, 'dreamax_lm_guest_claim_verify' );

	wp_set_current_user( $attacker_id );
	$attacker_html               = $dashboard->shortcode();
	$checks['attacker_isolated'] = str_contains( $attacker_html, 'No licenses connected yet' ) && ! str_contains( $attacker_html, (string) $license['public_id'] ) && ! str_contains( $attacker_html, $key ) && ! str_contains( $attacker_html, $label );
	if ( in_array( false, $checks, true ) ) {
		throw new RuntimeException( 'A standalone dashboard contract failed.' );
	}
} catch ( Throwable $error ) {
	$failure = $error;
} finally {
	wp_set_current_user( 0 );
	$event_cleanup      = $wpdb->delete(
		$wpdb->prefix . 'dreamax_lm_events',
		array(
			'license_id' => $license_id,
			'request_id' => $request_id,
		),
		array( '%d', '%s' )
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Deletes the exact verifier event.
	$activation_cleanup = '' === $activation_id ? 0 : $wpdb->delete(
		$wpdb->prefix . 'dreamax_lm_activations',
		array(
			'license_id' => $license_id,
			'public_id'  => $activation_id,
		),
		array( '%d', '%s' )
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Deletes the exact verifier activation.
	$user_cleanup       = $attacker_id < 1 || (bool) wp_delete_user( $attacker_id );
	$cleanup_ok         = false !== $event_cleanup && false !== $activation_cleanup && $user_cleanup;
	if ( $attacker_id > 0 ) {
		clean_user_cache( $attacker_id );
	}
	wp_set_current_user( $previous_user );
	if ( function_exists( 'sodium_memzero' ) ) {
		sodium_memzero( $key );
	}
	unset( $guest_html, $owner_html, $attacker_html );
}

$after = $counts();
if ( ! $cleanup_ok || $before !== $after || $failure instanceof Throwable ) {
	dreamax_lm_dashboard_fail( $failure instanceof Throwable ? $failure->getMessage() : 'The standalone dashboard state was not restored.' );
}
echo wp_json_encode(
	array(
		'classification'       => 'live_disposable_standalone_customer_dashboard',
		'portal_connected'     => true,
		'guest_login_required' => true,
		'owner_masked'         => true,
		'attacker_isolated'    => true,
		'activation_visible'   => true,
		'assets_enqueued'      => true,
		'database_restored'    => true,
		'sensitive_output'     => false,
	)
) . PHP_EOL;
