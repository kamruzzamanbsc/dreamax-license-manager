<?php
/**
 * Runs one guarded guest-claim verification contender for F25.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

use Dreamax\LicenseManager\CustomerPortal\GuestClaimService;
use Dreamax\LicenseManager\ReleaseTools\DisposableEnvironmentGuard;

require_once dirname( __DIR__ ) . '/lib/release-tools.php';

$options = getopt( '', array( 'environment-marker:', 'wp-root:' ) );
$marker  = (string) ( $options['environment-marker'] ?? '' );
$wp_root = realpath( (string) ( $options['wp-root'] ?? '' ) );

if ( false === $wp_root || ! is_file( $wp_root . '/wp-load.php' ) ) {
	exit( 2 );
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
	exit( 2 );
}
DisposableEnvironmentGuard::assertSafe(
	array(
		'marker'   => $marker,
		'database' => (string) DB_NAME,
		'site_url' => home_url( '/' ),
	)
);
if ( ! is_plugin_active( 'dreamax-license-manager/dreamax-license-manager.php' ) || ! function_exists( 'WC' ) ) {
	exit( 2 );
}
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounds only this verifier worker's lock wait.
$wpdb->query( 'SET SESSION innodb_lock_wait_timeout=10' );

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- The one-time proof travels only through the private parent/child pipe.
$raw_input = file_get_contents( 'php://stdin' );
$input     = is_string( $raw_input ) ? json_decode( $raw_input, true ) : null;
if ( ! is_array( $input ) ) {
	exit( 2 );
}

$user_id    = (int) ( $input['user_id'] ?? 0 );
$order_id   = (int) ( $input['order_id'] ?? 0 );
$proof      = (string) ( $input['proof'] ?? '' );
$ready_file = (string) ( $input['ready_file'] ?? '' );
$go_file    = (string) ( $input['go_file'] ?? '' );
$barrier    = realpath( dirname( $ready_file ) );
$temp_root  = realpath( sys_get_temp_dir() );
if ( $user_id < 1
	|| $order_id < 1
	|| 1 !== preg_match( '/^[A-Za-z0-9_-]{43}$/D', $proof )
	|| false === $barrier
	|| false === $temp_root
	|| ! str_starts_with( str_replace( '\\', '/', $barrier ) . '/', rtrim( str_replace( '\\', '/', $temp_root ), '/' ) . '/' )
	|| ! str_starts_with( basename( $barrier ), 'dreamax-lm-f25-' )
	|| dirname( $go_file ) !== dirname( $ready_file ) ) {
	exit( 2 );
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- The owned marker contains no data.
if ( false === file_put_contents( $ready_file, 'ready' ) ) {
	exit( 2 );
}
$deadline = microtime( true ) + 20.0;
while ( ! is_file( $go_file ) && microtime( true ) < $deadline ) {
	usleep( 10000 );
}
if ( ! is_file( $go_file ) ) {
	exit( 2 );
}

$result = array(
	'status'  => 'worker_error',
	'success' => false,
	'code'    => 'worker_error',
);
try {
	$verification = ( new GuestClaimService() )->verify( $user_id, $order_id, $proof );
	$result       = array(
		'status'  => 'completed',
		'success' => true === ( $verification['success'] ?? false ),
		'code'    => (string) ( $verification['code'] ?? '' ),
	);
} catch ( Throwable $error ) {
	unset( $error );
}
if ( function_exists( 'sodium_memzero' ) ) {
	sodium_memzero( $proof );
}

echo wp_json_encode( $result, JSON_UNESCAPED_SLASHES ) . PHP_EOL;
exit( 'completed' === $result['status'] ? 0 : 1 );
