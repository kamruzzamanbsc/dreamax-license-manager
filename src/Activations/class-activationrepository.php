<?php
/**
 * Defines the ActivationRepository class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Activations;

/**
 * Reads privacy-conscious installation records.
 */
final class ActivationRepository {
	/**
	 * Returns bounded activation history for one license.
	 *
	 * @param int $license_id Internal license identifier.
	 * @param int $limit Maximum rows.
	 * @return list<array<string,mixed>>
	 */
	public function for_license( int $license_id, int $limit = 100 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Installation state must be current for customer management.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT public_id,license_id,instance_label,status,first_activated_at,activated_at,deactivated_at,last_seen_at FROM {$wpdb->prefix}dreamax_lm_activations WHERE license_id=%d ORDER BY id DESC LIMIT %d",
				$license_id,
				min( 100, max( 1, $limit ) )
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}
}
