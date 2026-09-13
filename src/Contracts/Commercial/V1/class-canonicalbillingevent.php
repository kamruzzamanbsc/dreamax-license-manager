<?php
/**
 * Defines the CanonicalBillingEvent contract value.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Contracts\Commercial\V1;

/** Carries provider-neutral billing state for idempotent reconciliation. */
final class CanonicalBillingEvent extends ContractData {
	/**
	 * Returns the allowlisted fields.
	 *
	 * @return array<string,bool>
	 */
	protected static function fields(): array {
		return array(
			'provider'                 => true,
			'external_event_id'        => true,
			'external_subscription_id' => false,
			'event_type'               => true,
			'effective_at'             => true,
			'order_id'                 => false,
			'customer_id'              => false,
			'product_ids'              => true,
			'amount_minor'             => false,
			'currency'                 => false,
			'status'                   => true,
		);
	}
}
