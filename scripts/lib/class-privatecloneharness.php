<?php
/**
 * Shared guarded private-clone utilities for live release verification.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\ReleaseTools;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Creates and removes exact verifier-owned databases and file clones.
 */
final class PrivateCloneHarness {
	/**
	 * Validates an exact verifier-owned database name.
	 *
	 * @param string $database Database name.
	 * @param string $prefix Verifier prefix.
	 * @throws RuntimeException When the name is not verifier-owned.
	 */
	public static function assert_database( string $database, string $prefix ): void {
		if ( 1 !== preg_match( '/^' . preg_quote( $prefix, '/' ) . '[a-f0-9]{16}$/D', $database ) ) {
			throw new RuntimeException( 'A temporary database identifier was not safely scoped.' );
		}
	}

	/**
	 * Lists safe base tables in one database.
	 *
	 * @param string $database Database name.
	 * @return list<string>
	 * @throws RuntimeException When tables cannot be inspected.
	 */
	public static function tables( string $database ): array {
		global $wpdb;
		if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $database ) ) {
			throw new RuntimeException( 'A database identifier was unsafe.' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifier is strictly constrained.
		$rows = $wpdb->get_results( "SHOW FULL TABLES FROM `{$database}`", ARRAY_N );
		if ( ! is_array( $rows ) || array() === $rows ) {
			throw new RuntimeException( 'The database had no inspectable tables.' );
		}
		$tables = array();
		foreach ( $rows as $row ) {
			$table = (string) ( $row[0] ?? '' );
			$type  = strtoupper( (string) ( $row[1] ?? '' ) );
			if ( 'BASE TABLE' !== $type || 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $table ) ) {
				throw new RuntimeException( 'The verifier refused an unsafe database object.' );
			}
			$tables[] = $table;
		}
		sort( $tables, SORT_STRING );
		return array_values( array_unique( $tables ) );
	}

	/**
	 * Produces a value-free database digest.
	 *
	 * @param string $database Database name.
	 * @return array{digest:string,tables:int,rows:int}
	 * @throws RuntimeException When a table cannot be digested.
	 */
	public static function database_digest( string $database ): array {
		global $wpdb;
		$state      = array();
		$total_rows = 0;
		$tables     = self::tables( $database );
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
				throw new RuntimeException( 'A database schema could not be normalized.' );
			}
			$row_count   = (int) $count;
			$total_rows += $row_count;
			$state[]     = array(
				'table'    => $table,
				'schema'   => $schema,
				'rows'     => $row_count,
				'checksum' => (string) $checksum[1],
			);
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
	 * Copies one database into a fresh verifier-owned target.
	 *
	 * @param string $source Source database.
	 * @param string $target Target database.
	 * @param string $prefix Verifier prefix.
	 * @throws RuntimeException When copying fails.
	 */
	public static function copy_database( string $source, string $target, string $prefix ): void {
		global $wpdb;
		self::assert_database( $target, $prefix );
		if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $source ) ) {
			throw new RuntimeException( 'The source database identifier was unsafe.' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Existence check prevents overwrite.
		if ( null !== $wpdb->get_var( $wpdb->prepare( 'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = %s', $target ) ) ) {
			throw new RuntimeException( 'A verifier-owned database target already exists.' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Exact target is validated above.
		if ( false === $wpdb->query( "CREATE DATABASE `{$target}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" ) ) {
			throw new RuntimeException( 'A verifier-owned database could not be created.' );
		}
		foreach ( self::tables( $source ) as $table ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Strict identifiers copy into a fresh private database.
			if ( false === $wpdb->query( "CREATE TABLE `{$target}`.`{$table}` LIKE `{$source}`.`{$table}`" )
				|| false === $wpdb->query( "INSERT INTO `{$target}`.`{$table}` SELECT * FROM `{$source}`.`{$table}`" ) ) {
				throw new RuntimeException( 'A private database could not be copied.' );
			}
			// phpcs:enable
		}
	}

	/**
	 * Removes one exact verifier-owned database.
	 *
	 * @param string $database Database name.
	 * @param string $prefix Verifier prefix.
	 * @throws RuntimeException When cleanup fails.
	 */
	public static function drop_database( string $database, string $prefix ): void {
		global $wpdb;
		self::assert_database( $database, $prefix );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Exact target is validated above.
		if ( false === $wpdb->query( "DROP DATABASE IF EXISTS `{$database}`" ) ) {
			throw new RuntimeException( 'A verifier-owned database could not be removed.' );
		}
	}

	/**
	 * Copies a WordPress tree without mutable content directories.
	 *
	 * @param string $source Source root.
	 * @param string $target Clone root.
	 * @throws RuntimeException When copying fails.
	 */
	public static function copy_tree( string $source, string $target ): void {
		$excluded = array( 'wp-content/uploads', 'wp-content/cache', 'wp-content/upgrade', 'wp-content/backups', 'wp-content/ai1wm-backups' );
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $iterator as $item ) {
			$relative = str_replace( '\\', '/', substr( $item->getPathname(), strlen( $source ) + 1 ) );
			foreach ( $excluded as $excluded_path ) {
				if ( $relative === $excluded_path || str_starts_with( $relative, $excluded_path . '/' ) ) {
					continue 2;
				}
			}
			if ( $item->isLink() ) {
				throw new RuntimeException( 'The private clone refused a symbolic link.' );
			}
			$destination = $target . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
			if ( $item->isDir() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creates only the exact private clone.
				if ( ! is_dir( $destination ) && ! mkdir( $destination, 0700, true ) && ! is_dir( $destination ) ) {
					throw new RuntimeException( 'A private clone directory could not be created.' );
				}
				continue;
			}
			$parent = dirname( $destination );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creates only the exact private clone parent.
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
	 * Removes one exact private file clone.
	 *
	 * @param string $target Clone root.
	 * @param string $prefix Clone basename prefix.
	 * @throws RuntimeException When cleanup is ambiguous.
	 */
	public static function remove_tree( string $target, string $prefix ): void {
		$temp_real   = realpath( sys_get_temp_dir() );
		$target_real = realpath( $target );
		if ( false === $temp_real || false === $target_real || dirname( $target_real ) !== rtrim( $temp_real, DIRECTORY_SEPARATOR ) || ! str_starts_with( basename( $target_real ), $prefix ) ) {
			throw new RuntimeException( 'Cleanup refused an ambiguous private clone.' );
		}
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $target_real, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $iterator as $item ) {
			if ( $item->isLink() ) {
				throw new RuntimeException( 'Cleanup refused a symbolic link.' );
			}
			if ( $item->isDir() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removes only the guarded private clone.
				if ( ! rmdir( $item->getPathname() ) ) {
					throw new RuntimeException( 'A private clone directory could not be removed.' );
				}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes only a guarded private clone file.
			} elseif ( ! unlink( $item->getPathname() ) ) {
				throw new RuntimeException( 'A private clone file could not be removed.' );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removes only the guarded clone root.
		if ( ! rmdir( $target_real ) ) {
			throw new RuntimeException( 'The private clone root could not be removed.' );
		}
	}

	/**
	 * Selects a verifier-owned database in cloned configuration text.
	 *
	 * @param string $config Configuration text.
	 * @param string $database Database name.
	 * @param string $prefix Verifier database prefix.
	 * @throws RuntimeException When DB_NAME is not uniquely replaceable.
	 */
	public static function config_for_database( string $config, string $database, string $prefix ): string {
		self::assert_database( $database, $prefix );
		$pattern = '/^[ \t]*define\s*\(\s*([\'\"])DB_NAME\1\s*,.*?\)\s*;[ \t]*\r?$/m';
		if ( 1 !== preg_match_all( $pattern, $config ) ) {
			throw new RuntimeException( 'The cloned database definition was not uniquely replaceable.' );
		}
		$changed = preg_replace( $pattern, "define( 'DB_NAME', '" . $database . "' );", $config, 1, $count );
		if ( ! is_string( $changed ) || 1 !== $count ) {
			throw new RuntimeException( 'The cloned database could not be selected.' );
		}
		return $changed;
	}
}
