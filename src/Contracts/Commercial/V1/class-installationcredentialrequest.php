<?php
/**
 * Defines the InstallationCredentialRequest contract value.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Contracts\Commercial\V1;

/** Requests a credential for an authoritative installation decision. */
final class InstallationCredentialRequest extends ContractData {
	/**
	 * Returns the allowlisted fields.
	 *
	 * @return array<string,bool>
	 */
	protected static function fields(): array {
		return array(
			'installation' => true,
			'scopes'       => true,
			'expires_at'   => true,
			'request_id'   => true,
		);
	}
}
