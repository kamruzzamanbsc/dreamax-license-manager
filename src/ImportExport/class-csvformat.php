<?php
/**
 * Defines the CsvFormat class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\ImportExport;

use RuntimeException;

/**
 * Normalizes the deliberately small CSV format surface.
 */
final class CsvFormat {
	/**
	 * Resolves a merchant-facing delimiter choice.
	 *
	 * @param string $name Submitted delimiter name.
	 */
	public static function delimiter( string $name ): string {
		return match ( $name ) {
			'semicolon' => ';',
			'tab'       => "\t",
			default     => ',',
		};
	}

	/**
	 * Resolves an allowlisted source encoding.
	 *
	 * @param string $name Submitted encoding name.
	 */
	public static function encoding( string $name ): string {
		return match ( strtoupper( $name ) ) {
			'WINDOWS-1252' => 'Windows-1252',
			'ISO-8859-1'   => 'ISO-8859-1',
			default        => 'UTF-8',
		};
	}

	/**
	 * Converts one CSV cell to validated UTF-8.
	 *
	 * @param string $value Raw cell bytes.
	 * @param string $encoding Allowlisted source encoding.
	 * @throws RuntimeException When conversion fails.
	 */
	public static function decode( string $value, string $encoding ): string {
		if ( 'UTF-8' !== $encoding ) {
			if ( function_exists( 'mb_convert_encoding' ) ) {
				$value = mb_convert_encoding( $value, 'UTF-8', $encoding );
			} elseif ( function_exists( 'iconv' ) ) {
				$converted = iconv( $encoding, 'UTF-8', $value );
				if ( false === $converted ) {
					throw new RuntimeException( 'A CSV value could not be converted to UTF-8.' );
				}
				$value = $converted;
			} else {
				throw new RuntimeException( 'This server cannot convert the selected CSV encoding.' );
			}
		}

		if ( 1 !== preg_match( '//u', $value ) ) {
			throw new RuntimeException( 'The CSV contains invalid UTF-8.' );
		}
		return $value;
	}

	/**
	 * Produces a stable field-map key from a source heading.
	 *
	 * @param string $value Decoded source heading.
	 */
	public static function heading( string $value ): string {
		$value = preg_replace( '/^\xEF\xBB\xBF/', '', $value ) ?? $value;
		$value = strtolower( trim( $value ) );
		return trim( (string) preg_replace( '/[^a-z0-9]+/', '_', $value ), '_' );
	}
}
