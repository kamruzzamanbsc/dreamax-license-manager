<?php
/**
 * Defines the OrderAccessPolicy class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Integrations\WooCommerce;

/**
 * Applies defense-in-depth access checks after WooCommerce verifies its order context.
 */
final class OrderAccessPolicy {
	/**
	 * Determines whether order licenses may be displayed.
	 *
	 * @param int  $order_customer_id WooCommerce order customer ID, or zero for guest.
	 * @param int  $current_user_id Current WordPress user ID.
	 * @param bool $woocommerce_verified Whether execution is inside WooCommerce's verified order context.
	 * @param bool $valid_order_key Whether WooCommerce accepted the presented order key.
	 */
	public function allows( int $order_customer_id, int $current_user_id, bool $woocommerce_verified, bool $valid_order_key ): bool {
		if ( $order_customer_id > 0 ) {
			return $current_user_id > 0 && $current_user_id === $order_customer_id;
		}
		return $woocommerce_verified && $valid_order_key;
	}
}
