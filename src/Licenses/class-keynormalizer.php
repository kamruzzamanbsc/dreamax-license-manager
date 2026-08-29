<?php
/**
 * Defines the KeyNormalizer class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Licenses;

use InvalidArgumentException;

/**
 * Handles Key normalizer operations.
 */
final class KeyNormalizer {
	public const GENERATED = 'generated-ascii-v1';
	public const IMPORTED  = 'import-exact-v1';

	/**
	 * Handles the normalize operation.
	 *
	 * @param string  $value Value value.
	 * @param string  $profile Profile value.
	 * @param ?string $separator Separator value.
	 * @phpstan-param string|null $separator Separator value.
	 * @param bool    $file_record File record value.
	 * @throws InvalidArgumentException When the operation cannot be completed.
	 */
	public function normalize( string $value, string $profile, ?string $separator = null, bool $file_record = false ): string {
		if ( 1 !== preg_match( '//u', $value ) ) {
			throw new InvalidArgumentException( 'The license key is not valid UTF-8.' );
		}

		if ( self::IMPORTED === $profile ) {
			if ( $file_record ) {
				$value = preg_replace( '/^\xEF\xBB\xBF/', '', $value ) ?? $value;
				$value = preg_replace( '/(?:\r\n|\n|\r)$/D', '', $value ) ?? $value;
			}
			if ( '' === $value ) {
				throw new InvalidArgumentException( 'The license key cannot be empty.' );
			}
			return $value;
		}

		if ( self::GENERATED !== $profile ) {
			throw new InvalidArgumentException( 'Unknown normalization profile.' );
		}

		if ( null === $separator || 1 !== strlen( $separator ) || ord( $separator ) > 127 || ctype_space( $separator ) ) {
			throw new InvalidArgumentException( 'Generated keys require one declared ASCII display separator.' );
		}

		$value = preg_replace( '/^[\x09\x0A\x0D\x20]+|[\x09\x0A\x0D\x20]+$/D', '', $value ) ?? $value;
		if ( preg_match( '/[^\x20-\x7E]/', $value ) ) {
			throw new InvalidArgumentException( 'Generated keys may contain printable ASCII only.' );
		}
		if ( preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
			throw new InvalidArgumentException( 'Generated keys may not contain control characters.' );
		}

		$allowed_punctuation = preg_quote( $separator, '/' );
		if ( preg_match( '/[^A-Za-z0-9 ' . $allowed_punctuation . ']/', $value ) ) {
			throw new InvalidArgumentException( 'Generated key contains undeclared punctuation.' );
		}

		$canonical = strtoupper( str_replace( array( ' ', $separator ), '', $value ) );
		if ( '' === $canonical ) {
			throw new InvalidArgumentException( 'The canonical key cannot be empty.' );
		}

		return $canonical;
	}
}
