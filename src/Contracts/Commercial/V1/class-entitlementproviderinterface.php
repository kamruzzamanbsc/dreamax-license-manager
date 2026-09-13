<?php
/**
 * Defines the EntitlementProviderInterface interface.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Contracts\Commercial\V1;

/** Resolves additive commercial entitlements without overriding Free denials. */
interface EntitlementProviderInterface {
	/**
	 * Resolves one entitlement request.
	 *
	 * @param EntitlementRequest $request Verified entitlement context.
	 */
	public function resolve( EntitlementRequest $request ): EntitlementSnapshot;
}
