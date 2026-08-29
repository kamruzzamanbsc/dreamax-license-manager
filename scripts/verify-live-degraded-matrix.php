<?php
/**
 * Verifies the operational degraded-mode matrix on a private disposable clone.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

use Dreamax\LicenseManager\Api\PublicRoutes;
use Dreamax\LicenseManager\ImportExport\CsvController;
use Dreamax\LicenseManager\Licenses\LicenseService;
use Dreamax\LicenseManager\ReleaseTools\DisposableEnvironmentGuard;
use Dreamax\LicenseManager\Support\Health;
use Dreamax\LicenseManager\Support\Requirements;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/lib/release-tools.php';

const DREAMAX_LM_F26_DATABASE_PREFIX = 'dreamax_lm_f26_test_';
const DREAMAX_LM_F26_CLONE_PREFIX    = 'dreamax-lm-f26-clone-';
const DREAMAX_LM_F26_CONFIRMATION    = 'I_CONFIRM_F26_PRIVATE_CLONE_FAULTS';

/**
 * Stops with a fixed sanitized error.
 *
 * @param string $message Sanitized message.
 */
function dreamax_lm_f26_fail( string $message ): never {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI verifier reports only fixed sanitized errors.
	fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
	exit( 1 );
}

/**
 * Confirms an exact verifier-owned database name.
 *
 * @param string $database Database name.
 * @throws RuntimeException When the name is outside the verifier scope.
 */
function dreamax_lm_f26_assert_database( string $database ): void {
	if ( 1 !== preg_match( '/^dreamax_lm_f26_test_[a-f0-9]{16}$/D', $database ) ) {
		throw new RuntimeException( 'A temporary database identifier was not safely scoped.' );
	}
}

/**
 * Lists constrained base tables.
 *
 * @param string $database Database name.
 * @return list<string>
 * @throws RuntimeException When tables cannot be inspected safely.
 */
function dreamax_lm_f26_tables( string $database ): array {
	global $wpdb;
	if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $database ) ) {
		throw new RuntimeException( 'A database identifier was unsafe.' );
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifier is strictly constrained above.
	$rows = $wpdb->get_results( "SHOW FULL TABLES FROM `{$database}`", ARRAY_N );
	if ( ! is_array( $rows ) || array() === $rows ) {
		throw new RuntimeException( 'The database had no inspectable tables.' );
	}
	$tables = array();
	foreach ( $rows as $row ) {
		$table = (string) ( $row[0] ?? '' );
		$type  = strtoupper( (string) ( $row[1] ?? '' ) );
		if ( 'BASE TABLE' !== $type || 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $table ) ) {
			throw new RuntimeException( 'The verifier refuses views or unsafe table identifiers.' );
		}
		$tables[] = $table;
	}
	sort( $tables, SORT_STRING );
	return array_values( array_unique( $tables ) );
}

/**
 * Returns a value-free complete database digest.
 *
 * @param string $database Database name.
 * @return array{digest:string,tables:int,rows:int}
 * @throws RuntimeException When a table cannot be digested safely.
 */
function dreamax_lm_f26_database_digest( string $database ): array {
	global $wpdb;
	$state      = array();
	$total_rows = 0;
	$tables     = dreamax_lm_f26_tables( $database );
	foreach ( $tables as $table ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Strict identifiers and checksums avoid retaining values.
		$create   = $wpdb->get_row( "SHOW CREATE TABLE `{$database}`.`{$table}`", ARRAY_N );
		$count    = $wpdb->get_var( "SELECT COUNT(*) FROM `{$database}`.`{$table}`" );
		$checksum = $wpdb->get_row( "CHECKSUM TABLE `{$database}`.`{$table}`", ARRAY_N );
		// phpcs:enable
		if ( ! is_array( $create ) || ! is_numeric( $count ) || ! is_array( $checksum ) || ! is_numeric( $checksum[1] ?? null ) ) {
			throw new RuntimeException( 'A database table could not be digested.' );
		}
		$schema = preg_replace( '/\sAUTO_INCREMENT=\d+/D', '', (string) ( $create[1] ?? '' ) );
		if ( ! is_string( $schema ) ) {
			throw new RuntimeException( 'A schema digest could not be normalized.' );
		}
		$row_count   = (int) $count;
		$total_rows += $row_count;
		$state[]     = array( $table, $schema, $row_count, (string) $checksum[1] );
	}
	$encoded = wp_json_encode( $state, JSON_UNESCAPED_SLASHES );
	if ( ! is_string( $encoded ) ) {
		throw new RuntimeException( 'The database digest could not be encoded.' );
	}
	return array(
		'digest' => hash( 'sha256', $encoded ),
		'tables' => count( $tables ),
		'rows'   => $total_rows,
	);
}

/**
 * Copies the disposable database to one exact verifier target.
 *
 * @param string $source Source database.
 * @param string $target Target database.
 * @throws RuntimeException When the database cannot be copied safely.
 */
function dreamax_lm_f26_copy_database( string $source, string $target ): void {
	global $wpdb;
	dreamax_lm_f26_assert_database( $target );
	if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $source ) ) {
		throw new RuntimeException( 'The source database identifier was unsafe.' );
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prevents overwrite of a pre-existing target.
	if ( null !== $wpdb->get_var( $wpdb->prepare( 'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME=%s', $target ) ) ) {
		throw new RuntimeException( 'A verifier-owned database target already exists.' );
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Exact random target is validated above.
	if ( false === $wpdb->query( "CREATE DATABASE `{$target}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" ) ) {
		throw new RuntimeException( 'A verifier-owned database could not be created.' );
	}
	foreach ( dreamax_lm_f26_tables( $source ) as $table ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Strict identifiers copy into a fresh private database.
		$created = $wpdb->query( "CREATE TABLE `{$target}`.`{$table}` LIKE `{$source}`.`{$table}`" );
		$copied  = $wpdb->query( "INSERT INTO `{$target}`.`{$table}` SELECT * FROM `{$source}`.`{$table}`" );
		// phpcs:enable
		if ( false === $created || false === $copied ) {
			throw new RuntimeException( 'The private fault database could not be copied.' );
		}
	}
}

/**
 * Drops only the exact verifier-owned database.
 *
 * @param string $database Database name.
 * @throws RuntimeException When guarded cleanup fails.
 */
function dreamax_lm_f26_drop_database( string $database ): void {
	global $wpdb;
	dreamax_lm_f26_assert_database( $database );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Exact target is validated above.
	if ( false === $wpdb->query( "DROP DATABASE IF EXISTS `{$database}`" ) ) {
		throw new RuntimeException( 'The verifier-owned database could not be removed.' );
	}
}

/**
 * Copies a tree without mutable customer content.
 *
 * @param string $source Source root.
 * @param string $target Clone root.
 * @throws RuntimeException When the tree cannot be copied safely.
 */
function dreamax_lm_f26_copy_tree( string $source, string $target ): void {
	$excluded = array( 'wp-content/uploads', 'wp-content/cache', 'wp-content/upgrade', 'wp-content/backups', 'wp-content/ai1wm-backups' );
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
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
			throw new RuntimeException( 'The private fault clone refused a symbolic link.' );
		}
		$destination = $target . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
		if ( $item->isDir() ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creates only inside the exact private clone.
			if ( ! is_dir( $destination ) && ! mkdir( $destination, 0700, true ) && ! is_dir( $destination ) ) {
				throw new RuntimeException( 'A private clone directory could not be created.' );
			}
			continue;
		}
		$parent = dirname( $destination );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creates only inside the exact private clone.
		if ( ! is_dir( $parent ) && ! mkdir( $parent, 0700, true ) && ! is_dir( $parent ) ) {
			throw new RuntimeException( 'A private clone parent could not be created.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Copies only into the private clone.
		if ( ! copy( $item->getPathname(), $destination ) ) {
			throw new RuntimeException( 'A private clone file could not be copied.' );
		}
	}
}

/**
 * Removes only a verified clone directly under system temporary storage.
 *
 * @param string $target Clone root.
 * @throws RuntimeException When the target is ambiguous or cleanup fails.
 */
function dreamax_lm_f26_remove_tree( string $target ): void {
	$temp_real   = realpath( sys_get_temp_dir() );
	$target_real = realpath( $target );
	if ( false === $temp_real || false === $target_real || dirname( $target_real ) !== rtrim( $temp_real, DIRECTORY_SEPARATOR ) || ! str_starts_with( basename( $target_real ), DREAMAX_LM_F26_CLONE_PREFIX ) ) {
		throw new RuntimeException( 'Cleanup refused an ambiguous private fault clone.' );
	}
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $target_real, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $iterator as $item ) {
		if ( $item->isLink() ) {
			throw new RuntimeException( 'Cleanup refused a symbolic link.' );
		}
		if ( $item->isDir() ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removes only an exact private clone directory.
			if ( ! rmdir( $item->getPathname() ) ) {
				throw new RuntimeException( 'A private clone directory could not be removed.' );
			}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes only an exact private clone file.
		} elseif ( ! unlink( $item->getPathname() ) ) {
			throw new RuntimeException( 'A private clone file could not be removed.' );
		}
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removes only the exact guarded clone root.
	if ( ! rmdir( $target_real ) ) {
		throw new RuntimeException( 'The private clone root could not be removed.' );
	}
}

/**
 * Selects the verifier database in cloned configuration text.
 *
 * @param string $config Configuration text.
 * @param string $database Database name.
 * @throws RuntimeException When the definition cannot be replaced exactly.
 */
function dreamax_lm_f26_config_for_database( string $config, string $database ): string {
	dreamax_lm_f26_assert_database( $database );
	$pattern = '/^[ \t]*define\s*\(\s*([\'\"])DB_NAME\1\s*,.*?\)\s*;[ \t]*\r?$/m';
	if ( 1 !== preg_match_all( $pattern, $config ) ) {
		throw new RuntimeException( 'The cloned database definition was not uniquely replaceable.' );
	}
	$changed = preg_replace( $pattern, "define( 'DB_NAME', '" . $database . "' );", $config, 1, $count );
	if ( ! is_string( $changed ) || 1 !== $count ) {
		throw new RuntimeException( 'The cloned fault database could not be selected.' );
	}
	return $changed;
}

/**
 * Adds the clone-only WooCommerce fault flag before WordPress boots.
 *
 * @param string $config Configuration text.
 * @throws RuntimeException When the bootstrap boundary is ambiguous.
 */
function dreamax_lm_f26_config_without_woocommerce( string $config ): string {
	$needle = "require_once ABSPATH . 'wp-settings.php';";
	if ( 1 !== substr_count( $config, $needle ) ) {
		throw new RuntimeException( 'The cloned bootstrap boundary was not unique.' );
	}
	return str_replace( $needle, "define( 'DREAMAX_LM_F26_DISABLE_WOO', true );\n" . $needle, $config );
}

/**
 * Renames exact plugin tables inside the verifier database.
 *
 * @param string $database Database name.
 * @param string $prefix WordPress prefix.
 * @param array  $suffixes Plugin table suffixes.
 * @phpstan-param list<string> $suffixes Plugin table suffixes.
 * @param bool   $restore Whether held tables should be restored.
 * @throws RuntimeException When exact table renaming fails.
 */
function dreamax_lm_f26_hold_tables( string $database, string $prefix, array $suffixes, bool $restore ): void {
	global $wpdb;
	dreamax_lm_f26_assert_database( $database );
	if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $prefix ) ) {
		throw new RuntimeException( 'The cloned table prefix was unsafe.' );
	}
	$pairs = array();
	foreach ( $suffixes as $suffix ) {
		if ( 1 !== preg_match( '/^[a-z_]+$/D', $suffix ) ) {
			throw new RuntimeException( 'A plugin table suffix was unsafe.' );
		}
		$table   = $prefix . 'dreamax_lm_' . $suffix;
		$held    = $table . '_f26_hold';
		$from    = $restore ? $held : $table;
		$to      = $restore ? $table : $held;
		$pairs[] = "`{$database}`.`{$from}` TO `{$database}`.`{$to}`";
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Every identifier is strictly constrained and points only to the random clone database.
	if ( false === $wpdb->query( 'RENAME TABLE ' . implode( ', ', $pairs ) ) ) {
		throw new RuntimeException( $restore ? 'Held plugin tables could not be restored.' : 'Plugin tables could not be faulted safely.' );
	}
}

/**
 * Writes one fixed worker result.
 *
 * @param string              $path Exact clone result path.
 * @param array<string,mixed> $result Sanitized result.
 */
function dreamax_lm_f26_write_result( string $path, array $result ): void {
	$encoded = wp_json_encode( $result, JSON_UNESCAPED_SLASHES );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writes only an exact private worker result.
	if ( ! is_string( $encoded ) || strlen( $encoded ) !== file_put_contents( $path, $encoded, LOCK_EX ) ) {
		exit( 2 );
	}
}

/**
 * Runs one fault contract inside the private clone.
 *
 * @param array<string,mixed> $options CLI options.
 * @throws RuntimeException When a clone-only contract cannot execute.
 */
function dreamax_lm_f26_worker( array $options ): never {
	$mode          = (string) ( $options['worker'] ?? '' );
	$marker        = (string) ( $options['environment-marker'] ?? '' );
	$wp_root       = realpath( (string) ( $options['wp-root'] ?? '' ) );
	$result_path   = (string) ( $options['result-file'] ?? '' );
	$allowed       = array( 'baseline', 'cron_missing', 'upload_missing', 'storage_missing', 'audit_missing', 'woo_missing' );
	$result_parent = realpath( dirname( $result_path ) );
	$temp_parent   = realpath( sys_get_temp_dir() );
	$result_scoped = false !== $result_parent && ( $result_parent === $wp_root || $result_parent === $temp_parent );
	if ( false === $wp_root || ! in_array( $mode, $allowed, true ) || ! $result_scoped || ! str_starts_with( basename( $result_path ), '.dreamax-f26-result-' ) ) {
		exit( 2 );
	}
	$result       = array(
		'worker_ok'        => false,
		'mode'             => $mode,
		'contracts'        => array(),
		'sensitive_output' => false,
	);
	$worker_stage = 'bootstrap';
	try {
		if ( ! defined( 'DISABLE_WP_CRON' ) ) {
			define( 'DISABLE_WP_CRON', true );
		}
		if ( ! defined( 'WP_USE_THEMES' ) ) {
			define( 'WP_USE_THEMES', false );
		}
		require_once $wp_root . '/wp-load.php';
		$worker_stage = 'environment_guard';
		DisposableEnvironmentGuard::assertSafe(
			array(
				'marker'   => $marker,
				'database' => defined( 'DB_NAME' ) ? (string) DB_NAME : '',
				'site_url' => (string) get_option( 'siteurl' ),
			)
		);
		global $wpdb;
		$wpdb->suppress_errors( true );
		$health       = new Health();
		$worker_stage = 'contracts';

		if ( 'baseline' === $mode ) {
			$result['contracts'] = array(
				'encryption_ready' => 'good' === $health->test_encryption()['status'],
				'storage_ready'    => 'good' === $health->test_storage()['status'],
				'cleanup_ready'    => 'good' === $health->test_cleanup()['status'],
				'dependency_ready' => ( new Requirements() )->satisfied(),
			);
		} elseif ( 'cron_missing' === $mode ) {
			$scheduled = wp_next_scheduled( 'dreamax_lm_cleanup' );
			wp_clear_scheduled_hook( 'dreamax_lm_cleanup' );
			$missing    = 'recommended' === $health->test_cleanup()['status'];
			$ping       = ( new PublicRoutes() )->ping();
			$created    = ( new LicenseService() )->create_generated(
				array(
					'product_public_id' => 'prd_F26CRONAAAAAAAAAAAAAAA',
					'actor_type'        => 'system',
					'source'            => 'generated',
				)
			);
			$license_id = (int) $created['id'];
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Removes only the exact clone-only synchronous fixture.
			$events_deleted  = $wpdb->delete( $wpdb->prefix . 'dreamax_lm_events', array( 'license_id' => $license_id ), array( '%d' ) );
			$license_deleted = $wpdb->delete( $wpdb->prefix . 'dreamax_lm_licenses', array( 'id' => $license_id ), array( '%d' ) );
			// phpcs:enable
			if ( false !== $scheduled ) {
				wp_schedule_event( max( time() + 60, (int) $scheduled ), 'hourly', 'dreamax_lm_cleanup' );
			}
			$result['contracts'] = array(
				'recommended_signal'      => $missing,
				'core_api_unaffected'     => 200 === $ping->get_status(),
				'synchronous_core_passed' => $license_id > 0,
				'exact_fixture_removed'   => 1 === $events_deleted && 1 === $license_deleted,
				'schedule_restored'       => false !== wp_next_scheduled( 'dreamax_lm_cleanup' ),
			);
		} elseif ( 'upload_missing' === $mode ) {
			$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_licenses" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$admins = get_users(
				array(
					'role'   => 'administrator',
					'fields' => 'ID',
					'number' => 1,
				)
			);
			if ( array() === $admins ) {
				throw new RuntimeException( 'The disposable clone had no administrator.' );
			}
			wp_set_current_user( (int) $admins[0] );
			$_POST['_wpnonce'] = wp_create_nonce( 'dreamax_lm_import_csv' );
			$_FILES['csv']     = array(
				'tmp_name' => '',
				'size'     => 0,
			);
			add_filter(
				'wp_die_handler',
				static fn(): callable => static function (): never {
					throw new RuntimeException( 'Expected atomic import rejection.' );
				}
			);
			$blocked = false;
			try {
				( new CsvController() )->import();
			} catch ( RuntimeException $error ) {
				$blocked = 'Expected atomic import rejection.' === $error->getMessage();
			}
			$after               = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_licenses" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$result['contracts'] = array(
				'import_blocked_atomically' => $blocked,
				'no_license_write'          => $before === $after,
				'core_api_unaffected'       => 200 === ( new PublicRoutes() )->ping()->get_status(),
			);
		} elseif ( 'storage_missing' === $mode ) {
			$_SERVER['HTTPS']       = 'on';
			$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
			$request                = new WP_REST_Request( 'POST', '/dreamax-license-manager/v1/licenses/validate' );
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body(
				(string) wp_json_encode(
					array(
						'license_key'       => 'F26-SYNTHETIC-NONSECRET',
						'product_public_id' => 'prd_F26STORAGEAAAAAAAAAAA',
					)
				)
			);
			$response = ( new PublicRoutes() )->validate( $request );
			$blocked  = false;
			try {
				( new LicenseService() )->create_generated( array( 'product_public_id' => 'prd_F26STORAGEAAAAAAAAAAA' ) );
			} catch ( Throwable $error ) {
				$blocked = true;
				unset( $error );
			}
			$result['contracts'] = array(
				'critical_storage_signal' => 'critical' === $health->test_storage()['status'],
				'ping_503'                => 503 === ( new PublicRoutes() )->ping()->get_status(),
				'public_read_503'         => 503 === $response->get_status(),
				'mutation_blocked'        => $blocked,
			);
		} elseif ( 'audit_missing' === $mode ) {
			$before  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_licenses" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$blocked = false;
			try {
				( new LicenseService() )->create_generated(
					array(
						'product_public_id' => 'prd_F26AUDITAAAAAAAAAAAAA',
						'actor_type'        => 'system',
						'source'            => 'generated',
					)
				);
			} catch ( Throwable $error ) {
				$blocked = true;
				unset( $error );
			}
			$after               = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_licenses" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$result['contracts'] = array(
				'critical_storage_signal'  => 'critical' === $health->test_storage()['status'],
				'audited_mutation_blocked' => $blocked,
				'transaction_rolled_back'  => $before === $after,
				'public_api_503'           => 503 === ( new PublicRoutes() )->ping()->get_status(),
			);
		} elseif ( 'woo_missing' === $mode ) {
			$admins = get_users(
				array(
					'role'   => 'administrator',
					'fields' => 'ID',
					'number' => 1,
				)
			);
			if ( array() === $admins ) {
				throw new RuntimeException( 'The disposable clone had no administrator.' );
			}
			wp_set_current_user( (int) $admins[0] );
			ob_start();
			do_action( 'admin_notices' );
			$notice = (string) ob_get_clean();
			do_action( 'rest_api_init' );
			$routes              = rest_get_server()->get_routes();
			$result['contracts'] = array(
				'woocommerce_absent'       => ! class_exists( 'WooCommerce' ),
				'dependency_failed_closed' => ! ( new Requirements() )->satisfied(),
				'dependency_notice'        => str_contains( $notice, 'Licensing features are paused; stored data has not been changed.' ),
				'public_routes_disabled'   => ! isset( $routes['/dreamax-license-manager/v1/system/ping'] ),
			);
		}
		$result['worker_ok'] = ! in_array( false, $result['contracts'], true );
		dreamax_lm_f26_write_result( $result_path, $result );
		exit( $result['worker_ok'] ? 0 : 2 );
	} catch ( Throwable $error ) {
		$message       = $error->getMessage();
		$error_file    = str_replace( '\\', '/', $error->getFile() );
		$file_category = match ( true ) {
			str_ends_with( $error_file, '/wp-config.php' ) => 'clone_config',
			str_ends_with( $error_file, '/dreamax-f26-woo-fault.php' ) => 'woo_fault_adapter',
			str_contains( $error_file, '/wp-content/plugins/dreamax-license-manager/' ) => 'plugin_runtime',
			str_contains( $error_file, '/wp-content/plugins/woocommerce/' ) => 'woocommerce_runtime',
			default => 'wordpress_runtime',
		};
		$category = match ( true ) {
			str_contains( $message, 'already in use' ), str_contains( $message, 'already been declared' ) => 'class_collision',
			str_contains( $message, 'Failed opening required' ), str_contains( $message, 'failed to open stream' ) => 'missing_runtime_file',
			str_contains( $message, 'undefined function' ), str_contains( $message, 'undefined method' ) => 'missing_runtime_symbol',
			str_contains( $message, 'database' ), str_contains( $message, 'wpdb' ) => 'database_bootstrap',
			default => 'other_bootstrap',
		};
		$result['failure_stage']  = $worker_stage;
		$result['error_class']    = get_class( $error );
		$result['error_category'] = $category;
		$result['file_category']  = $file_category;
		$result['error_line']     = $error->getLine();
		unset( $error, $message, $error_file );
		dreamax_lm_f26_write_result( $result_path, $result );
		exit( 2 );
	}
}

/**
 * Runs and consumes one isolated worker result.
 *
 * @param string $mode Worker mode.
 * @param string $clone_root Clone root.
 * @param string $marker Disposable marker.
 * @return array<string,mixed>
 * @throws RuntimeException When the worker result is invalid.
 */
function dreamax_lm_f26_run_worker( string $mode, string $clone_root, string $marker ): array {
	$result_file = $clone_root . DIRECTORY_SEPARATOR . '.dreamax-f26-result-' . $mode . '-' . bin2hex( random_bytes( 4 ) ) . '.json';
	$command     = array( PHP_BINARY, __FILE__, '--worker=' . $mode, '--wp-root=' . $clone_root, '--environment-marker=' . $marker, '--result-file=' . $result_file );
	$descriptor  = array(
		0 => array( 'pipe', 'r' ),
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	);
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Separate boot processes isolate each clone-only fault.
	$process = proc_open( $command, $descriptor, $pipes, __DIR__ );
	if ( ! is_resource( $process ) ) {
		throw new RuntimeException( 'A private fault worker could not be started.' );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes verifier-owned pipes.
	fclose( $pipes[0] );
	$stdout = stream_get_contents( $pipes[1] );
	$stderr = stream_get_contents( $pipes[2] );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes verifier-owned pipes.
	fclose( $pipes[1] );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes verifier-owned pipes.
	fclose( $pipes[2] );
	$exit = proc_close( $process );
	unset( $stdout, $stderr );
	if ( ! in_array( $exit, array( 0, 2 ), true ) || ! is_file( $result_file ) ) {
		throw new RuntimeException( 'A private fault worker returned no valid status.' );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads only the exact private result.
	$encoded = file_get_contents( $result_file );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes only the exact private result.
	if ( false === $encoded || ! unlink( $result_file ) ) {
		throw new RuntimeException( 'A private fault result could not be consumed.' );
	}
	$result = json_decode( $encoded, true, 512, JSON_THROW_ON_ERROR );
	if ( ! is_array( $result ) ) {
		throw new RuntimeException( 'A private fault result was invalid.' );
	}
	return $result;
}

$options = getopt( '', array( 'environment-marker:', 'wp-root:', 'confirm-faults:', 'worker:', 'result-file:', 'diagnose-only' ) );
if ( isset( $options['worker'] ) ) {
	dreamax_lm_f26_worker( $options );
}

$marker       = (string) ( $options['environment-marker'] ?? '' );
$confirmation = (string) ( $options['confirm-faults'] ?? '' );
$wp_root      = realpath( (string) ( $options['wp-root'] ?? '' ) );
if ( false === $wp_root || ! is_file( $wp_root . '/wp-load.php' ) || ! is_file( $wp_root . '/wp-config.php' ) ) {
	dreamax_lm_f26_fail( 'A valid disposable WordPress root is required.' );
}
if ( ! defined( 'DISABLE_WP_CRON' ) ) {
	define( 'DISABLE_WP_CRON', true );
}
if ( ! defined( 'WP_USE_THEMES' ) ) {
	define( 'WP_USE_THEMES', false );
}
require_once $wp_root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
DisposableEnvironmentGuard::assertSafe(
	array(
		'marker'   => $marker,
		'database' => defined( 'DB_NAME' ) ? (string) DB_NAME : '',
		'site_url' => (string) get_option( 'siteurl' ),
	)
);
if ( ! is_plugin_active( 'dreamax-license-manager/dreamax-license-manager.php' ) || ! function_exists( 'WC' ) ) {
	dreamax_lm_f26_fail( 'The disposable WooCommerce runtime is unavailable.' );
}

global $wpdb;
$wpdb->suppress_errors( true );
$source_database = (string) $wpdb->get_var( 'SELECT DATABASE()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $source_database ) ) {
	dreamax_lm_f26_fail( 'The disposable database identifier was unsafe.' );
}
$temp_root = realpath( sys_get_temp_dir() );
if ( false === $temp_root ) {
	dreamax_lm_f26_fail( 'Private temporary storage is unavailable.' );
}
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only residue preflight prevents ambiguous cleanup.
$stale_databases = $wpdb->get_col( $wpdb->prepare( 'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME LIKE %s', DREAMAX_LM_F26_DATABASE_PREFIX . '%' ) );
$stale_clones    = glob( $temp_root . DIRECTORY_SEPARATOR . DREAMAX_LM_F26_CLONE_PREFIX . '*', GLOB_ONLYDIR );
$source_digest   = dreamax_lm_f26_database_digest( $source_database );
$source_config   = hash_file( 'sha256', $wp_root . '/wp-config.php' );
$health          = new Health();
$plugin_tables   = array( 'licenses', 'activations', 'events', 'generators', 'idempotency', 'rate_limits', 'api_credentials', 'guest_claims', 'order_owners' );
$innodb_ready    = true;
foreach ( $plugin_tables as $suffix ) {
	$table = $wpdb->prefix . 'dreamax_lm_' . $suffix;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only engine preflight.
	$engine       = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s', $source_database, $table ) );
	$innodb_ready = $innodb_ready && 'InnoDB' === $engine;
}
$diagnosis = array(
	'disposable_guard_passed'   => true,
	'source_database_readable'  => $source_digest['tables'] > 0,
	'source_storage_ready'      => 'good' === $health->test_storage()['status'],
	'source_cleanup_scheduled'  => 'good' === $health->test_cleanup()['status'],
	'source_dependencies_ready' => ( new Requirements() )->satisfied(),
	'required_tables_innodb'    => $innodb_ready,
	'no_stale_database_residue' => array() === $stale_databases,
	'no_stale_clone_residue'    => is_array( $stale_clones ) && array() === $stale_clones,
);
if ( isset( $options['diagnose-only'] ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Emits only sanitized booleans and counts.
	echo json_encode(
		array(
			'classification'   => 'sanitized_f26_read_only_diagnosis',
			'checks'           => $diagnosis,
			'sensitive_output' => false,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . PHP_EOL;
	exit( in_array( false, $diagnosis, true ) ? 1 : 0 );
}
if ( DREAMAX_LM_F26_CONFIRMATION !== $confirmation || in_array( false, $diagnosis, true ) ) {
	dreamax_lm_f26_fail( 'The exact private-clone fault confirmation and clean diagnosis are required.' );
}

$suffix     = bin2hex( random_bytes( 8 ) );
$database   = DREAMAX_LM_F26_DATABASE_PREFIX . $suffix;
$clone_root = rtrim( $temp_root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . DREAMAX_LM_F26_CLONE_PREFIX . $suffix;
$cleanup    = array(
	'database_removed' => false,
	'clone_removed'    => false,
);
$results    = array();
$failure    = null;
$stage      = 'clone_create';
$held       = array();
try {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creates only one exact random private clone.
	if ( file_exists( $clone_root ) || ! mkdir( $clone_root, 0700 ) ) {
		throw new RuntimeException( 'The private fault clone could not be created.' );
	}
	dreamax_lm_f26_copy_tree( $wp_root, $clone_root );
	$stage       = 'repository_source_deploy';
	$repo_root   = dirname( __DIR__ );
	$plugin_root = $clone_root . '/wp-content/plugins/dreamax-license-manager';
	dreamax_lm_f26_copy_tree( $repo_root . '/src', $plugin_root . '/src' );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Deploys only current runtime source into the private clone.
	if ( ! copy( $repo_root . '/dreamax-license-manager.php', $plugin_root . '/dreamax-license-manager.php' ) ) {
		throw new RuntimeException( 'The current runtime source could not be deployed privately.' );
	}
	$stage = 'database_copy';
	dreamax_lm_f26_copy_database( $source_database, $database );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Clone configuration remains private and is never emitted.
	$clone_config = file_get_contents( $clone_root . '/wp-config.php' );
	if ( ! is_string( $clone_config ) || ! is_string( $source_config ) ) {
		throw new RuntimeException( 'The configuration baseline could not be verified.' );
	}
	$clone_config = dreamax_lm_f26_config_for_database( $clone_config, $database );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writes only private clone configuration.
	if ( strlen( $clone_config ) !== file_put_contents( $clone_root . '/wp-config.php', $clone_config, LOCK_EX ) ) {
		throw new RuntimeException( 'The private clone configuration could not be written.' );
	}
	$mu_root = $clone_root . '/wp-content/mu-plugins';
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creates only the private clone MU directory.
	if ( ! is_dir( $mu_root ) && ! mkdir( $mu_root, 0700, true ) && ! is_dir( $mu_root ) ) {
		throw new RuntimeException( 'The clone fault adapter directory could not be created.' );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Copies only the fixed linted adapter into the private clone.
	if ( ! copy( __DIR__ . '/lib/degraded-woo-fault.php', $mu_root . '/dreamax-f26-woo-fault.php' ) ) {
		throw new RuntimeException( 'The clone fault adapter could not be written.' );
	}

	foreach ( array( 'baseline', 'cron_missing', 'upload_missing' ) as $contract_mode ) {
		$stage                     = $contract_mode;
		$results[ $contract_mode ] = dreamax_lm_f26_run_worker( $contract_mode, $clone_root, $marker );
		if ( true !== ( $results[ $contract_mode ]['worker_ok'] ?? false ) ) {
			throw new RuntimeException( 'A private fault contract failed.' );
		}
	}

	$stage = 'storage_hold';
	dreamax_lm_f26_hold_tables( $database, $wpdb->prefix, $plugin_tables, false );
	$held               = $plugin_tables;
	$results['storage'] = dreamax_lm_f26_run_worker( 'storage_missing', $clone_root, $marker );
	dreamax_lm_f26_hold_tables( $database, $wpdb->prefix, $plugin_tables, true );
	$held = array();
	if ( true !== ( $results['storage']['worker_ok'] ?? false ) ) {
		throw new RuntimeException( 'The storage fault contract failed.' );
	}

	$stage = 'audit_hold';
	dreamax_lm_f26_hold_tables( $database, $wpdb->prefix, array( 'events' ), false );
	$held             = array( 'events' );
	$results['audit'] = dreamax_lm_f26_run_worker( 'audit_missing', $clone_root, $marker );
	dreamax_lm_f26_hold_tables( $database, $wpdb->prefix, array( 'events' ), true );
	$held = array();
	if ( true !== ( $results['audit']['worker_ok'] ?? false ) ) {
		throw new RuntimeException( 'The audit fault contract failed.' );
	}

	$stage      = 'woocommerce_missing';
	$woo_config = dreamax_lm_f26_config_without_woocommerce( $clone_config );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writes only the private clone fault configuration.
	if ( strlen( $woo_config ) !== file_put_contents( $clone_root . '/wp-config.php', $woo_config, LOCK_EX ) ) {
		throw new RuntimeException( 'The WooCommerce fault mode could not be selected.' );
	}
	$results['woocommerce'] = dreamax_lm_f26_run_worker( 'woo_missing', $clone_root, $marker );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Restores exact private clone configuration.
	if ( strlen( $clone_config ) !== file_put_contents( $clone_root . '/wp-config.php', $clone_config, LOCK_EX ) ) {
		throw new RuntimeException( 'The private clone configuration could not be restored.' );
	}
	if ( true !== ( $results['woocommerce']['worker_ok'] ?? false ) ) {
		throw new RuntimeException( 'The dependency fault contract failed.' );
	}

	$stage                     = 'recovery_baseline';
	$results['recovery']       = dreamax_lm_f26_run_worker( 'baseline', $clone_root, $marker );
	$clone_recovered           = true === ( $results['recovery']['worker_ok'] ?? false );
	$source_database_unchanged = dreamax_lm_f26_database_digest( $source_database ) === $source_digest;
	$source_config_unchanged   = hash_equals( $source_config, (string) hash_file( 'sha256', $wp_root . '/wp-config.php' ) );
	if ( ! $clone_recovered || ! $source_database_unchanged || ! $source_config_unchanged ) {
		throw new RuntimeException( 'The recovery or source-preservation contract failed.' );
	}
} catch ( Throwable $error ) {
	$failure = $error;
} finally {
	if ( array() !== $held ) {
		try {
			dreamax_lm_f26_hold_tables( $database, $wpdb->prefix, $held, true );
		} catch ( Throwable $restore_error ) {
			unset( $restore_error );
		}
	}
	try {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact cleanup checks only the random verifier target.
		if ( null !== $wpdb->get_var( $wpdb->prepare( 'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME=%s', $database ) ) ) {
			dreamax_lm_f26_drop_database( $database );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact post-cleanup check.
		$cleanup['database_removed'] = null === $wpdb->get_var( $wpdb->prepare( 'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME=%s', $database ) );
	} catch ( Throwable $cleanup_error ) {
		unset( $cleanup_error );
	}
	try {
		if ( is_dir( $clone_root ) ) {
			dreamax_lm_f26_remove_tree( $clone_root );
		}
		$cleanup['clone_removed'] = ! file_exists( $clone_root );
	} catch ( Throwable $cleanup_error ) {
		unset( $cleanup_error );
	}
}

$cleanup_committed = ! in_array( false, $cleanup, true );
if ( $failure instanceof Throwable || ! $cleanup_committed ) {
	$worker_contracts = array();
	$worker_stages    = array();
	$worker_errors    = array();
	foreach ( $results as $contract_name => $result ) {
		$worker_contracts[ $contract_name ] = is_array( $result['contracts'] ?? null ) ? $result['contracts'] : array();
		$worker_stages[ $contract_name ]    = (string) ( $result['failure_stage'] ?? 'none' );
		$worker_errors[ $contract_name ]    = array(
			'class'         => (string) ( $result['error_class'] ?? 'none' ),
			'category'      => (string) ( $result['error_category'] ?? 'none' ),
			'file_category' => (string) ( $result['file_category'] ?? 'none' ),
			'line'          => (int) ( $result['error_line'] ?? 0 ),
		);
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Emits only sanitized fixed fields.
	echo json_encode(
		array(
			'classification'   => 'sanitized_f26_verification_failure',
			'failure_stage'    => $stage,
			'worker_contracts' => $worker_contracts,
			'worker_stages'    => $worker_stages,
			'worker_errors'    => $worker_errors,
			'cleanup'          => $cleanup,
			'sensitive_output' => false,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . PHP_EOL;
	dreamax_lm_f26_fail( 'The guarded F26 degraded-mode verification failed.' );
}

$contracts = array();
foreach ( $results as $contract_mode => $result ) {
	$contracts[ $contract_mode ] = array_keys( array_filter( is_array( $result['contracts'] ?? null ) ? $result['contracts'] : array() ) );
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Emits only sanitized contract names, booleans, and counts.
echo json_encode(
	array(
		'classification'     => 'sanitized_f26_degraded_matrix',
		'fault_modes_passed' => 5,
		'baseline_recovered' => true,
		'source_unchanged'   => true,
		'cleanup_committed'  => true,
		'contracts'          => $contracts,
		'sensitive_output'   => false,
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
