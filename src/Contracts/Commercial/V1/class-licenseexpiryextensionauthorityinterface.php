<?php
/**
 * Defines the LicenseExpiryExtensionAuthorityInterface interface.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Contracts\Commercial\V1;

/** Owns product-bound license expiry extension writes in Free. */
interface LicenseExpiryExtensionAuthorityInterface {
	/**
	 * Applies one validated expiry extension command.
	 *
	 * @param LicenseExpiryExtensionCommand $command Validated command.
	 */
	public function extend( LicenseExpiryExtensionCommand $command ): LicenseExpiryExtensionResult;
}
