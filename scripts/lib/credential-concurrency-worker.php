<?php
/**
 * Runs one bounded F31 credential-concurrency action.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

use Dreamax\LicenseManager\Credentials\CredentialService;
use Dreamax\LicenseManager\ReleaseTools\DisposableEnvironmentGuard;

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';
require_once __DIR__ . '/release-tools.php';

/**
 * Emits one fixed, sanitized worker result.
 *
 * @param string $status Result status.
 */
function dreamax_lm_f31_worker_result( string $status ): never {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Must also work before WordPress loads.
	echo json_encode(
		array(
			'status'           => $status,
			'sensitive_output' => false,
		),
		JSON_UNESCAPED_SLASHES
	) . PHP_EOL;
	exit( 0 );
}

/**
 * Validates an exact private barrier path.
 *
 * @param string $path Barrier path.
 * @throws RuntimeException When the path is outside the private temp root.
 */
function dreamax_lm_f31_worker_barrier( string $path ): string {
	$temp = realpath( sys_get_temp_dir() );
	$dir  = realpath( dirname( $path ) );
	if ( false === $temp || false === $dir || $dir !== $temp || ! str_starts_with( basename( $path ), 'dreamax-lm-f31-' ) ) {
		throw new RuntimeException( 'The worker barrier was not safely scoped.' );
	}
	return $path;
}

/**
 * Waits for one bounded private barrier.
 *
 * @param string $ready Ready path.
 * @param string $go Go path.
 * @throws RuntimeException When the bounded barrier cannot be completed.
 */
function dreamax_lm_f31_worker_wait( string $ready, string $go ): void {
	$ready = dreamax_lm_f31_worker_barrier( $ready );
	$go    = dreamax_lm_f31_worker_barrier( $go );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Owned empty barrier marker only.
	if ( false === file_put_contents( $ready, 'ready', LOCK_EX ) ) {
		throw new RuntimeException( 'The worker could not signal readiness.' );
	}
	$deadline = microtime( true ) + 20.0;
	while ( ! is_file( $go ) && microtime( true ) < $deadline ) {
		usleep( 20000 );
	}
	if ( ! is_file( $go ) ) {
		throw new RuntimeException( 'The worker barrier timed out.' );
	}
}

$options = getopt( '', array( 'environment-marker:', 'wp-root:' ) );
$marker  = (string) ( $options['environment-marker'] ?? '' );
$wp_root = realpath( (string) ( $options['wp-root'] ?? '' ) );
if ( false === $wp_root || ! is_file( $wp_root . '/wp-load.php' ) ) {
	dreamax_lm_f31_worker_result( 'worker_error' );
}

if ( ! defined( 'DISABLE_WP_CRON' ) ) {
	define( 'DISABLE_WP_CRON', true );
}
if ( ! defined( 'WP_USE_THEMES' ) ) {
	define( 'WP_USE_THEMES', false );
}
require_once $wp_root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

try {
	if ( ! defined( 'DB_NAME' ) ) {
		throw new RuntimeException( 'The database identity was unavailable.' );
	}
	DisposableEnvironmentGuard::assertSafe(
		array(
			'marker'   => $marker,
			'database' => (string) DB_NAME,
			'site_url' => home_url( '/' ),
		)
	);
	if ( ! is_plugin_active( 'dreamax-license-manager/dreamax-license-manager.php' ) ) {
		throw new RuntimeException( 'Dreamax License Manager was inactive.' );
	}

	$input = stream_get_contents( STDIN );
	$data  = is_string( $input ) ? json_decode( $input, true ) : null;
	if ( ! is_array( $data ) ) {
		throw new RuntimeException( 'The worker input was invalid.' );
	}
	$worker_mode = (string) ( $data['mode'] ?? '' );
	$public_id   = (string) ( $data['public_id'] ?? '' );
	$version     = (int) ( $data['version'] ?? 0 );
	$actor_id    = (int) ( $data['actor_id'] ?? 0 );
	if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{22}$/D', $public_id ) || $version < 1 || $actor_id < 1 ) {
		throw new RuntimeException( 'The worker credential reference was invalid.' );
	}
	wp_set_current_user( $actor_id );

	$error_log_before = ini_get( 'error_log' );
	// phpcs:ignore WordPress.PHP.IniSet.Risky -- Prevents private runtime detail from reaching a worker sink.
	ini_set( 'error_log', 'NUL' );
	$service = new CredentialService();
	try {
		if ( 'rotate_barrier' === $worker_mode ) {
			dreamax_lm_f31_worker_wait( (string) ( $data['ready'] ?? '' ), (string) ( $data['go'] ?? '' ) );
			$result = $service->rotate( $public_id, $version );
			if ( function_exists( 'sodium_memzero' ) ) {
				sodium_memzero( $result['credential'] );
			}
			dreamax_lm_f31_worker_result( 'rotated' );
		}
		if ( 'rotate' === $worker_mode ) {
			$result = $service->rotate( $public_id, $version );
			if ( function_exists( 'sodium_memzero' ) ) {
				sodium_memzero( $result['credential'] );
			}
			dreamax_lm_f31_worker_result( 'rotated' );
		}
		if ( 'revoke' === $worker_mode ) {
			$changed = $service->revoke( $public_id );
			dreamax_lm_f31_worker_result( $changed ? 'revoked' : 'unchanged' );
		}
		if ( 'use_hold' === $worker_mode ) {
			$credential = $data['credential'] ?? null;
			if ( ! is_array( $credential ) || (string) ( $credential['public_id'] ?? '' ) !== $public_id ) {
				throw new RuntimeException( 'The safe worker credential was invalid.' );
			}
			$used = $service->authorized_use(
				$credential,
				'licenses:read',
				'req_' . rtrim( strtr( base64_encode( random_bytes( 17 ) ), '+/', '-_' ), '=' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Synthetic opaque request identity.
				static function () use ( $data ): bool {
					dreamax_lm_f31_worker_wait( (string) ( $data['ready'] ?? '' ), (string) ( $data['go'] ?? '' ) );
					return true;
				}
			);
			dreamax_lm_f31_worker_result( true === $used ? 'used' : 'worker_error' );
		}
		throw new RuntimeException( 'The worker mode was invalid.' );
	} finally {
		if ( false !== $error_log_before ) {
			// phpcs:ignore WordPress.PHP.IniSet.Risky -- Restores the exact worker logging destination.
			ini_set( 'error_log', (string) $error_log_before );
		}
	}
} catch ( Throwable ) {
	dreamax_lm_f31_worker_result( 'rejected' );
}
