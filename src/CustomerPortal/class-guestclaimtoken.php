<?php
/**
 * Defines the GuestClaimToken class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\CustomerPortal;

use Dreamax\LicenseManager\Support\Base64Url;

/**
 * Generates and validates one-time guest claim proofs.
 */
final class GuestClaimToken {
	public const RANDOM_BYTES = 32;

	/**
	 * Generates 256 bits of cryptographic randomness encoded without padding.
	 */
	public function generate(): string {
		return Base64Url::encode( random_bytes( self::RANDOM_BYTES ) );
	}

	/**
	 * Checks the exact format emitted by generate().
	 *
	 * @param string $token Presented proof.
	 */
	public function valid_format( string $token ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_-]{43}$/D', $token );
	}
}
