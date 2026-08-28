<?php
/**
 * Verifies database-plus-key disaster recovery on a private disposable clone.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

use Dreamax\LicenseManager\Activations\ActivationService;
use Dreamax\LicenseManager\Encryption\Crypto;
use Dreamax\LicenseManager\Licenses\LicenseRepository;
use Dreamax\LicenseManager\ReleaseTools\DisposableEnvironmentGuard;
use Dreamax\LicenseManager\Support\Health;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/lib/release-tools.php';

const DREAMAX_LM_F12_CLONE_PREFIX    = 'dreamax-lm-f12-';
const DREAMAX_LM_F12_DATABASE_PREFIX = 'dreamax_lm_f12_';
const DREAMAX_LM_F12_CONFIRMATION    = 'DREAMAX_LM_F12_DISASTER_RECOVERY_CONFIRMED';

/**
 * Stops with a sanitized failure.
 *
 * @param string $message Failure message.
 */
function dreamax_lm_f12_fail( string $message ): never {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI verifier failures must go to stderr without rendering sensitive context.
	fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
	exit( 1 );
}

/**
 * Asserts that a database identifier belongs exclusively to this verifier.
 *
 * @param string $database Database identifier.
 * @throws RuntimeException When the identifier is not exactly verifier-scoped.
 */
function dreamax_lm_f12_assert_owned_database( string $database ): void {
	if ( 1 !== preg_match( '/^dreamax_lm_f12_(?:backup|restore)_test_[a-f0-9]{16}$/D', $database ) ) {
		throw new RuntimeException( 'A temporary database identifier was not safely scoped.' );
	}
}

/**
 * Returns every base table in one safely scoped database.
 *
 * @param string $database Database identifier.
 * @return list<string>
 * @throws RuntimeException When tables or views cannot be safely inspected.
 */
function dreamax_lm_f12_tables( string $database ): array {
	global $wpdb;
	if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $database ) ) {
		throw new RuntimeException( 'A database identifier was unsafe.' );
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The identifier is constrained above and live recovery acceptance requires authoritative schema inspection.
	$rows = $wpdb->get_results( "SHOW FULL TABLES FROM `{$database}`", ARRAY_N );
	if ( ! is_array( $rows ) || array() === $rows ) {
		throw new RuntimeException( 'The recovery database had no inspectable tables.' );
	}
	$tables = array();
	foreach ( $rows as $row ) {
		$table = (string) ( $row[0] ?? '' );
		$type  = strtoupper( (string) ( $row[1] ?? '' ) );
		if ( 'BASE TABLE' !== $type || 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $table ) ) {
			throw new RuntimeException( 'The recovery drill refuses views or unsafe table identifiers.' );
		}
		$tables[] = $table;
	}
	sort( $tables, SORT_STRING );
	return array_values( array_unique( $tables ) );
}

/**
 * Returns a schema-and-data digest without retaining database values.
 *
 * @param string $database Database identifier.
 * @return array{digest:string,tables:int,rows:int}
 * @throws RuntimeException When a table cannot be inspected.
 */
function dreamax_lm_f12_database_digest( string $database ): array {
	global $wpdb;
	$state      = array();
	$total_rows = 0;
	$tables     = dreamax_lm_f12_tables( $database );
	foreach ( $tables as $table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Both identifiers are strictly constrained and recovery verification requires fresh schema/data checks.
		$create = $wpdb->get_row( "SHOW CREATE TABLE `{$database}`.`{$table}`", ARRAY_N );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Both identifiers are strictly constrained and recovery verification requires fresh row counts.
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM `{$database}`.`{$table}`" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Both identifiers are strictly constrained and CHECKSUM avoids retaining table data.
		$checksum = $wpdb->get_row( "CHECKSUM TABLE `{$database}`.`{$table}`", ARRAY_N );
		if ( ! is_array( $create ) || ! is_numeric( $count ) || ! is_array( $checksum ) || ! is_numeric( $checksum[1] ?? null ) ) {
			throw new RuntimeException( 'A recovery table could not be digested.' );
		}
		$normalized_schema = preg_replace( '/\sAUTO_INCREMENT=\d+/D', '', (string) ( $create[1] ?? '' ) );
		if ( ! is_string( $normalized_schema ) ) {
			throw new RuntimeException( 'A recovery table schema could not be normalized.' );
		}
		$row_count   = (int) $count;
		$total_rows += $row_count;
		$state[]     = array(
			'table'    => $table,
			'schema'   => $normalized_schema,
			'rows'     => $row_count,
			'checksum' => (string) $checksum[1],
		);
	}
	$encoded_state = wp_json_encode( $state, JSON_UNESCAPED_SLASHES );
	if ( ! is_string( $encoded_state ) ) {
		throw new RuntimeException( 'The recovery database digest could not be encoded.' );
	}
	return array(
		'digest' => hash( 'sha256', $encoded_state ),
		'tables' => count( $tables ),
		'rows'   => $total_rows,
	);
}

/**
 * Returns a digest of only plugin-owned rows and hashed key metadata.
 *
 * @param string $database Database identifier.
 * @return string
 * @throws RuntimeException When plugin-owned state cannot be inspected safely.
 */
function dreamax_lm_f12_plugin_digest( string $database ): string {
	global $wpdb;
	if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $database ) || 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $wpdb->prefix ) ) {
		throw new RuntimeException( 'Plugin-state inspection refused an unsafe identifier.' );
	}
	$plugin_prefix = $wpdb->prefix . 'dreamax_lm_';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The database is constrained and the LIKE value is prepared for authoritative plugin-owned inspection.
	$tables = $wpdb->get_col( $wpdb->prepare( "SHOW TABLES FROM `{$database}` LIKE %s", $wpdb->esc_like( $plugin_prefix ) . '%' ) );
	$tables = is_array( $tables ) ? array_map( 'strval', $tables ) : array();
	sort( $tables, SORT_STRING );
	$state = array();
	foreach ( $tables as $table ) {
		if ( ! str_starts_with( $table, $plugin_prefix ) || 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $table ) ) {
			throw new RuntimeException( 'Plugin-state inspection refused an unsafe table.' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Strict identifiers and CHECKSUM avoid retaining protected plugin values.
		$checksum = $wpdb->get_row( "CHECKSUM TABLE `{$database}`.`{$table}`", ARRAY_N );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Strict identifiers provide an authoritative plugin-owned row count.
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM `{$database}`.`{$table}`" );
		if ( ! is_array( $checksum ) || ! is_numeric( $checksum[1] ?? null ) || ! is_numeric( $count ) ) {
			throw new RuntimeException( 'Plugin-owned state could not be digested.' );
		}
		$state[] = array(
			'table'    => $table,
			'rows'     => (int) $count,
			'checksum' => (string) $checksum[1],
		);
	}
	$options_table = $wpdb->prefix . 'options';
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Strict identifiers and server-side hashing prevent protected option values from leaving the database query boundary.
	$key_metadata = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT option_name, SHA2(CAST(option_value AS CHAR), 256) AS value_hash FROM `{$database}`.`{$options_table}` WHERE option_name IN (%s,%s) ORDER BY option_name",
			'dreamax_lm_master_key_id',
			'dreamax_lm_kdf_salt'
		),
		ARRAY_A
	);
	// phpcs:enable
	if ( ! is_array( $key_metadata ) ) {
		throw new RuntimeException( 'Hashed key metadata could not be inspected.' );
	}
	$encoded = wp_json_encode(
		array(
			'tables'              => $state,
			'key_metadata_hashes' => $key_metadata,
		),
		JSON_UNESCAPED_SLASHES
	);
	if ( ! is_string( $encoded ) ) {
		throw new RuntimeException( 'Plugin-owned recovery state could not be encoded.' );
	}
	return hash( 'sha256', $encoded );
}

/**
 * Copies a complete base-table database into a new verifier-owned database.
 *
 * @param string $source Source database.
 * @param string $target Exact verifier-owned target database.
 * @throws RuntimeException When creation or copying fails.
 */
function dreamax_lm_f12_copy_database( string $source, string $target ): void {
	global $wpdb;
	dreamax_lm_f12_assert_owned_database( $target );
	if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $source ) ) {
		throw new RuntimeException( 'The source database identifier was unsafe.' );
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fresh existence check prevents overwriting any database.
	if ( null !== $wpdb->get_var( $wpdb->prepare( 'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = %s', $target ) ) ) {
		throw new RuntimeException( 'A verifier-owned database target already exists.' );
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The exact random verifier-owned target is validated above.
	if ( false === $wpdb->query( "CREATE DATABASE `{$target}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" ) ) {
		throw new RuntimeException( 'A verifier-owned database could not be created.' );
	}
	foreach ( dreamax_lm_f12_tables( $source ) as $table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Strict identifiers copy exact schema only into the newly created verifier database.
		if ( false === $wpdb->query( "CREATE TABLE `{$target}`.`{$table}` LIKE `{$source}`.`{$table}`" ) ) {
			throw new RuntimeException( 'A recovery table schema could not be copied.' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Strict identifiers copy the complete disposable table into the private backup or restore database.
		if ( false === $wpdb->query( "INSERT INTO `{$target}`.`{$table}` SELECT * FROM `{$source}`.`{$table}`" ) ) {
			throw new RuntimeException( 'A recovery table body could not be copied.' );
		}
	}
}

/**
 * Drops only one exact verifier-owned temporary database.
 *
 * @param string $database Exact verifier-owned database.
 * @throws RuntimeException When cleanup fails.
 */
function dreamax_lm_f12_drop_database( string $database ): void {
	global $wpdb;
	dreamax_lm_f12_assert_owned_database( $database );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Exact cleanup removes only the validated verifier-owned database.
	if ( false === $wpdb->query( "DROP DATABASE IF EXISTS `{$database}`" ) ) {
		throw new RuntimeException( 'A verifier-owned database could not be removed.' );
	}
}

/**
 * Copies a disposable WordPress tree without mutable uploads or caches.
 *
 * @param string $source Source WordPress root.
 * @param string $target Exact private temporary clone root.
 * @throws RuntimeException When the clone cannot be created safely.
 */
function dreamax_lm_f12_copy_tree( string $source, string $target ): void {
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
			throw new RuntimeException( 'The disaster-recovery clone refused a symbolic link.' );
		}
		$destination = $target . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
		if ( $item->isDir() ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creates only the exact private temporary clone.
			if ( ! is_dir( $destination ) && ! mkdir( $destination, 0700, true ) && ! is_dir( $destination ) ) {
				throw new RuntimeException( 'A disaster-recovery clone directory could not be created.' );
			}
			continue;
		}
		$parent = dirname( $destination );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creates only the exact private temporary clone parent.
		if ( ! is_dir( $parent ) && ! mkdir( $parent, 0700, true ) && ! is_dir( $parent ) ) {
			throw new RuntimeException( 'A disaster-recovery clone parent could not be created.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Copies only into the private temporary clone.
		if ( ! copy( $item->getPathname(), $destination ) ) {
			throw new RuntimeException( 'A disaster-recovery clone file could not be copied.' );
		}
	}
}

/**
 * Removes only one verified private temporary clone.
 *
 * @param string $target Exact clone root.
 * @throws RuntimeException When cleanup is ambiguous or incomplete.
 */
function dreamax_lm_f12_remove_tree( string $target ): void {
	$temp_real   = realpath( sys_get_temp_dir() );
	$target_real = realpath( $target );
	if ( false === $temp_real || false === $target_real || dirname( $target_real ) !== rtrim( $temp_real, DIRECTORY_SEPARATOR ) || ! str_starts_with( basename( $target_real ), DREAMAX_LM_F12_CLONE_PREFIX ) ) {
		throw new RuntimeException( 'Cleanup refused an ambiguous disaster-recovery clone.' );
	}
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $target_real, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $iterator as $item ) {
		if ( $item->isLink() ) {
			throw new RuntimeException( 'Cleanup refused a symbolic link.' );
		}
		if ( $item->isDir() ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Exact guarded cleanup removes only the private verifier clone.
			if ( ! rmdir( $item->getPathname() ) ) {
				throw new RuntimeException( 'A clone directory could not be removed.' );
			}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Exact guarded cleanup removes only the private verifier clone.
		} elseif ( ! unlink( $item->getPathname() ) ) {
			throw new RuntimeException( 'A clone file could not be removed.' );
		}
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Exact guarded cleanup removes only the private verifier clone root.
	if ( ! rmdir( $target_real ) ) {
		throw new RuntimeException( 'The clone root could not be removed.' );
	}
}

/**
 * Replaces exactly one database definition in cloned configuration text.
 *
 * @param string $config Original cloned configuration.
 * @param string $database Restore database identifier.
 * @throws RuntimeException When the definition is not uniquely replaceable.
 */
function dreamax_lm_f12_config_for_database( string $config, string $database ): string {
	dreamax_lm_f12_assert_owned_database( $database );
	$pattern = '/^[ \t]*define\s*\(\s*([\'\"])DB_NAME\1\s*,.*?\)\s*;[ \t]*\r?$/m';
	if ( 1 !== preg_match_all( $pattern, $config ) ) {
		throw new RuntimeException( 'The cloned database definition was not uniquely replaceable.' );
	}
	$changed = preg_replace( $pattern, "define( 'DB_NAME', '" . $database . "' );", $config, 1, $count );
	if ( ! is_string( $changed ) || 1 !== $count ) {
		throw new RuntimeException( 'The cloned restore database could not be selected.' );
	}
	return $changed;
}

/**
 * Removes exactly one root-key definition from cloned configuration text.
 *
 * @param string $config Correct restore configuration.
 * @throws RuntimeException When the definition is not uniquely replaceable.
 */
function dreamax_lm_f12_database_only_config( string $config ): string {
	$pattern = '/^[ \t]*define\s*\(\s*([\'\"])DREAMAX_LICENSE_MANAGER_MASTER_KEY\1\s*,.*?\)\s*;[ \t]*\r?$/m';
	if ( 1 !== preg_match_all( $pattern, $config ) ) {
		throw new RuntimeException( 'The cloned master-key definition was not uniquely replaceable.' );
	}
	$changed = preg_replace( $pattern, '// Dreamax F12 database-only restore intentionally omits the external key.', $config, 1, $count );
	if ( ! is_string( $changed ) || 1 !== $count ) {
		throw new RuntimeException( 'The database-only restore configuration could not be prepared.' );
	}
	return $changed;
}

/**
 * Writes one private worker result file.
 *
 * @param string              $path Result path.
 * @param array<string,mixed> $result Sanitized result.
 * @throws RuntimeException When writing fails.
 */
function dreamax_lm_f12_write_result( string $path, array $result ): void {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in every process and JSON exceptions are required.
	$encoded = json_encode( $result, JSON_THROW_ON_ERROR );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writes only the exact private worker result.
	if ( strlen( $encoded ) !== file_put_contents( $path, $encoded, LOCK_EX ) ) {
		throw new RuntimeException( 'The private recovery result could not be written.' );
	}
}

/**
 * Runs one restored-site worker without exposing protected values.
 *
 * @param array<string,mixed> $options Command options.
 * @throws RuntimeException When the private result cannot be written.
 */
function dreamax_lm_f12_worker( array $options ): never {
	$mode        = (string) ( $options['worker'] ?? '' );
	$marker      = (string) ( $options['environment-marker'] ?? '' );
	$wp_root     = realpath( (string) ( $options['wp-root'] ?? '' ) );
	$result_path = (string) ( $options['result-file'] ?? '' );
	if ( false === $wp_root || ! in_array( $mode, array( 'database_only', 'complete_restore' ), true ) || realpath( dirname( $result_path ) ) !== $wp_root || ! str_starts_with( basename( $result_path ), '.dreamax-f12-result-' ) ) {
		dreamax_lm_f12_fail( 'The private restore-worker arguments are invalid.' );
	}
	if ( ! defined( 'DISABLE_WP_CRON' ) ) {
		define( 'DISABLE_WP_CRON', true );
	}
	if ( ! defined( 'WP_USE_THEMES' ) ) {
		define( 'WP_USE_THEMES', false );
	}
	// phpcs:disable -- Private workers suppress boot diagnostics so paths or configuration context never reach the parent.
	ini_set( 'display_errors', '0' );
	ini_set( 'log_errors', '0' );
	error_reporting( E_ERROR | E_PARSE );
	// phpcs:enable
	$stage = 'bootstrap';
	try {
		require_once $wp_root . '/wp-load.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$stage = 'guard';
		DisposableEnvironmentGuard::assertSafe(
			array(
				'marker'   => $marker,
				'database' => defined( 'DB_NAME' ) ? (string) DB_NAME : '',
				'site_url' => (string) get_option( 'siteurl' ),
			)
		);
		if ( ! is_plugin_active( 'dreamax-license-manager/dreamax-license-manager.php' ) ) {
			throw new RuntimeException( 'The restored plugin runtime is unavailable.' );
		}
		global $wpdb;
		$database = (string) $wpdb->get_var( 'SELECT DATABASE()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		dreamax_lm_f12_assert_owned_database( $database );
		$before = dreamax_lm_f12_database_digest( $database );
		$crypto = new Crypto();
		$health = ( new Health() )->test_encryption();
		$result = array(
			'worker_ok'           => true,
			'database_digest'     => $before['digest'],
			'recovery_signaled'   => false,
			'decryption_blocked'  => false,
			'encryption_ready'    => false,
			'prebackup_decrypts'  => false,
			'prebackup_validates' => false,
			'database_unchanged'  => false,
		);
		$stage  = 'contracts';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The guarded verifier needs one eligible pre-backup encrypted row.
		$license = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}dreamax_lm_licenses WHERE lifecycle_status = 'assigned' AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP()) ORDER BY id LIMIT 1", ARRAY_A );
		if ( ! is_array( $license ) || '' === (string) ( $license['key_ciphertext'] ?? '' ) ) {
			throw new RuntimeException( 'An eligible pre-backup license was unavailable.' );
		}
		if ( 'database_only' === $mode ) {
			$result['recovery_signaled'] = ! $crypto->ready() && 'critical' === $health['status'];
			try {
				( new LicenseRepository() )->decrypt_key( $license );
			} catch ( Throwable $error ) {
				$result['decryption_blocked'] = true;
			}
		} else {
			$result['encryption_ready']    = $crypto->ready() && 'good' === $health['status'];
			$key                           = ( new LicenseRepository() )->decrypt_key( $license );
			$result['prebackup_decrypts']  = '' !== $key;
			$validation                    = ( new ActivationService() )->validate( $key, (string) $license['product_public_id'] );
			$result['prebackup_validates'] = 'valid' === ( $validation['status'] ?? null ) && ( $validation['license_public_id'] ?? null ) === $license['public_id'];
			if ( function_exists( 'sodium_memzero' ) ) {
				sodium_memzero( $key );
			}
		}
		$result['database_unchanged'] = dreamax_lm_f12_database_digest( $database )['digest'] === $before['digest'];
		dreamax_lm_f12_write_result( $result_path, $result );
		exit( 0 );
	} catch ( Throwable $error ) {
		dreamax_lm_f12_write_result(
			$result_path,
			array(
				'worker_ok'     => false,
				'failure_stage' => $stage,
			)
		);
		exit( 2 );
	}
}

/**
 * Runs one private restored-site worker and consumes only its sanitized result.
 *
 * @param string $mode Worker mode.
 * @param string $clone_root Private clone root.
 * @param string $marker Disposable marker.
 * @return array<string,mixed>
 * @throws RuntimeException When execution or result consumption fails.
 */
function dreamax_lm_f12_run_worker( string $mode, string $clone_root, string $marker ): array {
	$result_file = $clone_root . DIRECTORY_SEPARATOR . '.dreamax-f12-result-' . $mode . '-' . bin2hex( random_bytes( 4 ) ) . '.json';
	$command     = array( PHP_BINARY, __FILE__, '--worker=' . $mode, '--wp-root=' . $clone_root, '--environment-marker=' . $marker, '--result-file=' . $result_file );
	$descriptor  = array(
		0 => array( 'pipe', 'r' ),
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	);
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Isolated workers prevent restored WordPress constants from contaminating the parent process.
	$process = proc_open( $command, $descriptor, $pipes, __DIR__ );
	if ( ! is_resource( $process ) ) {
		throw new RuntimeException( 'A private restore worker could not be started.', 1 );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes only verifier-owned pipes.
	fclose( $pipes[0] );
	$stdout = stream_get_contents( $pipes[1] );
	$stderr = stream_get_contents( $pipes[2] );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes only verifier-owned pipes.
	fclose( $pipes[1] );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes only verifier-owned pipes.
	fclose( $pipes[2] );
	$exit = proc_close( $process );
	unset( $stdout, $stderr );
	if ( ! in_array( $exit, array( 0, 2 ), true ) || ! is_file( $result_file ) ) {
		throw new RuntimeException( 'A private restore worker did not return a valid status.', 2 );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads only the exact private result.
	$encoded = file_get_contents( $result_file );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes only the exact private result after consumption.
	if ( false === $encoded || ! unlink( $result_file ) ) {
		throw new RuntimeException( 'A private restore result could not be consumed.', 3 );
	}
	$result = json_decode( $encoded, true, 512, JSON_THROW_ON_ERROR );
	if ( ! is_array( $result ) ) {
		throw new RuntimeException( 'A private restore result was invalid.', 4 );
	}
	return $result;
}

$options = getopt( '', array( 'environment-marker:', 'wp-root:', 'confirm-restore:', 'worker:', 'result-file:', 'diagnose-only' ) );
if ( isset( $options['worker'] ) ) {
	dreamax_lm_f12_worker( $options );
}

$marker       = (string) ( $options['environment-marker'] ?? '' );
$confirmation = (string) ( $options['confirm-restore'] ?? '' );
$wp_root      = realpath( (string) ( $options['wp-root'] ?? '' ) );
if ( false === $wp_root || ! is_file( $wp_root . '/wp-load.php' ) || ! is_file( $wp_root . '/wp-config.php' ) ) {
	dreamax_lm_f12_fail( 'A valid disposable WordPress root is required.' );
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
if ( ! is_plugin_active( 'dreamax-license-manager/dreamax-license-manager.php' ) ) {
	dreamax_lm_f12_fail( 'The disposable plugin runtime is unavailable.' );
}

global $wpdb;
$source_database = (string) $wpdb->get_var( 'SELECT DATABASE()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $source_database ) ) {
	dreamax_lm_f12_fail( 'The disposable database identifier was unsafe.' );
}
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only residue preflight prevents ambiguous destructive cleanup.
$stale_databases = $wpdb->get_col( $wpdb->prepare( 'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME LIKE %s', DREAMAX_LM_F12_DATABASE_PREFIX . '%' ) );
$temp_root       = realpath( sys_get_temp_dir() );
$stale_clones    = false === $temp_root ? false : glob( $temp_root . DIRECTORY_SEPARATOR . DREAMAX_LM_F12_CLONE_PREFIX . '*', GLOB_ONLYDIR );
$eligible_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_licenses WHERE lifecycle_status = 'assigned' AND key_ciphertext <> '' AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$source_digest   = dreamax_lm_f12_database_digest( $source_database );
$diagnosis       = array(
	'disposable_guard_passed'   => true,
	'source_database_readable'  => $source_digest['tables'] > 0,
	'eligible_fixture_present'  => $eligible_count > 0,
	'no_stale_database_residue' => array() === $stale_databases,
	'no_stale_clone_residue'    => is_array( $stale_clones ) && array() === $stale_clones,
);
if ( isset( $options['diagnose-only'] ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Emits only fixed sanitized booleans and counts.
	echo json_encode(
		array(
			'classification'    => 'sanitized_f12_read_only_diagnosis',
			'checks'            => $diagnosis,
			'source_tables'     => $source_digest['tables'],
			'eligible_fixtures' => min( 1, $eligible_count ),
			'sensitive_output'  => false,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . PHP_EOL;
	exit( in_array( false, $diagnosis, true ) ? 1 : 0 );
}
if ( DREAMAX_LM_F12_CONFIRMATION !== $confirmation || in_array( false, $diagnosis, true ) ) {
	dreamax_lm_f12_fail( 'The exact disposable restore confirmation and clean preflight are required.' );
}

$suffix           = bin2hex( random_bytes( 8 ) );
$backup_database  = DREAMAX_LM_F12_DATABASE_PREFIX . 'backup_test_' . $suffix;
$restore_database = DREAMAX_LM_F12_DATABASE_PREFIX . 'restore_test_' . $suffix;
$clone_root       = rtrim( (string) $temp_root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . DREAMAX_LM_F12_CLONE_PREFIX . $suffix;
$failure          = null;
$failure_stage    = 'clone_create';
$failed_contracts = array();
$checks           = array();
$worker_stages    = array(
	'database_only'    => 'not_run',
	'complete_restore' => 'not_run',
);
$cleanup          = array(
	'backup_database_removed'  => false,
	'restore_database_removed' => false,
	'clone_removed'            => false,
);

try {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creates only the random private verifier clone.
	if ( file_exists( $clone_root ) || ! mkdir( $clone_root, 0700 ) ) {
		throw new RuntimeException( 'The private disaster-recovery clone could not be created.' );
	}
	dreamax_lm_f12_copy_tree( $wp_root, $clone_root );
	$original_config_hash = hash_file( 'sha256', $wp_root . '/wp-config.php' );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- The cloned configuration remains private and is never emitted.
	$original_clone_config = file_get_contents( $clone_root . '/wp-config.php' );
	if ( false === $original_config_hash || false === $original_clone_config ) {
		throw new RuntimeException( 'The recovery configuration baseline could not be verified.' );
	}

	$failure_stage = 'backup_database';
	dreamax_lm_f12_copy_database( $source_database, $backup_database );
	$backup_digest                   = dreamax_lm_f12_database_digest( $backup_database );
	$checks['backup_matches_source'] = $backup_digest === $source_digest;

	$failure_stage = 'restore_database';
	dreamax_lm_f12_copy_database( $backup_database, $restore_database );
	$restore_digest                   = dreamax_lm_f12_database_digest( $restore_database );
	$checks['restore_matches_backup'] = $restore_digest === $backup_digest;
	$restore_plugin_digest            = dreamax_lm_f12_plugin_digest( $restore_database );

	$failure_stage           = 'database_only_configuration';
	$complete_restore_config = dreamax_lm_f12_config_for_database( $original_clone_config, $restore_database );
	$database_only_config    = dreamax_lm_f12_database_only_config( $complete_restore_config );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writes only the private cloned configuration.
	if ( strlen( $database_only_config ) !== file_put_contents( $clone_root . '/wp-config.php', $database_only_config, LOCK_EX ) ) {
		throw new RuntimeException( 'The database-only restore could not be configured.' );
	}
	$failure_stage                  = 'database_only_worker';
	$database_only                  = dreamax_lm_f12_run_worker( 'database_only', $clone_root, $marker );
	$worker_stages['database_only'] = true === ( $database_only['worker_ok'] ?? false ) ? 'none' : (string) ( $database_only['failure_stage'] ?? 'unknown' );
	$checks['database_only_warns']  = true === ( $database_only['worker_ok'] ?? false )
		&& true === ( $database_only['recovery_signaled'] ?? false )
		&& true === ( $database_only['decryption_blocked'] ?? false )
		&& true === ( $database_only['database_unchanged'] ?? false );

	$failure_stage = 'complete_restore_configuration';
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Restores the private clone's exact backed-up key configuration with the restore database selected.
	if ( strlen( $complete_restore_config ) !== file_put_contents( $clone_root . '/wp-config.php', $complete_restore_config, LOCK_EX ) ) {
		throw new RuntimeException( 'The complete restore could not be configured.' );
	}
	$failure_stage                            = 'complete_restore_worker';
	$complete_restore                         = dreamax_lm_f12_run_worker( 'complete_restore', $clone_root, $marker );
	$worker_stages['complete_restore']        = true === ( $complete_restore['worker_ok'] ?? false ) ? 'none' : (string) ( $complete_restore['failure_stage'] ?? 'unknown' );
	$checks['complete_restore_ready']         = true === ( $complete_restore['worker_ok'] ?? false )
		&& true === ( $complete_restore['encryption_ready'] ?? false )
		&& true === ( $complete_restore['prebackup_decrypts'] ?? false )
		&& true === ( $complete_restore['prebackup_validates'] ?? false )
		&& true === ( $complete_restore['database_unchanged'] ?? false );
	$checks['restore_plugin_state_preserved'] = dreamax_lm_f12_plugin_digest( $restore_database ) === $restore_plugin_digest;
	$checks['source_database_unchanged']      = dreamax_lm_f12_database_digest( $source_database ) === $source_digest;
	$checks['original_config_unchanged']      = hash_equals( $original_config_hash, (string) hash_file( 'sha256', $wp_root . '/wp-config.php' ) );
	$checks['clone_key_backup_restored']      = hash_equals( hash( 'sha256', $complete_restore_config ), (string) hash_file( 'sha256', $clone_root . '/wp-config.php' ) );
	foreach ( $checks as $contract => $passed ) {
		if ( ! $passed ) {
			$failed_contracts[] = $contract;
		}
	}
	if ( array() !== $failed_contracts ) {
		throw new RuntimeException( 'One or more disaster-recovery contracts failed.' );
	}
} catch ( Throwable $error ) {
	$failure = $error;
} finally {
	try {
		if ( null !== $wpdb->get_var( $wpdb->prepare( 'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = %s', $restore_database ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			dreamax_lm_f12_drop_database( $restore_database );
		}
		$cleanup['restore_database_removed'] = null === $wpdb->get_var( $wpdb->prepare( 'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = %s', $restore_database ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	} catch ( Throwable $cleanup_error ) {
		$cleanup['restore_database_removed'] = false;
	}
	try {
		if ( null !== $wpdb->get_var( $wpdb->prepare( 'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = %s', $backup_database ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			dreamax_lm_f12_drop_database( $backup_database );
		}
		$cleanup['backup_database_removed'] = null === $wpdb->get_var( $wpdb->prepare( 'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = %s', $backup_database ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	} catch ( Throwable $cleanup_error ) {
		$cleanup['backup_database_removed'] = false;
	}
	try {
		if ( is_dir( $clone_root ) ) {
			dreamax_lm_f12_remove_tree( $clone_root );
		}
		$cleanup['clone_removed'] = ! file_exists( $clone_root );
	} catch ( Throwable $cleanup_error ) {
		$cleanup['clone_removed'] = false;
	}
}

$cleanup_committed = ! in_array( false, $cleanup, true );
if ( $failure instanceof Throwable || ! $cleanup_committed ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Emits only fixed sanitized fields.
	echo json_encode(
		array(
			'classification'   => 'sanitized_f12_verification_failure',
			'failure_stage'    => $failure_stage,
			'worker_stages'    => $worker_stages,
			'failed_contracts' => $failed_contracts,
			'cleanup'          => $cleanup,
			'sensitive_output' => false,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . PHP_EOL;
	dreamax_lm_f12_fail( 'The guarded F12 disaster-recovery verification failed.' );
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Emits only fixed sanitized booleans and counts.
echo json_encode(
	array(
		'classification'              => 'live_disposable_disaster_recovery',
		'contracts_passed'            => count( $checks ),
		'backup_matches_source'       => $checks['backup_matches_source'],
		'restore_matches_backup'      => $checks['restore_matches_backup'],
		'restore_plugin_state_kept'   => $checks['restore_plugin_state_preserved'],
		'database_only_warned'        => $checks['database_only_warns'],
		'complete_restore_ready'      => $checks['complete_restore_ready'],
		'prebackup_license_decrypted' => true,
		'prebackup_license_validated' => true,
		'source_unchanged'            => $checks['source_database_unchanged'] && $checks['original_config_unchanged'],
		'cleanup_committed'           => $cleanup_committed,
		'outbound_email_sent'         => false,
		'sensitive_output'            => false,
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
