<?php
/**
 * Defines the OrderSlotPolicy class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Integrations\WooCommerce;

/**
 * Handles Order slot policy operations.
 */
final class OrderSlotPolicy {
	/**
	 * Handles the newly refunded slots operation.
	 *
	 * @param int $original_quantity Original quantity value.
	 * @param int $cumulative_refunded Cumulative refunded value.
	 * @param int $current_refund Current refund value.
	 * @return list<int>
	 */
	public function newly_refunded_slots( int $original_quantity, int $cumulative_refunded, int $current_refund ): array {
		$original   = max( 1, $original_quantity );
		$cumulative = min( $original, max( 0, $cumulative_refunded ) );
		$previous   = max( 0, $cumulative - max( 0, $current_refund ) );
		$first      = max( 1, $original - $cumulative + 1 );
		$last       = max( 0, $original - $previous );
		return $last >= $first ? range( $first, $last ) : array();
	}

	/**
	 * Handles the eligible slots operation.
	 *
	 * @param int    $current_quantity Current quantity value.
	 * @param int    $original_quantity Original quantity value.
	 * @param int    $cumulative_refunded Cumulative refunded value.
	 * @param string $issuance Issuance value.
	 * @return list<int>
	 */
	public function eligible_slots( int $current_quantity, int $original_quantity, int $cumulative_refunded, string $issuance ): array {
		$original   = max( 1, $original_quantity );
		$cumulative = min( $original, max( 0, $cumulative_refunded ) );
		if ( 'per_item' === $issuance ) {
			return $cumulative >= $original ? array() : array( 1 );
		}

		$current  = max( 0, $current_quantity );
		$refunded = array_flip( $this->newly_refunded_slots( $original, $cumulative, $cumulative ) );
		$eligible = array();
		for ( $slot = 1; $slot <= $current; ++$slot ) {
			if ( ! isset( $refunded[ $slot ] ) ) {
				$eligible[] = $slot;
			}
		}
		return $eligible;
	}

	/**
	 * Handles the per item refund applies operation.
	 *
	 * @param int $original_quantity Original quantity value.
	 * @param int $cumulative_refunded Cumulative refunded value.
	 * @param int $current_refund Current refund value.
	 */
	public function per_item_refund_applies( int $original_quantity, int $cumulative_refunded, int $current_refund ): bool {
		$original   = max( 1, $original_quantity );
		$cumulative = min( $original, max( 0, $cumulative_refunded ) );
		$previous   = max( 0, $cumulative - max( 0, $current_refund ) );
		return $previous < $original && $cumulative >= $original;
	}
}
