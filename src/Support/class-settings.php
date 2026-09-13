<?php
/**
 * Defines the Settings class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Support;

use Dreamax\LicenseManager\Encryption\MasterKey;
use Throwable;

/**
 * Provides bounded merchant configuration used by operational services.
 */
final class Settings {
	public const OPTION_ALLOCATION_STATUSES            = 'dreamax_lm_allocation_statuses';
	public const OPTION_CUSTOMER_ACTIVATION_MANAGEMENT = 'dreamax_lm_customer_activation_management';
	public const OPTION_BACKUP_CONFIRMED_KEY_ID        = 'dreamax_lm_backup_confirmed_key_id';
	public const OPTION_TRUSTED_PROXIES                = 'dreamax_lm_trusted_proxies';

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

	/**
	 * Reports whether backup coverage was confirmed for the current key identity.
	 */
	public static function backup_confirmed(): bool {
		try {
			$current = ( new MasterKey() )->identifier();
		} catch ( Throwable $error ) {
			return false;
		}
		$confirmed = get_option( self::OPTION_BACKUP_CONFIRMED_KEY_ID, '' );
		return is_string( $confirmed ) && hash_equals( $current, $confirmed );
	}

	/**
	 * Records or clears backup acknowledgement for the loaded key identity.
	 *
	 * @param bool $confirmed Whether the current key backup has been confirmed.
	 */
	public static function confirm_backup( bool $confirmed ): void {
		if ( ! $confirmed ) {
			delete_option( self::OPTION_BACKUP_CONFIRMED_KEY_ID );
			return;
		}
		try {
			update_option( self::OPTION_BACKUP_CONFIRMED_KEY_ID, ( new MasterKey() )->identifier(), false );
		} catch ( Throwable $error ) {
			delete_option( self::OPTION_BACKUP_CONFIRMED_KEY_ID );
		}
	}

	/**
	 * Returns exact proxy IPs allowed to supply forwarding headers.
	 *
	 * @return list<string>
	 */
	public static function trusted_proxies(): array {
		$stored = get_option( self::OPTION_TRUSTED_PROXIES, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$result = array();
		foreach ( $stored as $address ) {
			$address = trim( (string) $address );
			if ( false !== filter_var( $address, FILTER_VALIDATE_IP ) && ! in_array( $address, $result, true ) ) {
				$result[] = $address;
			}
		}
		return array_slice( $result, 0, 100 );
	}
}
