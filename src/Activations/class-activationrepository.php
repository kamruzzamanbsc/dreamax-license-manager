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
	 * Returns a filtered page of activations with non-secret license context.
	 *
	 * @param string $status Active/inactive filter.
	 * @param string $license_public_id Public license identifier filter.
	 * @param int    $page One-based page.
	 * @return list<array<string,mixed>>
	 */
	public function search( string $status = '', string $license_public_id = '', int $page = 1 ): array {
		global $wpdb;
		$status            = in_array( $status, array( 'active', 'inactive' ), true ) ? $status : '';
		$license_public_id = sanitize_text_field( $license_public_id );
		$offset            = ( max( 1, $page ) - 1 ) * 50;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Administration inventory requires current operational state.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.public_id,a.license_id,a.instance_label,a.status,a.first_activated_at,a.activated_at,a.deactivated_at,a.last_seen_at,l.public_id AS license_public_id,l.product_public_id,l.customer_id
				FROM {$wpdb->prefix}dreamax_lm_activations a
				INNER JOIN {$wpdb->prefix}dreamax_lm_licenses l ON l.id=a.license_id
				WHERE (%s='' OR a.status=%s) AND (%s='' OR l.public_id=%s)
				ORDER BY a.updated_at DESC,a.id DESC LIMIT 50 OFFSET %d",
				$status,
				$status,
				$license_public_id,
				$license_public_id,
				$offset
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

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
