<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Support;

use InvalidArgumentException;

final class Base64Url {
	public static function encode( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	public static function decode( string $encoded ): string {
		if ( ! preg_match( '/^[A-Za-z0-9_-]+$/D', $encoded ) ) {
			throw new InvalidArgumentException( 'Invalid base64url value.' );
		}

		$padded = $encoded . str_repeat( '=', ( 4 - strlen( $encoded ) % 4 ) % 4 );
		$result = base64_decode( strtr( $padded, '-_', '+/' ), true );

		if ( false === $result ) {
			throw new InvalidArgumentException( 'Invalid base64url value.' );
		}

		return $result;
	}
}
