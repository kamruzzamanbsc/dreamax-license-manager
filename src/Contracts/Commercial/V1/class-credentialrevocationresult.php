<?php
/**
 * Defines the CredentialRevocationResult contract value.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Contracts\Commercial\V1;

/** Reports the result of credential revocation. */
final class CredentialRevocationResult extends ContractData {
	/**
	 * Returns the allowlisted fields.
	 *
	 * @return array<string,bool>
	 */
	protected static function fields(): array {
		return array(
			'revoked'    => true,
			'code'       => true,
			'revoked_at' => true,
		);
	}
}
