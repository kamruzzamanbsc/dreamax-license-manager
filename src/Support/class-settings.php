<?php
/**
 * Defines the Settings class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Support;

/**
 * Provides bounded merchant configuration used by operational services.
 */
final class Settings {
	public const OPTION_ALLOCATION_STATUSES            = 'dreamax_lm_allocation_statuses';
	public const OPTION_CUSTOMER_ACTIVATION_MANAGEMENT = 'dreamax_lm_customer_activation_management';

	/**
	 * Returns normalized WooCommerce statuses that trigger automatic allocation.
	 *
	 * @return list<string>
	 */
	public static function allocation_statuses(): array {
		$stored = get_option( self::OPTION_ALLOCATION_STATUSES, array( 'processing', 'completed' ) );
		$stored = is_array( $stored ) ? $stored : array();
		$known  = self::order_statuses();
		$result = array();
		foreach ( $stored as $status ) {
			$status = sanitize_key( (string) $status );
			if ( isset( $known[ $status ] ) && ! in_array( $status, $result, true ) ) {
				$result[] = $status;
			}
		}

		return array() !== $result ? $result : array( 'processing', 'completed' );
	}

	/**
	 * Returns customer-facing order status choices without WooCommerce prefixes.
	 *
	 * @return array<string,string>
	 */
	public static function order_statuses(): array {
		$statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array(
			'wc-processing' => 'Processing',
			'wc-completed'  => 'Completed',
		);
		$result   = array();
		foreach ( $statuses as $status => $label ) {
			$result[ preg_replace( '/^wc-/', '', (string) $status ) ?? '' ] = (string) $label;
		}
		unset( $result[''] );
		return $result;
	}

	/**
	 * Reports whether customers may activate and deactivate owned installations.
	 */
	public static function customer_activation_management(): bool {
		return 1 === (int) get_option( self::OPTION_CUSTOMER_ACTIVATION_MANAGEMENT, 1 );
	}
}
