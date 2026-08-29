<?php

declare(strict_types=1);

defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'Dreamax\\LicenseManager\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$relative   = substr( $class, strlen( $prefix ) );
		$parts      = explode( '\\', $relative );
		$class_file = 'class-' . strtolower( (string) array_pop( $parts ) ) . '.php';
		$directory  = $parts ? implode( '/', $parts ) . '/' : '';
		$file       = dirname( __DIR__ ) . '/src/' . $directory . $class_file;
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);
