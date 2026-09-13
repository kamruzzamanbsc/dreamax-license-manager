<?php
/**
 * Defines the ReleaseMetadataDecision contract value.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Contracts\Commercial\V1;

/** Reports an allowlisted release manifest decision. */
final class ReleaseMetadataDecision extends ContractData {
	/**
	 * Returns the allowlisted fields.
	 *
	 * @return array<string,bool>
	 */
	protected static function fields(): array {
		return array(
			'allowed'      => true,
			'code'         => true,
			'release'      => false,
			'evaluated_at' => true,
		);
	}
}
