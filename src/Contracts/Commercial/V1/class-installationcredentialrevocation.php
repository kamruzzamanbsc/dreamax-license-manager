<?php
/**
 * Defines the InstallationCredentialRevocation contract value.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Contracts\Commercial\V1;

/** Requests revocation of an authenticated installation credential. */
final class InstallationCredentialRevocation extends ContractData {
	/**
	 * Returns the allowlisted fields.
	 *
	 * @return array<string,bool>
	 */
	protected static function fields(): array {
		return array(
			'credential_public_id' => true,
			'reason'               => true,
			'request_id'           => true,
		);
	}
}
