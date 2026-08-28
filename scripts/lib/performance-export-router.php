<?php
/**
 * Provides an ephemeral loopback CSV-export boundary for the F24 verifier.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

use Dreamax\LicenseManager\ReleaseTools\DisposableEnvironmentGuard;

require_once __DIR__ . '/release-tools.php';

// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Compared only with fixed allowlisted paths.
$request_path = (string) parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH );

if ( '/health' === $request_path ) {
	http_response_code( 204 );
	exit;
}

$wp_root = realpath( (string) getenv( 'DREAMAX_LM_F24_WP_ROOT' ) );
$marker  = (string) getenv( 'DREAMAX_LM_F24_MARKER' );
if ( false === $wp_root || ! is_file( $wp_root . '/wp-load.php' ) ) {
	http_response_code( 503 );
	exit;
}
if ( ! defined( 'DISABLE_WP_CRON' ) ) {
	define( 'DISABLE_WP_CRON', true );
}
if ( ! defined( 'WP_USE_THEMES' ) ) {
	define( 'WP_USE_THEMES', false );
}
if ( ! defined( 'WP_ADMIN' ) ) {
	define( 'WP_ADMIN', true );
}

require_once $wp_root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
if ( ! defined( 'DB_NAME' ) ) {
	http_response_code( 503 );
	exit;
}
DisposableEnvironmentGuard::assertSafe(
	array(
		'marker'   => $marker,
		'database' => (string) DB_NAME,
		'site_url' => home_url( '/' ),
	)
);
if ( ! is_plugin_active( 'dreamax-license-manager/dreamax-license-manager.php' ) ) {
	http_response_code( 503 );
	exit;
}

// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Compared byte-exact with POST.
if ( '/admin-post.php' !== $request_path || 'POST' !== (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
	http_response_code( 404 );
	exit;
}

add_filter( 'woocommerce_prevent_admin_access', '__return_false', PHP_INT_MAX );
if ( function_exists( 'memory_reset_peak_usage' ) ) {
	memory_reset_peak_usage();
}
ob_start(
	static function ( string $body ): string {
		if ( ! headers_sent() ) {
			header( 'X-Dreamax-F24-Peak-Memory: ' . memory_get_peak_usage( true ) );
		}
		return $body;
	}
);

require ABSPATH . 'wp-admin/admin-post.php';
