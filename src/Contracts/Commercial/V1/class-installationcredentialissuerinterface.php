<?php
/**
 * Defines the InstallationCredentialIssuerInterface interface.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Contracts\Commercial\V1;

/** Issues, rotates, and revokes installation-scoped credentials. */
interface InstallationCredentialIssuerInterface {
	/**
	 * Issues one installation credential.
	 *
	 * @param InstallationCredentialRequest $request Verified issuance request.
	 */
	public function issue( InstallationCredentialRequest $request ): IssuedCredential;

	/**
	 * Atomically rotates one installation credential.
	 *
	 * @param InstallationCredentialRotation $request Authenticated rotation request.
	 */
	public function rotate( InstallationCredentialRotation $request ): IssuedCredential;

	/**
	 * Revokes one installation credential.
	 *
	 * @param InstallationCredentialRevocation $request Authenticated revocation request.
	 */
	public function revoke( InstallationCredentialRevocation $request ): CredentialRevocationResult;
}
