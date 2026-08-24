<?php

declare(strict_types=1);

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'Dreamax\\LicenseManager\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$file = dirname( __DIR__ ) . '/src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);
