<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Support;

final class Autoloader {
	private const PREFIX = 'Dreamax\\LicenseManager\\';

	public static function register(): void {
		spl_autoload_register( array( self::class, 'load' ) );
	}

	private static function load( string $class ): void {
		if ( 0 !== strpos( $class, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class, strlen( self::PREFIX ) );
		$file     = DREAMAX_LM_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
