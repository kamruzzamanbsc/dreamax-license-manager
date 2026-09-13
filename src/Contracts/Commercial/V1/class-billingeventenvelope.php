<?php
/**
 * Defines the BillingEventEnvelope contract value.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Contracts\Commercial\V1;

/** Carries an authenticated, replay-identifiable billing event. */
final class BillingEventEnvelope extends ContractData {
	/**
	 * Returns the allowlisted fields.
	 *
	 * @return array<string,bool>
	 */
	protected static function fields(): array {
		return array(
			'provider'          => true,
			'external_event_id' => true,
			'event_type'        => true,
			'occurred_at'       => true,
			'payload_digest'    => true,
			'payload'           => true,
		);
	}
}
