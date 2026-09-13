<?php
/**
 * Defines the ReleaseMetadataRequest contract value.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Contracts\Commercial\V1;

/** Requests release metadata for one authenticated installation. */
final class ReleaseMetadataRequest extends ContractData {
	/**
	 * Returns the allowlisted fields.
	 *
	 * @return array<string,bool>
	 */
	protected static function fields(): array {
		return array(
			'installation'        => true,
			'software_public_id'  => true,
			'installed_version'   => true,
			'channel'             => true,
			'wordpress_version'   => false,
			'php_version'         => true,
			'woocommerce_version' => false,
			'rollout_identity'    => true,
		);
	}
}
