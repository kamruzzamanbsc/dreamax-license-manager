<?php
/**
 * Defines the Autoloader class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Support;

/**
 * Handles Autoloader operations.
 */
final class Autoloader {
	private const PREFIX = 'Dreamax\\LicenseManager\\';

	/**
	 * Handles the register operation.
	 */
	public static function register(): void {
		spl_autoload_register( array( self::class, 'load' ) );
	}

	/**
	 * Handles the load operation.
	 *
	 * @param string $class_name Class name value.
	 */
	private static function load( string $class_name ): void {
		if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
			return;
		}

		$relative   = substr( $class_name, strlen( self::PREFIX ) );
		$parts      = explode( '\\', $relative );
		$class_file = 'class-' . strtolower( (string) array_pop( $parts ) ) . '.php';
		$directory  = $parts ? implode( '/', $parts ) . '/' : '';
		$file       = DREAMAX_LM_DIR . 'src/' . $directory . $class_file;

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
