<?php
/**
 * Defines the Base64Url class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Support;

use InvalidArgumentException;

/**
 * Handles Base64 url operations.
 */
final class Base64Url {
	/**
	 * Handles the encode operation.
	 *
	 * @param string $bytes Bytes value.
	 */
	public static function encode( string $bytes ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- This is URL-safe binary encoding, not code obfuscation.
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	/**
	 * Handles the decode operation.
	 *
	 * @param string $encoded Encoded value.
	 * @throws InvalidArgumentException When the operation cannot be completed.
	 */
	public static function decode( string $encoded ): string {
		if ( ! preg_match( '/^[A-Za-z0-9_-]+$/D', $encoded ) ) {
			throw new InvalidArgumentException( 'Invalid base64url value.' );
		}

		$padded = $encoded . str_repeat( '=', ( 4 - strlen( $encoded ) % 4 ) % 4 );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- This decodes validated URL-safe binary data, not executable code.
		$result = base64_decode( strtr( $padded, '-_', '+/' ), true );

		if ( false === $result ) {
			throw new InvalidArgumentException( 'Invalid base64url value.' );
		}

		return $result;
	}
}
