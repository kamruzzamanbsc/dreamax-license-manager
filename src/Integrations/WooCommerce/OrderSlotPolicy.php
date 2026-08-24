<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Integrations\WooCommerce;

final class OrderSlotPolicy {
	/** @return list<int> */
	public function newly_refunded_slots( int $original_quantity, int $cumulative_refunded, int $current_refund ): array {
		$original   = max( 1, $original_quantity );
		$cumulative = min( $original, max( 0, $cumulative_refunded ) );
		$previous   = max( 0, $cumulative - max( 0, $current_refund ) );
		$first      = max( 1, $original - $cumulative + 1 );
		$last       = max( 0, $original - $previous );
		return $last >= $first ? range( $first, $last ) : array();
	}

	/** @return list<int> */
	public function eligible_slots( int $current_quantity, int $original_quantity, int $cumulative_refunded, string $issuance ): array {
		$original   = max( 1, $original_quantity );
		$cumulative = min( $original, max( 0, $cumulative_refunded ) );
		if ( 'per_item' === $issuance ) {
			return $cumulative >= $original ? array() : array( 1 );
		}

		$current = max( 0, $current_quantity );
		$refunded = array_flip( $this->newly_refunded_slots( $original, $cumulative, $cumulative ) );
		$eligible = array();
		for ( $slot = 1; $slot <= $current; ++$slot ) {
			if ( ! isset( $refunded[ $slot ] ) ) {
				$eligible[] = $slot;
			}
		}
		return $eligible;
	}

	public function per_item_refund_applies( int $original_quantity, int $cumulative_refunded, int $current_refund ): bool {
		$original   = max( 1, $original_quantity );
		$cumulative = min( $original, max( 0, $cumulative_refunded ) );
		$previous   = max( 0, $cumulative - max( 0, $current_refund ) );
		return $previous < $original && $cumulative >= $original;
	}
}
