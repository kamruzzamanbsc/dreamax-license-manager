<?php
/**
 * Defines the CoreAuthorityInterface interface.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Contracts\Commercial\V1;

/**
 * Exposes only sanitized, authoritative Free decisions to extensions.
 */
interface CoreAuthorityInterface {
	/**
	 * Returns one sanitized license snapshot by opaque public identifier.
	 *
	 * @param string $license_public_id Opaque license identifier.
	 */
	public function license_by_public_id( string $license_public_id ): ?CoreLicenseSnapshot;

	/**
	 * Returns sanitized licenses allocated to one WooCommerce order item.
	 *
	 * @param int $order_item_id WooCommerce order-item identifier.
	 * @return list<CoreLicenseSnapshot>
	 */
	public function licenses_for_order_item( int $order_item_id ): array;

	/**
	 * Evaluates a presented license through authoritative Free policy.
	 *
	 * @param LicenseProof $proof In-memory license proof.
	 */
	public function evaluate_license( LicenseProof $proof ): LicenseDecision;

	/**
	 * Verifies an active installation through authoritative Free policy.
	 *
	 * @param InstallationProof $proof In-memory installation proof.
	 */
	public function verify_installation( InstallationProof $proof ): InstallationDecision;
}
