<?php
/**
 * Runs one guarded concurrency contender for the F06/F07 release verifier.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

use Dreamax\LicenseManager\Activations\ActivationService;
use Dreamax\LicenseManager\Integrations\WooCommerce\OrderLicensing;
use Dreamax\LicenseManager\Licenses\LicenseException;
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
if ( ! is_plugin_active( 'dreamax-license-manager/dreamax-license-manager.php' ) ) {
	exit( 2 );
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Sensitive synthetic input is received through the private parent/child pipe, never an argument or file.
$raw_input = file_get_contents( 'php://stdin' );
$input     = is_string( $raw_input ) ? json_decode( $raw_input, true ) : null;
if ( ! is_array( $input ) ) {
	exit( 2 );
}

$worker_mode = (string) ( $input['mode'] ?? '' );
$ready_file  = (string) ( $input['ready_file'] ?? '' );
$go_file     = (string) ( $input['go_file'] ?? '' );
$barrier     = realpath( dirname( $ready_file ) );
$temp_root   = realpath( sys_get_temp_dir() );
if ( false === $barrier
	|| false === $temp_root
	|| ! str_starts_with( str_replace( '\\', '/', $barrier ) . '/', rtrim( str_replace( '\\', '/', $temp_root ), '/' ) . '/' )
	|| ! str_starts_with( basename( $barrier ), 'dreamax-lm-f06-f07-' )
	|| dirname( $go_file ) !== dirname( $ready_file ) ) {
	exit( 2 );
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- The owned ready marker contains no data and coordinates two local workers only.
if ( false === file_put_contents( $ready_file, 'ready' ) ) {
	exit( 2 );
}

$deadline = microtime( true ) + 15.0;
while ( ! is_file( $go_file ) && microtime( true ) < $deadline ) {
	usleep( 10000 );
}
if ( ! is_file( $go_file ) ) {
	exit( 2 );
}

$result = array( 'status' => 'worker_error' );
try {
	if ( 'pool' === $worker_mode ) {
		$order_id   = (int) ( $input['order_id'] ?? 0 );
		$request_id = (string) ( $input['request_id'] ?? '' );
		if ( $order_id < 1 || ! preg_match( '/^f06-[ab]-[a-f0-9]{16}$/D', $request_id ) ) {
			exit( 2 );
		}
		$allocation = ( new OrderLicensing() )->allocate_missing( $order_id, false, 'woocommerce', null, $request_id );
		$result     = array(
			'status'    => 'success',
			'allocated' => $allocation['allocated'],
			'existing'  => $allocation['existing'],
			'failed'    => $allocation['failed'],
			'items'     => $allocation['items'],
		);
	} elseif ( 'activation' === $worker_mode ) {
		$key        = (string) ( $input['key'] ?? '' );
		$product_id = (string) ( $input['product_public_id'] ?? '' );
		$instance   = (string) ( $input['instance_id'] ?? '' );
		$request_id = (string) ( $input['request_id'] ?? '' );
		try {
			$activation = ( new ActivationService() )->activate( $key, $product_id, $instance, null, $request_id );
			$result     = array(
				'status'   => 'success',
				'replayed' => (bool) $activation['replayed'],
			);
		} catch ( LicenseException $error ) {
			$result = array(
				'status'       => 'domain_failure',
				'machine_code' => $error->machine_code(),
			);
		}
		if ( '' !== $key && function_exists( 'sodium_memzero' ) ) {
			sodium_memzero( $key );
		}
	}
} catch ( Throwable $error ) {
	$result = array( 'status' => 'worker_error' );
}

echo wp_json_encode( $result, JSON_UNESCAPED_SLASHES ) . PHP_EOL;
exit( 'worker_error' === $result['status'] ? 1 : 0 );
