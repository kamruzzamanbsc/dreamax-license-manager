<?php
/**
 * Defines the PackageAuthorizationDecision contract value.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Contracts\Commercial\V1;

/** Reports a short-lived package grant decision. */
final class PackageAuthorizationDecision extends ContractData {
	/**
	 * Returns the allowlisted fields.
	 *
	 * @return array<string,bool>
	 */
	protected static function fields(): array {
		return array(
			'allowed'         => true,
			'code'            => true,
			'grant_public_id' => false,
			'expires_at'      => false,
			'uses_remaining'  => false,
		);
	}
}
