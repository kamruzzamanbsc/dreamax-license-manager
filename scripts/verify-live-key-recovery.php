<?php
/**
 * Verifies missing and mismatched master-key recovery on a disposable clone.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

use Dreamax\LicenseManager\Api\PublicRoutes;
use Dreamax\LicenseManager\Encryption\Crypto;
use Dreamax\LicenseManager\Licenses\KeyNormalizer;
use Dreamax\LicenseManager\Licenses\LicenseRepository;
use Dreamax\LicenseManager\Licenses\LicenseService;
use Dreamax\LicenseManager\ReleaseTools\DisposableEnvironmentGuard;
use Dreamax\LicenseManager\Support\Health;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/lib/release-tools.php';

const DREAMAX_LM_F11_CLONE_PREFIX = 'dreamax-lm-f11-';
const DREAMAX_LM_F11_CONFIRMATION = 'DREAMAX_LM_F11_DISPOSABLE_CLONE_CONFIRMED';

/**
 * Stops with a sanitized failure.
 *
 * @param string $message Failure message.
 */
function dreamax_lm_f11_fail( string $message ): never {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI verifier failures must go to stderr without rendering sensitive context.
	fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
	exit( 1 );
}

/**
 * Returns the exact plugin-owned database state digest.
 *
 * @throws RuntimeException When a plugin table name is not safely scoped.
 */
function dreamax_lm_f11_database_digest(): string {
	global $wpdb;
	$prefix = $wpdb->prefix . 'dreamax_lm_';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Recovery acceptance requires fresh inspection of plugin-owned tables.
	$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) );
	$tables = is_array( $tables ) ? array_map( 'strval', $tables ) : array();
	sort( $tables );
	$state = array();
	foreach ( $tables as $table ) {
		if ( ! str_starts_with( $table, $prefix ) || 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $table ) ) {
			throw new RuntimeException( 'A plugin table name was not safely scoped.' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table is discovered from the exact active prefix and constrained to safe identifier characters.
		$rows            = $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY id", ARRAY_A );
		$state[ $table ] = is_array( $rows ) ? $rows : array();
	}
	$state['key_options'] = array(
		'master_key_id' => get_option( 'dreamax_lm_master_key_id', null ),
		'kdf_salt'      => get_option( 'dreamax_lm_kdf_salt', null ),
	);
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- The digest input is trusted in-process database state and is never deserialized.
	return hash( 'sha256', serialize( $state ) );
}

/**
 * Returns one existing encrypted license without exposing its values.
 *
 * @return array<string,mixed>
 * @throws RuntimeException When no encrypted disposable fixture exists.
 */
function dreamax_lm_f11_existing_license(): array {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The guarded verifier needs one existing encrypted row for recovery acceptance.
	$row = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}dreamax_lm_licenses ORDER BY id LIMIT 1", ARRAY_A );
	if ( ! is_array( $row ) || '' === (string) ( $row['key_ciphertext'] ?? '' ) ) {
		throw new RuntimeException( 'F11 requires an existing encrypted disposable license row.' );
	}
	return $row;
}

/**
 * Confirms that one operation throws without exposing its exception.
 *
 * @param callable():mixed $operation Operation expected to fail closed.
 */
function dreamax_lm_f11_blocked( callable $operation ): bool {
	try {
		$operation();
	} catch ( Throwable $error ) {
		return true;
	}
	return false;
}

/**
 * Writes one private worker result file.
 *
 * @param string              $path Result path inside the disposable clone.
 * @param array<string,mixed> $result Sanitized parent-consumed result.
 * @throws RuntimeException When the private result cannot be encoded or written.
 */
function dreamax_lm_f11_write_result( string $path, array $result ): void {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in every verifier process; JSON_THROW_ON_ERROR is required.
	$encoded = json_encode( $result, JSON_THROW_ON_ERROR );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- The standalone verifier writes only its exact private temporary result file.
	if ( strlen( $encoded ) !== file_put_contents( $path, $encoded, LOCK_EX ) ) {
		throw new RuntimeException( 'The private worker result could not be written.' );
	}
}

/**
 * Runs one isolated WordPress worker mode.
 *
 * @param array<string,mixed> $options Command options.
 * @throws RuntimeException When a private result cannot be written.
 */
function dreamax_lm_f11_worker( array $options ): never {
	$mode        = (string) ( $options['worker'] ?? '' );
	$marker      = (string) ( $options['environment-marker'] ?? '' );
	$wp_root     = realpath( (string) ( $options['wp-root'] ?? '' ) );
	$result_path = (string) ( $options['result-file'] ?? '' );
	if ( false === $wp_root || ! in_array( $mode, array( 'correct_before', 'missing', 'wrong', 'correct_after' ), true ) ) {
		dreamax_lm_f11_fail( 'The private worker arguments are invalid.' );
	}
	$result_parent = realpath( dirname( $result_path ) );
	$temp_parent   = realpath( sys_get_temp_dir() );
	if (
		false === $result_parent
		|| ( $result_parent !== $wp_root && $result_parent !== $temp_parent )
		|| ! str_starts_with( basename( $result_path ), '.dreamax-f11-result-' )
	) {
		dreamax_lm_f11_fail( 'The private worker result target is invalid.' );
	}
	if ( ! defined( 'DISABLE_WP_CRON' ) ) {
		define( 'DISABLE_WP_CRON', true );
	}
	if ( ! defined( 'WP_USE_THEMES' ) ) {
		define( 'WP_USE_THEMES', false );
	}
	// phpcs:disable -- The private worker must suppress boot diagnostics so paths or configuration context can never reach the parent process.
	ini_set( 'display_errors', '0' );
	ini_set( 'log_errors', '0' );
	error_reporting( E_ERROR | E_PARSE );
	// phpcs:enable
	$worker_stage = 'bootstrap';
	try {
		require_once $wp_root . '/wp-load.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$worker_stage = 'guard';
		DisposableEnvironmentGuard::assertSafe(
			array(
				'marker'   => $marker,
				'database' => defined( 'DB_NAME' ) ? (string) DB_NAME : '',
				'site_url' => (string) get_option( 'siteurl' ),
			)
		);
		if ( ! is_plugin_active( 'dreamax-license-manager/dreamax-license-manager.php' ) ) {
			throw new RuntimeException( 'The disposable plugin runtime is unavailable.' );
		}

		$worker_stage = 'baseline';
		$before       = dreamax_lm_f11_database_digest();
		$license      = dreamax_lm_f11_existing_license();
		$crypto       = new Crypto();
		$health       = ( new Health() )->test_encryption();
		$recovery     = in_array( $mode, array( 'missing', 'wrong' ), true );
		$result       = array(
			'worker_ok'            => true,
			'mode'                 => $mode,
			'database_digest'      => $before,
			'ready_expected'       => $recovery ? ! $crypto->ready() : $crypto->ready(),
			'health_expected'      => $recovery ? 'critical' === $health['status'] : 'good' === $health['status'],
			'preexisting_readable' => false,
			'create_blocked'       => false,
			'import_blocked'       => false,
			'assign_blocked'       => false,
			'reveal_blocked'       => false,
			'public_503'           => false,
			'database_unchanged'   => false,
		);

		$worker_stage = 'contracts';
		if ( ! $recovery ) {
			$result['preexisting_readable'] = '' !== ( new LicenseRepository() )->decrypt_key( $license );
		} else {
			$service                  = new LicenseService();
			$result['create_blocked'] = dreamax_lm_f11_blocked( static fn() => $service->create_generated( array() ) );
			$result['import_blocked'] = dreamax_lm_f11_blocked( static fn() => $service->import( 'F11-SYNTHETIC-NONSECRET', KeyNormalizer::IMPORTED, null, array() ) );
			$result['assign_blocked'] = dreamax_lm_f11_blocked( static fn() => $service->assign_pool( 'prd_AAAAAAAAAAAAAAAAAAAAAA', array() ) );
			$result['reveal_blocked'] = dreamax_lm_f11_blocked( static fn() => ( new LicenseRepository() )->decrypt_key( $license ) );

			$_SERVER['HTTPS']       = 'on';
			$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
			$request                = new WP_REST_Request( 'POST', '/dreamax-license-manager/v1/licenses/validate' );
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body(
				(string) wp_json_encode(
					array(
						'license_key'       => 'F11-SYNTHETIC-NONSECRET',
						'product_public_id' => 'prd_AAAAAAAAAAAAAAAAAAAAAA',
					)
				)
			);
			$response             = ( new PublicRoutes() )->validate( $request );
			$result['public_503'] = 503 === $response->get_status();
		}

		$result['database_unchanged'] = dreamax_lm_f11_database_digest() === $before;
		dreamax_lm_f11_write_result( $result_path, $result );
		exit( 0 );
	} catch ( Throwable $error ) {
		dreamax_lm_f11_write_result(
			$result_path,
			array(
				'worker_ok'     => false,
				'failure_stage' => $worker_stage,
			)
		);
		exit( 2 );
	}
}

/**
 * Copies a disposable WordPress tree without mutable uploads or caches.
 *
 * @param string $source Source WordPress root.
 * @param string $target Exact private temporary clone root.
 * @throws RuntimeException When the clone cannot be created safely.
 */
function dreamax_lm_f11_copy_tree( string $source, string $target ): void {
	$excluded = array(
		'wp-content/uploads',
		'wp-content/cache',
		'wp-content/upgrade',
		'wp-content/backups',
		'wp-content/ai1wm-backups',
	);
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);
	foreach ( $iterator as $item ) {
		$relative = str_replace( '\\', '/', substr( $item->getPathname(), strlen( $source ) + 1 ) );
		$skip     = false;
		foreach ( $excluded as $excluded_path ) {
			if ( $relative === $excluded_path || str_starts_with( $relative, $excluded_path . '/' ) ) {
				$skip = true;
				break;
			}
		}
		if ( $skip ) {
			continue;
		}
		if ( $item->isLink() ) {
			throw new RuntimeException( 'The disposable clone refused a symbolic link.' );
		}
		$destination = $target . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
		if ( $item->isDir() ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- The standalone verifier creates only its private temporary clone.
			if ( ! is_dir( $destination ) && ! mkdir( $destination, 0700, true ) && ! is_dir( $destination ) ) {
				throw new RuntimeException( 'A disposable clone directory could not be created.' );
			}
			continue;
		}
		$parent = dirname( $destination );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- The standalone verifier creates only its private temporary clone.
		if ( ! is_dir( $parent ) && ! mkdir( $parent, 0700, true ) && ! is_dir( $parent ) ) {
			throw new RuntimeException( 'A disposable clone parent could not be created.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- The verifier creates a private disposable clone without exposing file contents.
		if ( ! copy( $item->getPathname(), $destination ) ) {
			throw new RuntimeException( 'A disposable clone file could not be copied.' );
		}
	}
}

/**
 * Removes only one verified private temporary clone.
 *
 * @param string $target Exact clone root.
 * @throws RuntimeException When cleanup is ambiguous or incomplete.
 */
function dreamax_lm_f11_remove_tree( string $target ): void {
	$temp_real   = realpath( sys_get_temp_dir() );
	$temp_root   = rtrim( false === $temp_real ? '' : $temp_real, DIRECTORY_SEPARATOR );
	$target_real = realpath( $target );
	if (
		'' === $temp_root
		|| false === $target_real
		|| dirname( $target_real ) !== $temp_root
		|| ! str_starts_with( basename( $target_real ), DREAMAX_LM_F11_CLONE_PREFIX )
	) {
		throw new RuntimeException( 'Cleanup refused an ambiguous clone target.' );
	}
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $target_real, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iterator as $item ) {
		if ( $item->isLink() ) {
			throw new RuntimeException( 'Cleanup refused a symbolic link.' );
		}
		if ( $item->isDir() ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Exact guarded cleanup removes only the private verifier clone.
			if ( ! rmdir( $item->getPathname() ) ) {
				throw new RuntimeException( 'A disposable clone directory could not be removed.' );
			}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Exact guarded cleanup removes only the private verifier clone.
		} elseif ( ! unlink( $item->getPathname() ) ) {
			throw new RuntimeException( 'A disposable clone file could not be removed.' );
		}
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Exact guarded cleanup removes only the private verifier clone.
	if ( ! rmdir( $target_real ) ) {
		throw new RuntimeException( 'The disposable clone root could not be removed.' );
	}
}

/**
 * Replaces exactly one master-key definition in cloned configuration text.
 *
 * @param string $config Original cloned configuration.
 * @param string $mode Missing or wrong mode.
 * @throws RuntimeException When the key definition is not uniquely replaceable.
 */
function dreamax_lm_f11_config_for_mode( string $config, string $mode ): string {
	$pattern = '/^[ \t]*define\s*\(\s*([\'\"])DREAMAX_LICENSE_MANAGER_MASTER_KEY\1\s*,.*?\)\s*;[ \t]*\r?$/m';
	if ( 1 !== preg_match_all( $pattern, $config ) ) {
		throw new RuntimeException( 'The cloned master-key definition was not uniquely replaceable.' );
	}
	$replacement = 'missing' === $mode
		? '// Dreamax F11 verifier temporarily omits the master-key constant.'
		: "define( 'DREAMAX_LICENSE_MANAGER_MASTER_KEY', rtrim( strtr( base64_encode( str_repeat( chr( 165 ), 32 ) ), '+/', '-_' ), '=' ) );";
	$changed     = preg_replace( $pattern, $replacement, $config, 1, $count );
	if ( ! is_string( $changed ) || 1 !== $count ) {
		throw new RuntimeException( 'The cloned master-key mode could not be prepared.' );
	}
	return $changed;
}

/**
 * Runs one private worker and reads its private result file.
 *
 * @param string $mode Worker mode.
 * @param string $clone_root Clone root.
 * @param string $marker Disposable environment marker.
 * @param bool   $result_in_temp Whether to keep the private result outside the site root.
 * @param bool   $wrong_prepend Whether to define process-local synthetic wrong key material.
 * @return array<string,mixed>
 * @throws RuntimeException When the worker or its private result fails.
 */
function dreamax_lm_f11_run_worker( string $mode, string $clone_root, string $marker, bool $result_in_temp = false, bool $wrong_prepend = false ): array {
	$result_root = $result_in_temp ? sys_get_temp_dir() : $clone_root;
	$result_file = rtrim( $result_root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . '.dreamax-f11-result-' . $mode . '-' . bin2hex( random_bytes( 4 ) ) . '.json';
	$command     = array( PHP_BINARY );
	if ( $wrong_prepend ) {
		$command[] = '-d';
		$command[] = 'auto_prepend_file=' . __DIR__ . '/lib/key-recovery-wrong-prepend.php';
	}
	$command    = array_merge(
		$command,
		array(
			__FILE__,
			'--worker=' . $mode,
			'--wp-root=' . $clone_root,
			'--environment-marker=' . $marker,
			'--result-file=' . $result_file,
		)
	);
	$descriptor = array(
		0 => array( 'pipe', 'r' ),
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	);
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- The guarded CLI verifier requires isolated PHP workers without a shell.
	$process = proc_open( $command, $descriptor, $pipes, __DIR__ );
	if ( ! is_resource( $process ) ) {
		throw new RuntimeException( 'A private recovery worker could not be started.', 1 );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the verifier-owned worker pipes.
	fclose( $pipes[0] );
	$stdout = stream_get_contents( $pipes[1] );
	$stderr = stream_get_contents( $pipes[2] );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the verifier-owned worker pipes.
	fclose( $pipes[1] );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the verifier-owned worker pipes.
	fclose( $pipes[2] );
	$exit = proc_close( $process );
	if ( ! in_array( $exit, array( 0, 2 ), true ) ) {
		throw new RuntimeException( 'A private recovery worker returned an unexpected status.', 2 );
	}
	// Worker output is captured and discarded. Only the private result file is accepted as evidence.
	unset( $stdout, $stderr );
	if ( ! is_file( $result_file ) ) {
		throw new RuntimeException( 'A private recovery worker did not create its result.', 4 );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads only the exact private worker result.
	$encoded = file_get_contents( $result_file );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes only the exact private worker result after consumption.
	if ( false === $encoded || ! unlink( $result_file ) ) {
		throw new RuntimeException( 'A private recovery worker result could not be consumed.', 5 );
	}
	$result = json_decode( $encoded, true, 512, JSON_THROW_ON_ERROR );
	if ( ! is_array( $result ) ) {
		throw new RuntimeException( 'A private recovery worker result was invalid.', 6 );
	}
	return $result;
}

$options = getopt(
	'',
	array(
		'environment-marker:',
		'wp-root:',
		'confirm-clone:',
		'worker:',
		'result-file:',
		'diagnose-only',
		'diagnose-wrong',
	)
);
if ( isset( $options['worker'] ) ) {
	dreamax_lm_f11_worker( $options );
}

$marker       = (string) ( $options['environment-marker'] ?? '' );
$confirmation = (string) ( $options['confirm-clone'] ?? '' );
$wp_root      = realpath( (string) ( $options['wp-root'] ?? '' ) );
if ( false === $wp_root || ! is_file( $wp_root . '/wp-load.php' ) || ! is_file( $wp_root . '/wp-config.php' ) ) {
	dreamax_lm_f11_fail( 'A valid disposable WordPress root is required.' );
}
if ( isset( $options['diagnose-only'] ) ) {
	$diagnostic_stage = 'worker';
	try {
		$diagnosis        = dreamax_lm_f11_run_worker( 'correct_before', $wp_root, $marker, true );
		$diagnostic_stage = is_string( $diagnosis['failure_stage'] ?? null ) ? (string) $diagnosis['failure_stage'] : 'contracts';
		$passed           = true === ( $diagnosis['worker_ok'] ?? false )
			&& true === ( $diagnosis['ready_expected'] ?? false )
			&& true === ( $diagnosis['health_expected'] ?? false )
			&& true === ( $diagnosis['preexisting_readable'] ?? false )
			&& true === ( $diagnosis['database_unchanged'] ?? false );
	} catch ( Throwable $error ) {
		$passed           = false;
		$diagnostic_stage = 'runner_' . max( 0, (int) $error->getCode() );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Diagnosis mode does not load WordPress in the parent and emits only fixed sanitized fields.
	echo json_encode(
		array(
			'classification'     => 'sanitized_f11_read_only_diagnosis',
			'correct_key_ready'  => $passed,
			'database_unchanged' => $passed,
			'failure_stage'      => $passed ? 'none' : $diagnostic_stage,
			'sensitive_output'   => false,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . PHP_EOL;
	exit( $passed ? 0 : 1 );
}
if ( isset( $options['diagnose-wrong'] ) ) {
	try {
		$diagnosis = dreamax_lm_f11_run_worker( 'wrong', $wp_root, $marker, true, true );
		$expected  = array( 'worker_ok', 'ready_expected', 'health_expected', 'create_blocked', 'import_blocked', 'assign_blocked', 'reveal_blocked', 'public_503', 'database_unchanged' );
		$failed    = array();
		foreach ( $expected as $contract ) {
			if ( true !== ( $diagnosis[ $contract ] ?? false ) ) {
				$failed[] = $contract;
			}
		}
		if ( ! is_string( $diagnosis['database_digest'] ?? null ) || 64 !== strlen( (string) $diagnosis['database_digest'] ) ) {
			$failed[] = 'database_digest';
		}
	} catch ( Throwable $error ) {
		$failed = array( 'worker_runner' );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Diagnosis mode does not load WordPress in the parent and emits only fixed sanitized fields.
	echo json_encode(
		array(
			'classification'     => 'sanitized_f11_wrong_key_diagnosis',
			'failed_contracts'   => $failed,
			'database_unchanged' => ! in_array( 'database_unchanged', $failed, true ),
			'sensitive_output'   => false,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . PHP_EOL;
	exit( array() === $failed ? 0 : 1 );
}
if ( DREAMAX_LM_F11_CONFIRMATION !== $confirmation ) {
	dreamax_lm_f11_fail( 'The explicit disposable-clone confirmation is required.' );
}

$temp_root = realpath( sys_get_temp_dir() );
if ( false === $temp_root ) {
	dreamax_lm_f11_fail( 'The private temporary root is unavailable.' );
}
$clone_root = $temp_root . DIRECTORY_SEPARATOR . DREAMAX_LM_F11_CLONE_PREFIX . bin2hex( random_bytes( 8 ) );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creates only the exact private temporary clone root.
if ( file_exists( $clone_root ) || ! mkdir( $clone_root, 0700 ) ) {
	dreamax_lm_f11_fail( 'The private disposable clone root could not be created.' );
}

$failure           = null;
$failure_stage     = 'copy';
$cleanup_committed = false;
$checks            = array();
$failed_contracts  = array();
try {
	dreamax_lm_f11_copy_tree( $wp_root, $clone_root );
	$original_config      = $wp_root . '/wp-config.php';
	$cloned_config        = $clone_root . '/wp-config.php';
	$original_config_hash = hash_file( 'sha256', $original_config );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the cloned configuration only in memory; no value is logged or retained.
	$correct_config = file_get_contents( $cloned_config );
	if ( false === $original_config_hash || false === $correct_config ) {
		throw new RuntimeException( 'The disposable configuration baseline could not be verified.' );
	}

	$failure_stage            = 'correct_before';
	$before                   = dreamax_lm_f11_run_worker( 'correct_before', $clone_root, $marker );
	$checks['correct_before'] = true === ( $before['worker_ok'] ?? false )
		&& true === ( $before['ready_expected'] ?? false )
		&& true === ( $before['health_expected'] ?? false )
		&& true === ( $before['preexisting_readable'] ?? false )
		&& true === ( $before['database_unchanged'] ?? false );

	foreach ( array( 'missing', 'wrong' ) as $key_mode ) {
		$failure_stage = $key_mode . '_configuration';
		$mode_config   = dreamax_lm_f11_config_for_mode( $correct_config, $key_mode );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writes only the cloned wp-config.php and restores it before cleanup.
		if ( strlen( $mode_config ) !== file_put_contents( $cloned_config, $mode_config, LOCK_EX ) ) {
			throw new RuntimeException( 'A disposable recovery configuration could not be written.' );
		}
		$failure_stage       = $key_mode . '_worker';
		$result              = dreamax_lm_f11_run_worker( $key_mode, $clone_root, $marker );
		$checks[ $key_mode ] = true === ( $result['worker_ok'] ?? false )
			&& true === ( $result['ready_expected'] ?? false )
			&& true === ( $result['health_expected'] ?? false )
			&& true === ( $result['create_blocked'] ?? false )
			&& true === ( $result['import_blocked'] ?? false )
			&& true === ( $result['assign_blocked'] ?? false )
			&& true === ( $result['reveal_blocked'] ?? false )
			&& true === ( $result['public_503'] ?? false )
			&& true === ( $result['database_unchanged'] ?? false )
			&& ( $before['database_digest'] ?? null ) === ( $result['database_digest'] ?? null );
	}

	$failure_stage = 'correct_restore';
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Restores the exact original cloned configuration before the final worker.
	if ( strlen( $correct_config ) !== file_put_contents( $cloned_config, $correct_config, LOCK_EX ) ) {
		throw new RuntimeException( 'The correct disposable configuration could not be restored.' );
	}
	$after                               = dreamax_lm_f11_run_worker( 'correct_after', $clone_root, $marker );
	$checks['correct_after']             = true === ( $after['worker_ok'] ?? false )
		&& true === ( $after['ready_expected'] ?? false )
		&& true === ( $after['health_expected'] ?? false )
		&& true === ( $after['preexisting_readable'] ?? false )
		&& true === ( $after['database_unchanged'] ?? false )
		&& ( $before['database_digest'] ?? null ) === ( $after['database_digest'] ?? null );
	$checks['original_config_unchanged'] = hash_equals( $original_config_hash, (string) hash_file( 'sha256', $original_config ) );
	$checks['clone_config_restored']     = hash_equals( hash( 'sha256', $correct_config ), (string) hash_file( 'sha256', $cloned_config ) );
	if ( in_array( false, $checks, true ) ) {
		foreach ( $checks as $contract => $passed ) {
			if ( ! $passed ) {
				$failed_contracts[] = $contract;
			}
		}
		throw new RuntimeException( 'One or more F11 recovery contracts failed.' );
	}
} catch ( Throwable $error ) {
	$failure = $error;
} finally {
	try {
		dreamax_lm_f11_remove_tree( $clone_root );
		$cleanup_committed = ! file_exists( $clone_root );
	} catch ( Throwable $cleanup_error ) {
		$cleanup_committed = false;
	}
}

if ( $failure instanceof Throwable || ! $cleanup_committed ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Parent mode does not load WordPress and emits only fixed sanitized fields.
	echo json_encode(
		array(
			'classification'    => 'sanitized_f11_verification_failure',
			'failure_stage'     => $failure_stage,
			'failed_contracts'  => $failed_contracts,
			'cleanup_committed' => $cleanup_committed,
			'sensitive_output'  => false,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . PHP_EOL;
	dreamax_lm_f11_fail( 'The guarded F11 recovery verification failed.' );
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Parent mode does not load WordPress and emits only fixed sanitized fields.
echo json_encode(
	array(
		'classification'             => 'live_disposable_key_recovery_clone',
		'contracts_passed'           => count( $checks ),
		'correct_key_before_ready'   => $checks['correct_before'],
		'missing_key_recovery_safe'  => $checks['missing'],
		'wrong_key_recovery_safe'    => $checks['wrong'],
		'correct_key_restored_ready' => $checks['correct_after'],
		'original_config_unchanged'  => $checks['original_config_unchanged'],
		'clone_config_restored'      => $checks['clone_config_restored'],
		'database_unchanged'         => true,
		'clone_cleanup_committed'    => $cleanup_committed,
		'outbound_email_sent'        => false,
		'sensitive_output'           => false,
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
