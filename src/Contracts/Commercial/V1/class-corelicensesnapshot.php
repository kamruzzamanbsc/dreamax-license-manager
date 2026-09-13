<?php
/**
 * Defines the CoreLicenseSnapshot class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Contracts\Commercial\V1;

/**
 * Carries a sanitized view of Free-owned license state.
 */
final class CoreLicenseSnapshot extends ContractData {
	/**
	 * Returns the allowlisted fields.
	 *
	 * @return array<string,bool>
	 */
	protected static function fields(): array {
		return array(
			'license_public_id' => true,
			'product_public_id' => true,
			'lifecycle_status'  => true,
			'activation_limit'  => true,
			'expires_at'        => true,
			'order_id'          => true,
			'order_item_id'     => true,
			'customer_id'       => true,
			'evaluated_at'      => true,
		);
	}
}
