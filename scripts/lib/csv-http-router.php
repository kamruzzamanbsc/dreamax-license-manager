<?php
/**
 * Provides an ephemeral loopback-only HTTP boundary for the F19 verifier.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

use Dreamax\LicenseManager\ReleaseTools\DisposableEnvironmentGuard;

require_once __DIR__ . '/release-tools.php';

header( 'X-Dreamax-F19-Router-Stage: start' );
register_shutdown_function(
	static function (): void {
		$error = error_get_last();
		if ( ! is_array( $error ) || ! in_array( (int) ( $error['type'] ?? 0 ), array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {
			return;
		}
		$message  = (string) ( $error['message'] ?? '' );
		$category = 'other';
		if ( str_contains( $message, 'Cannot redeclare' ) ) {
			$category = 'redeclare';
		} elseif ( str_contains( $message, 'Failed opening required' ) ) {
			$category = 'required';
		} elseif ( str_contains( $message, 'Class' ) && str_contains( $message, 'not found' ) ) {
			$category = 'class';
		} elseif ( str_contains( $message, 'undefined function' ) || str_contains( $message, 'undefined method' ) ) {
			$category = 'undefined';
		} elseif ( str_contains( $message, 'memory' ) ) {
			$category = 'memory';
		}
		if ( ! headers_sent() ) {
			header( 'X-Dreamax-F19-Fatal: ' . $category );
		}
	}
);

// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- This pre-WordPress router only compares the path to two fixed local allowlist values.
$request_path = (string) parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH );
if ( '/health' === $request_path ) {
	http_response_code( 204 );
	exit;
}
// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- The pre-WordPress method is compared byte-exact to POST and never rendered.
if ( '/admin-post.php' !== $request_path || 'POST' !== (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
	http_response_code( 404 );
	exit;
}

$wp_root = realpath( (string) getenv( 'DREAMAX_LM_F19_WP_ROOT' ) );
$marker  = (string) getenv( 'DREAMAX_LM_F19_MARKER' );
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
header( 'X-Dreamax-F19-Router-Stage: wp-loaded' );
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

add_filter( 'woocommerce_prevent_admin_access', '__return_false', PHP_INT_MAX );

header( 'X-Dreamax-F19-Router-Stage: admin-post' );

if ( function_exists( 'memory_reset_peak_usage' ) ) {
	memory_reset_peak_usage();
}
ob_start(
	static function ( string $body ): string {
		if ( ! headers_sent() ) {
			header( 'X-Dreamax-F19-Peak-Memory: ' . memory_get_peak_usage( true ) );
		}
		return $body;
	}
);

require ABSPATH . 'wp-admin/admin-post.php';
