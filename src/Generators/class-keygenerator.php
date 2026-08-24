<?php
/**
 * Defines the KeyGenerator class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Generators;

use InvalidArgumentException;

/**
 * Handles Key generator operations.
 */
final class KeyGenerator {
	public const DEFAULT_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
	public const DEFAULT_LENGTH   = 26;

	/**
	 * Handles the generate operation.
	 *
	 * @param array $config Config value.
	 * @phpstan-param array<string,mixed> $config Config value.
	 */
	public function generate( array $config = array() ): string {
		$alphabet  = (string) ( $config['alphabet'] ?? self::DEFAULT_ALPHABET );
		$length    = (int) ( $config['length'] ?? self::DEFAULT_LENGTH );
		$group     = (int) ( $config['group'] ?? 5 );
		$separator = (string) ( $config['separator'] ?? '-' );
		$prefix    = (string) ( $config['prefix'] ?? 'DLM' );
		$suffix    = (string) ( $config['suffix'] ?? '' );

		$this->validate( $alphabet, $length, $separator );
		$max    = strlen( $alphabet ) - 1;
		$random = '';
		for ( $i = 0; $i < $length; ++$i ) {
			$random .= $alphabet[ random_int( 0, $max ) ];
		}

		$body  = $group > 0 ? implode( $separator, str_split( $random, $group ) ) : $random;
		$parts = array_filter( array( $prefix, $body, $suffix ), static fn( string $part ): bool => '' !== $part );
		return implode( $separator, $parts );
	}

	/**
	 * Handles the entropy bits operation.
	 *
	 * @param string $alphabet Alphabet value.
	 * @param int    $length Length value.
	 * @throws InvalidArgumentException When the operation cannot be completed.
	 */
	public function entropy_bits( string $alphabet, int $length ): float {
		$unique = count( array_unique( str_split( $alphabet ) ) );
		if ( $unique < 2 || $length < 1 ) {
			throw new InvalidArgumentException( 'Entropy requires at least two canonical symbols and one random position.' );
		}
		return $length * log( (float) $unique, 2.0 );
	}

	/**
	 * Handles the validate operation.
	 *
	 * @param string $alphabet Alphabet value.
	 * @param int    $length Length value.
	 * @param string $separator Separator value.
	 * @throws InvalidArgumentException When the operation cannot be completed.
	 */
	private function validate( string $alphabet, int $length, string $separator ): void {
		if ( 1 !== strlen( $separator ) || ord( $separator ) > 127 || ctype_space( $separator ) ) {
			throw new InvalidArgumentException( 'The separator must be one printable non-whitespace ASCII character.' );
		}
		$symbols = str_split( $alphabet );
		if ( count( $symbols ) !== count( array_unique( $symbols ) ) || in_array( $separator, $symbols, true ) ) {
			throw new InvalidArgumentException( 'The alphabet must be unique and must not contain the separator.' );
		}
		if ( $this->entropy_bits( $alphabet, $length ) < 96.0 ) {
			throw new InvalidArgumentException( 'Generator configuration is below the 96-bit security floor.' );
		}
	}
}
