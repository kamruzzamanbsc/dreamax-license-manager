<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Integrations\WooCommerce;

use Dreamax\LicenseManager\Database\Transaction;
use Dreamax\LicenseManager\Events\EventRepository;
use Dreamax\LicenseManager\Licenses\LicenseRepository;
use RuntimeException;

final class OrderPolicyService {
	private LicenseRepository $licenses;
	private EventRepository $events;
	private Transaction $transaction;
	private ProductPolicy $products;
	private OrderSlotPolicy $slots;

	public function __construct() {
		$this->licenses    = new LicenseRepository();
		$this->events      = new EventRepository();
		$this->transaction = new Transaction();
		$this->products    = new ProductPolicy();
		$this->slots       = new OrderSlotPolicy();
	}

	/** @return array{affected:int,replayed:int,unmapped:int} */
	public function refund( int $order_id, int $refund_id ): array {
		$order  = wc_get_order( $order_id );
		$refund = wc_get_order( $refund_id );
		if ( ! $order || ! $refund || ! is_a( $refund, 'WC_Order_Refund' ) || (int) $refund->get_parent_id() !== $order_id ) {
			throw new RuntimeException( 'The refund could not be matched to its order.' );
		}

		$result = array( 'affected' => 0, 'replayed' => 0, 'unmapped' => 0 );
		$refund_items = $refund->get_items( 'line_item' );
		if ( array() === $refund_items ) {
			if ( $order->has_status( 'refunded' ) ) {
				foreach ( $this->licenses->for_order( $order_id ) as $license ) {
					$this->count_result( $result, $this->apply( $license, $this->policy_for( $license, 'refund' ), 'refund', $order_id, $refund_id, 'refund:' . $refund_id . ':' . (int) $license['id'] ) );
				}
			} else {
				++$result['unmapped'];
				$this->events->append( 'order_refund_unmapped', null, 'woocommerce', null, 'refund:' . $refund_id, array( 'order_id' => $order_id, 'refund_id' => $refund_id, 'reason' => 'No refunded line-item quantity was provided.' ) );
			}
			return $result;
		}

		foreach ( $refund_items as $refund_item ) {
			$item_id = (int) $refund_item->get_meta( '_refunded_item_id', true );
			$order_item = $order->get_item( $item_id );
			$current_quantity = abs( (int) $refund_item->get_quantity() );
			if ( ! $order_item || $current_quantity < 1 ) {
				++$result['unmapped'];
				continue;
			}

			$snapshot_quantity = (int) $order_item->get_meta( '_dreamax_lm_delivery_quantity', true );
			$delivery_target   = (int) $order_item->get_meta( '_dreamax_lm_delivery_target_slots', true );
			$original_quantity = max( 1, $snapshot_quantity, $delivery_target, (int) $order_item->get_quantity() );
			$cumulative = min( $original_quantity, abs( (int) $order->get_qty_refunded_for_item( $item_id ) ) );
			$rows       = $this->licenses->for_order_item( $item_id );
			$issuance   = $this->issuance_for( $rows, $order_item );
			if ( 'per_item' === $issuance ) {
				if ( $this->slots->per_item_refund_applies( $original_quantity, $cumulative, $current_quantity ) ) {
					foreach ( $rows as $license ) {
						$this->count_result( $result, $this->apply( $license, $this->policy_for( $license, 'refund' ), 'refund', $order_id, $refund_id, 'refund:' . $refund_id . ':' . (int) $license['id'] ) );
					}
				}
				continue;
			}

			$refunded_slots = array_flip( $this->slots->newly_refunded_slots( $original_quantity, $cumulative, $current_quantity ) );
			foreach ( $rows as $license ) {
				$slot = (int) $license['quantity_slot'];
				if ( isset( $refunded_slots[ $slot ] ) ) {
					$this->count_result( $result, $this->apply( $license, $this->policy_for( $license, 'refund' ), 'refund', $order_id, $refund_id, 'refund:' . $refund_id . ':' . (int) $license['id'] ) );
				}
			}
		}

		return $result;
	}

	/** @return array{affected:int,replayed:int,unmapped:int} */
	public function cancellation( int $order_id ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			throw new RuntimeException( 'The cancelled order could not be found.' );
		}
		$result = array( 'affected' => 0, 'replayed' => 0, 'unmapped' => 0 );
		foreach ( $this->licenses->for_order( $order_id ) as $license ) {
			$this->count_result( $result, $this->apply( $license, $this->policy_for( $license, 'cancellation' ), 'cancellation', $order_id, null, 'cancel:' . $order_id . ':' . (int) $license['id'] ) );
		}
		return $result;
	}

	/** @param array<string,mixed> $license @return array{replayed:bool,effective:string} */
	private function apply( array $license, string $policy, string $context, int $order_id, ?int $refund_id, string $operation_id ): array {
		return $this->transaction->run(
			function () use ( $license, $policy, $context, $order_id, $refund_id, $operation_id ): array {
				global $wpdb;
				$table = $wpdb->prefix . 'dreamax_lm_licenses';
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d FOR UPDATE", (int) $license['id'] ), ARRAY_A );
				if ( ! is_array( $row ) || (int) $row['order_id'] !== $order_id ) {
					throw new RuntimeException( 'The license ownership changed before the order policy could be applied.' );
				}

				$metadata = $this->metadata( $row );
				$operations = is_array( $metadata['woocommerce_operations'] ?? null ) ? $metadata['woocommerce_operations'] : array();
				$operation_hash = hash( 'sha256', $operation_id );
				if ( in_array( $operation_hash, $operations, true ) ) {
					return array( 'replayed' => true, 'effective' => $policy );
				}

				$before    = (string) $row['lifecycle_status'];
				$after     = $before;
				$effective = $policy;
				$release   = false;
				if ( 'suspend' === $policy && 'revoked' !== $before ) {
					$after = 'suspended';
				} elseif ( 'revoke' === $policy ) {
					$after = 'revoked';
				} elseif ( 'release_unused' === $policy ) {
					$source = isset( $metadata['woocommerce']['source'] ) ? (string) $metadata['woocommerce']['source'] : '';
					$activation_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_activations WHERE license_id=%d", (int) $row['id'] ) );
					$delivery_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_events WHERE license_id=%d AND event_type='license_delivered'", (int) $row['id'] ) );
					$delivered = $delivery_count > 0 || ! empty( $metadata['woocommerce']['delivered_at'] );
					if ( 'pool' === $source && 'assigned' === $before && 0 === $activation_count && ! $delivered ) {
						$after   = 'available';
						$release = true;
					} else {
						$effective = 'release_blocked_suspended';
						if ( 'revoked' !== $before ) {
							$after = 'suspended';
						}
					}
				}

				$operations[] = $operation_hash;
				$metadata['woocommerce_operations'] = array_slice( array_values( array_unique( $operations ) ), -100 );
				if ( $release && isset( $metadata['woocommerce'] ) && is_array( $metadata['woocommerce'] ) ) {
					foreach ( array( 'order_id', 'order_item_id', 'quantity_slot', 'delivery_started_at', 'delivered_at' ) as $field ) {
						unset( $metadata['woocommerce'][ $field ] );
					}
					$metadata['woocommerce']['released_at'] = gmdate( 'Y-m-d H:i:s' );
				}
				$encoded = wp_json_encode( $metadata );
				if ( ! is_string( $encoded ) ) {
					throw new RuntimeException( 'The order-policy operation marker could not be encoded.' );
				}

				$data = array( 'lifecycle_status' => $after, 'updated_at' => gmdate( 'Y-m-d H:i:s' ), 'metadata' => $encoded );
				$formats = array( '%s', '%s', '%s' );
				if ( $release ) {
					$data['order_id']       = null;
					$data['order_item_id']  = null;
					$data['quantity_slot']  = null;
					$data['customer_id']    = null;
					$data['product_id']     = null;
					$data['variation_id']   = null;
					$formats = array_merge( $formats, array( '%d', '%d', '%d', '%d', '%d', '%d' ) );
				}
				if ( false === $wpdb->update( $table, $data, array( 'id' => (int) $row['id'] ), $formats, array( '%d' ) ) ) {
					throw new RuntimeException( 'The order policy could not be stored.' );
				}

				$event = 'refund' === $context ? 'order_refund_policy_applied' : 'order_cancellation_policy_applied';
				$this->events->append( $event, (int) $row['id'], 'woocommerce', null, $operation_id, array( 'order_id' => $order_id, 'refund_id' => $refund_id, 'order_item_id' => $row['order_item_id'], 'quantity_slot' => $row['quantity_slot'], 'configured_policy' => $policy, 'effective_policy' => $effective, 'before_status' => $before, 'after_status' => $after ) );
				return array( 'replayed' => false, 'effective' => $effective );
			}
		);
	}

	/** @param array<string,mixed> $license */
	private function policy_for( array $license, string $kind ): string {
		$metadata = $this->metadata( $license );
		$field = 'refund' === $kind ? 'refund_policy' : 'cancellation_policy';
		if ( isset( $metadata['woocommerce'][ $field ] ) && is_string( $metadata['woocommerce'][ $field ] ) ) {
			return $this->products->policy( $metadata['woocommerce'][ $field ] );
		}
		$product_id = ! empty( $license['variation_id'] ) ? (int) $license['variation_id'] : (int) $license['product_id'];
		$product = $product_id > 0 ? wc_get_product( $product_id ) : null;
		if ( ! $product ) {
			return 'retain';
		}
		$resolved = $this->products->resolve( $product );
		return 'refund' === $kind ? $resolved['refund_policy'] : $resolved['cancellation_policy'];
	}

	/** @param list<array<string,mixed>> $rows */
	private function issuance_for( array $rows, $order_item ): string {
		if ( isset( $rows[0] ) ) {
			$metadata = $this->metadata( $rows[0] );
			if ( isset( $metadata['woocommerce']['issuance'] ) && in_array( $metadata['woocommerce']['issuance'], array( 'per_quantity', 'per_item' ), true ) ) {
				return (string) $metadata['woocommerce']['issuance'];
			}
		}
		$product = is_object( $order_item ) && method_exists( $order_item, 'get_product' ) ? $order_item->get_product() : null;
		return $product ? $this->products->resolve( $product )['issuance'] : 'per_quantity';
	}

	/** @param array<string,mixed> $license @return array<string,mixed> */
	private function metadata( array $license ): array {
		$metadata = json_decode( (string) ( $license['metadata'] ?? '{}' ), true );
		return is_array( $metadata ) ? $metadata : array();
	}

	/** @param array{affected:int,replayed:int,unmapped:int} $aggregate @param array{replayed:bool,effective:string} $item */
	private function count_result( array &$aggregate, array $item ): void {
		if ( $item['replayed'] ) {
			++$aggregate['replayed'];
		} else {
			++$aggregate['affected'];
		}
	}
}
