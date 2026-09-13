<?php
/**
 * Defines the private temporary-file boundary for license CSV data.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\ImportExport;

use RuntimeException;

/**
 * Refuses WordPress's web-root fallback for sensitive temporary files.
 */
final class PrivateTempFile {
	/**
	 * Creates a restricted temporary file outside every known web root.
	 *
	 * @param string $filename Fixed plugin-owned file prefix.
	 * @throws RuntimeException When no private writable location is available.
	 */
	public static function create( string $filename ): string {
		$path = wp_tempnam( $filename );
		if ( ! is_string( $path ) || '' === $path ) {
			throw new RuntimeException( 'A private temporary file could not be created.' );
		}
		$roots = array( ABSPATH, WP_CONTENT_DIR );
		if ( ! empty( $_SERVER['DOCUMENT_ROOT'] ) && is_string( $_SERVER['DOCUMENT_ROOT'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- The server path is not request content; realpath resolves it before a fail-closed containment comparison.
			$roots[] = $_SERVER['DOCUMENT_ROOT'];
		}
		if ( ! self::outside_roots( $path, $roots ) ) {
			wp_delete_file( $path );
			throw new RuntimeException( 'A private temporary directory is required.' );
		}
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
		$filesystem = new \WP_Filesystem_Direct( false );
		if ( ! $filesystem->chmod( $path, 0600 ) ) {
			wp_delete_file( $path );
			throw new RuntimeException( 'The temporary file could not be restricted.' );
		}
		return $path;
	}

	/**
	 * Checks resolved paths so symlinks cannot place a temporary file in the web root.
	 *
	 * @param string $path Temporary file path.
	 * @param array  $roots Known web-accessible roots.
	 * @phpstan-param list<string> $roots Known web-accessible roots.
	 */
	public static function outside_roots( string $path, array $roots ): bool {
		$resolved_path = realpath( $path );
		if ( false === $resolved_path ) {
			return false;
		}
		$candidate = self::normalized( $resolved_path );
		foreach ( $roots as $root ) {
			$resolved_root = realpath( $root );
			if ( false === $resolved_root ) {
				return false;
			}
			$prefix = rtrim( self::normalized( $resolved_root ), '/' );
			if ( $candidate === $prefix || str_starts_with( $candidate, $prefix . '/' ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Normalizes Windows separators and case for a path containment check.
	 *
	 * @param string $path Resolved path.
	 */
	private static function normalized( string $path ): string {
		$path = str_replace( '\\', '/', $path );
		return 'Windows' === PHP_OS_FAMILY ? strtolower( $path ) : $path;
	}
}
