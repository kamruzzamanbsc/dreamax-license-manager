<?php
/**
 * Defines the InstallationCredentialRotation contract value.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Contracts\Commercial\V1;

/** Requests atomic replacement of an authenticated installation credential. */
final class InstallationCredentialRotation extends ContractData {
	/**
	 * Returns the allowlisted fields.
	 *
	 * @return array<string,bool>
	 */
	protected static function fields(): array {
		return array(
			'credential_public_id'       => true,
			'authenticated_installation' => true,
			'request_id'                 => true,
		);
	}
}
