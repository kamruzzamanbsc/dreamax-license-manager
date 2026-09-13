<?php
/**
 * Defines the PackageAuthorizationInterface interface.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Contracts\Commercial\V1;

/** Authorizes a short-lived package grant without exposing storage details. */
interface PackageAuthorizationInterface {
	/**
	 * Authorizes one bounded package grant request.
	 *
	 * @param PackageAuthorizationRequest $request Fully evaluated package request.
	 */
	public function authorize( PackageAuthorizationRequest $request ): PackageAuthorizationDecision;
}
