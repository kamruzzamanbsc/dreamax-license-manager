<?php
/**
 * Defines the PackageAuthorizationRequest contract value.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Contracts\Commercial\V1;

/** Requests a bounded package grant after all authority checks. */
final class PackageAuthorizationRequest extends ContractData {
	/**
	 * Returns the allowlisted fields.
	 *
	 * @return array<string,bool>
	 */
	protected static function fields(): array {
		return array(
			'credential_public_id' => true,
			'installation'         => true,
			'entitlement'          => true,
			'artifact_public_id'   => true,
			'release_public_id'    => true,
			'request_id'           => true,
		);
	}
}
