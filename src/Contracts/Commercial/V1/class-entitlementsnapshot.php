<?php
/**
 * Defines the EntitlementSnapshot contract value.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Contracts\Commercial\V1;

/** Carries one typed, versioned entitlement decision. */
final class EntitlementSnapshot extends ContractData {
	/**
	 * Returns the allowlisted fields.
	 *
	 * @return array<string,bool>
	 */
	protected static function fields(): array {
		return array(
			'allowed'         => true,
			'code'            => true,
			'grants'          => true,
			'source_revision' => true,
			'issued_at'       => true,
			'expires_at'      => false,
			'grace_ends_at'   => false,
		);
	}
}
