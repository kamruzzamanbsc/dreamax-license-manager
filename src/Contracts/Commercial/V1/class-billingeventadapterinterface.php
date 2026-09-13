<?php
/**
 * Defines the BillingEventAdapterInterface interface.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Contracts\Commercial\V1;

/** Normalizes authenticated provider events without mutating Free state. */
interface BillingEventAdapterInterface {
	/** Returns the stable provider identifier. */
	public function provider(): string;

	/**
	 * Normalizes an authenticated provider event.
	 *
	 * @param BillingEventEnvelope $event Authenticated provider event.
	 */
	public function normalize( BillingEventEnvelope $event ): CanonicalBillingEvent;
}
