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
	public const MIN_ENTROPY_BITS = 96.0;
	public const MAX_LENGTH       = 128;
	public const MAX_AFFIX_LENGTH = 32;
	public const MAX_KEY_LENGTH   = 512;

	/**
	 * Handles the generate operation.
	 *
	 * @param array $config Config value.
	 * @phpstan-param array<string,mixed> $config Config value.
	 */
	public function generate( array $config = array() ): string {
		$config    = $this->normalize_configuration( $config );
		$alphabet  = $config['alphabet'];
		$length    = $config['length'];
		$group     = $config['group'];
		$separator = $config['separator'];
		$prefix    = $config['prefix'];
		$suffix    = $config['suffix'];
		$max       = strlen( $alphabet ) - 1;
		$random    = '';
		for ( $i = 0; $i < $length; ++$i ) {
			$random .= $alphabet[ random_int( 0, $max ) ];
		}

		$body  = $group > 0 ? implode( $separator, str_split( $random, $group ) ) : $random;
		$parts = array_filter( array( $prefix, $body, $suffix ), static fn( string $part ): bool => '' !== $part );
		return implode( $separator, $parts );
	}

	/**
	 * Validates and normalizes a merchant-authored generator configuration.
	 *
	 * @param array $config Configuration value.
	 * @phpstan-param array<string,mixed> $config Configuration value.
	 * @return array{alphabet:string,length:int,group:int,separator:string,prefix:string,suffix:string}
	 */
	public function normalize_configuration( array $config ): array {
		$normalized = array(
			'alphabet'  => (string) ( $config['alphabet'] ?? self::DEFAULT_ALPHABET ),
			'length'    => (int) ( $config['length'] ?? self::DEFAULT_LENGTH ),
			'group'     => (int) ( $config['group'] ?? 5 ),
			'separator' => (string) ( $config['separator'] ?? '-' ),
			'prefix'    => (string) ( $config['prefix'] ?? 'DLM' ),
			'suffix'    => (string) ( $config['suffix'] ?? '' ),
		);

		$this->validate( $normalized );
		return $normalized;
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
	 * @param array $config Configuration value.
	 * @phpstan-param array{alphabet:string,length:int,group:int,separator:string,prefix:string,suffix:string} $config Configuration value.
	 * @throws InvalidArgumentException When the operation cannot be completed.
	 */
	private function validate( array $config ): void {
		$alphabet  = $config['alphabet'];
		$length    = $config['length'];
		$group     = $config['group'];
		$separator = $config['separator'];
		$prefix    = $config['prefix'];
		$suffix    = $config['suffix'];

		if ( 1 !== strlen( $separator ) || ord( $separator ) < 33 || ord( $separator ) > 126 || ctype_alnum( $separator ) ) {
			throw new InvalidArgumentException( 'The separator must be one printable non-alphanumeric ASCII character.' );
		}
		if ( 2 > strlen( $alphabet ) || 64 < strlen( $alphabet ) || 1 !== preg_match( '/^[A-Za-z0-9]+$/D', $alphabet ) ) {
			throw new InvalidArgumentException( 'The alphabet must contain 2 to 64 ASCII letters or numbers.' );
		}
		$symbols = str_split( $alphabet );
		if ( count( $symbols ) !== count( array_unique( $symbols ) ) || in_array( $separator, $symbols, true ) ) {
			throw new InvalidArgumentException( 'The alphabet must be unique and must not contain the separator.' );
		}
		if ( $length < 1 || $length > self::MAX_LENGTH ) {
			throw new InvalidArgumentException( 'The random length must be between 1 and 128 characters.' );
		}
		if ( $group < 0 || $group > $length ) {
			throw new InvalidArgumentException( 'The group length must be zero or no greater than the random length.' );
		}
		if ( strlen( $prefix ) > self::MAX_AFFIX_LENGTH || ( '' !== $prefix && 1 !== preg_match( '/^[A-Za-z0-9]+$/D', $prefix ) ) ) {
			throw new InvalidArgumentException( 'The prefix must contain at most 32 ASCII letters or numbers.' );
		}
		if ( strlen( $suffix ) > self::MAX_AFFIX_LENGTH || ( '' !== $suffix && 1 !== preg_match( '/^[A-Za-z0-9]+$/D', $suffix ) ) ) {
			throw new InvalidArgumentException( 'The suffix must contain at most 32 ASCII letters or numbers.' );
		}
		if ( $this->entropy_bits( $alphabet, $length ) < self::MIN_ENTROPY_BITS ) {
			throw new InvalidArgumentException( 'Generator configuration is below the 96-bit security floor.' );
		}

		$groups          = $group > 0 ? (int) ceil( $length / $group ) : 1;
		$separator_count = max( 0, $groups - 1 ) + ( '' !== $prefix ? 1 : 0 ) + ( '' !== $suffix ? 1 : 0 );
		$total_length    = $length + strlen( $prefix ) + strlen( $suffix ) + $separator_count;
		if ( $total_length > self::MAX_KEY_LENGTH ) {
			throw new InvalidArgumentException( 'The generated license key would exceed 512 characters.' );
		}
	}
}
