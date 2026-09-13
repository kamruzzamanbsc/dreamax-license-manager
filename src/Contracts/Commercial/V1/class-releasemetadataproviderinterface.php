<?php
/**
 * Defines the ReleaseMetadataProviderInterface interface.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Contracts\Commercial\V1;

/** Resolves signed release metadata for an authorized installation. */
interface ReleaseMetadataProviderInterface {
	/**
	 * Resolves one release metadata request.
	 *
	 * @param ReleaseMetadataRequest $request Authenticated update request.
	 */
	public function resolve( ReleaseMetadataRequest $request ): ReleaseMetadataDecision;
}
