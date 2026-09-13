<?php
/**
 * Defines the EntitlementRequest contract value.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Contracts\Commercial\V1;

/** Carries verified context for an entitlement decision. */
final class EntitlementRequest extends ContractData {
	/**
	 * Returns the allowlisted fields.
	 *
	 * @return array<string,bool>
	 */
	protected static function fields(): array {
		return array(
			'core_license'       => true,
			'installation'       => false,
			'product_public_id'  => true,
			'plan_public_id'     => false,
			'channel'            => false,
			'requested_features' => true,
		);
	}
}
