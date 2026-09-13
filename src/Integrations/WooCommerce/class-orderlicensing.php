<?php
/**
 * Defines the OrderLicensing class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Integrations\WooCommerce;

use Dreamax\LicenseManager\Events\AuditEventCatalog;
use Dreamax\LicenseManager\Events\EventRepository;
use Dreamax\LicenseManager\Licenses\LicenseRepository;
use Dreamax\LicenseManager\Licenses\LicenseService;
use Dreamax\LicenseManager\Support\Capabilities;
use Dreamax\LicenseManager\Support\Settings;
use RuntimeException;
use Throwable;
use WC_Email;
use WC_Order;
use WC_Order_Item_Product;

/**
 * Handles Order licensing operations.
 */
final class OrderLicensing {
	/**
	 * Service value.
	 *
	 * @var LicenseService
	 */
	private LicenseService $service;
	/**
	 * Licenses value.
	 *
	 * @var LicenseRepository
	 */
	private LicenseRepository $licenses;
	/**
	 * Products value.
	 *
	 * @var ProductPolicy
	 */
	private ProductPolicy $products;
	/**
	 * Policies value.
	 *
	 * @var OrderPolicyService
	 */
	private OrderPolicyService $policies;
	/**
	 * Events value.
	 *
	 * @var EventRepository
	 */
	private EventRepository $events;
	/**
	 * Slots value.
	 *
	 * @var OrderSlotPolicy
	 */
	private OrderSlotPolicy $slots;
	/**
	 * Operations value.
	 *
	 * @var OrderOperationRepository
	 */
	private OrderOperationRepository $operations;
	/**
	 * Order access policy value.
	 *
	 * @var OrderAccessPolicy
	 */
	private OrderAccessPolicy $access;

	/**
	 * Initializes the service.
	 */
	public function __construct() {
		$this->service    = new LicenseService();
		$this->licenses   = new LicenseRepository();
		$this->products   = new ProductPolicy();
		$this->policies   = new OrderPolicyService();
		$this->events     = new EventRepository();
		$this->slots      = new OrderSlotPolicy();
		$this->operations = new OrderOperationRepository();
		$this->access     = new OrderAccessPolicy();
	}

	/**
	 * Handles the register operation.
	 */
	public function register(): void {
		add_action( 'woocommerce_order_status_changed', array( $this, 'status_changed' ), 10, 4 );
		add_action( 'woocommerce_order_refunded', array( $this, 'refunded' ), 10, 2 );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'cancelled' ), 10, 2 );
		add_action( 'woocommerce_saved_order_items', array( $this, 'quantity_saved' ), 10, 2 );
		add_action( 'woocommerce_before_delete_order_item', array( $this, 'before_delete_order_item' ) );
		add_filter( 'woocommerce_order_actions', array( $this, 'order_actions' ), 10, 2 );
		add_action( 'woocommerce_order_action_dreamax_lm_resend_licenses', array( $this, 'resend_order_action' ) );
		add_action( 'woocommerce_email_order_meta', array( $this, 'email_licenses' ), 20, 4 );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'order_licenses' ) );
		add_action( 'woocommerce_thankyou', array( $this, 'thankyou_licenses' ), 20 );
	}

	/**
	 * Allocates licenses only when an order enters a merchant-selected status.
	 *
	 * @param int           $order_id Order identifier.
	 * @param string        $from Previous status.
	 * @param string        $to Current status.
	 * @param WC_Order|null $order Order supplied by WooCommerce.
	 */
	public function status_changed( int $order_id, string $from, string $to, ?WC_Order $order = null ): void {
		unset( $from );
		if ( ! in_array( $to, Settings::allocation_statuses(), true ) ) {
			return;
		}
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order || ! $order->is_paid() || $order->has_status( array( 'cancelled', 'failed', 'refunded' ) ) ) {
			return;
		}
		$this->allocate( $order_id );
	}

	/**
	 * Handles the allocate operation.
	 *
	 * @param int $order_id Order id value.
	 */
	public function allocate( int $order_id ): void {
		try {
			$this->allocate_missing( $order_id, false, 'woocommerce', null, 'automatic:' . $order_id );
		} catch ( Throwable $error ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$order->add_order_note( __( 'Dreamax licensing could not finish automatic allocation. Use the confirmed Order tools preview after resolving the reported system issue.', 'dreamax-license-manager' ) );
			}
		}
	}

	/**
	 * Handles the allocate missing operation.
	 *
	 * @param int    $order_id Order id value.
	 * @param bool   $explicit Explicit value.
	 * @param string $actor_type Actor type value.
	 * @param ?int   $actor_id Actor id value.
	 * @phpstan-param int|null $actor_id Actor id value.
	 * @param string $operation_id Operation id value.
	 * @throws RuntimeException When the operation cannot be completed.
	 * @return array{allocated:int,existing:int,failed:int,items:int}
	 */
	public function allocate_missing( int $order_id, bool $explicit, string $actor_type, ?int $actor_id, string $operation_id ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			throw new RuntimeException( 'The order could not be found.' );
		}
		if ( ! $order->is_paid() || $order->has_status( array( 'cancelled', 'failed', 'refunded' ) ) ) {
			throw new RuntimeException( 'Licenses can be allocated only after payment is confirmed.' );
		}

		$result = array(
			'allocated' => 0,
			'existing'  => 0,
			'failed'    => 0,
			'items'     => 0,
		);
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}
			$policy = $this->products->resolve( $product );
			if ( ! $policy['enabled'] || '' === $policy['public_id'] ) {
				continue;
			}
			++$result['items'];
			$eligible_slots   = $this->eligible_slots( $order, $item, $policy['issuance'] );
			$rows             = $this->licenses->for_order_item( (int) $item_id );
			$existing_by_slot = array();
			foreach ( $rows as $row ) {
				$existing_by_slot[ (int) $row['quantity_slot'] ] = true;
			}

			$target = (int) $item->get_meta( '_dreamax_lm_delivery_target_slots', true );
			if ( $target < 1 ) {
				$target = $rows ? max( array_keys( $existing_by_slot ) ) : ( $eligible_slots ? max( $eligible_slots ) : 0 );
				/**
				 * Store the integer delivery target supported by WC_Data.
				 *
				 * @phpstan-ignore argument.type (WC_Data accepts mixed metadata values; the WooCommerce stub narrows this to string|array.)
				 */
				$item->update_meta_data( '_dreamax_lm_delivery_target_slots', $target );
				/**
				 * Store the integer delivery quantity supported by WC_Data.
				 *
				 * @phpstan-ignore argument.type (WC_Data accepts mixed metadata values; the WooCommerce stub narrows this to string|array.)
				 */
				$item->update_meta_data( '_dreamax_lm_delivery_quantity', max( 1, (int) $item->get_quantity() ) );
				$item->update_meta_data( '_dreamax_lm_delivery_issuance', $policy['issuance'] );
				$item->update_meta_data( '_dreamax_lm_delivery_started_at', gmdate( 'Y-m-d H:i:s' ) );
				$item->save();
			}
			$highest_eligible = $eligible_slots ? max( $eligible_slots ) : 0;
			if ( $explicit && $highest_eligible > $target ) {
				$refunded_quantity = abs( (int) $order->get_qty_refunded_for_item( (int) $item_id ) );
				if ( $refunded_quantity > 0 ) {
					throw new RuntimeException( 'A post-delivery quantity increase cannot be allocated after a quantity refund. Review the order and license history manually.' );
				}
				$target = $highest_eligible;
				/**
				 * Store the integer delivery target supported by WC_Data.
				 *
				 * @phpstan-ignore argument.type (WC_Data accepts mixed metadata values; the WooCommerce stub narrows this to string|array.)
				 */
				$item->update_meta_data( '_dreamax_lm_delivery_target_slots', $target );
				$item->save();
			}
			$candidate_slots = $explicit ? $eligible_slots : array_values( array_filter( $eligible_slots, static fn( int $slot ): bool => $slot <= $target ) );
			foreach ( $candidate_slots as $slot ) {
				if ( isset( $existing_by_slot[ $slot ] ) || $this->licenses->by_order_slot( (int) $item_id, $slot ) ) {
					++$result['existing'];
					continue;
				}
				$now        = gmdate( 'Y-m-d H:i:s' );
				$paid_at    = $order->get_date_paid() ? $order->get_date_paid()->getTimestamp() : time();
				$expires_at = $policy['fixed_expires_at'] ?? ( null !== $policy['valid_for_seconds'] ? gmdate( 'Y-m-d H:i:s', $paid_at + $policy['valid_for_seconds'] ) : null );
				$attributes = array(
					'lifecycle_status'  => 'assigned',
					'product_public_id' => $policy['public_id'],
					'product_id'        => $product->get_parent_id() ? $product->get_parent_id() : $product->get_id(),
					'variation_id'      => $product->is_type( 'variation' ) ? $product->get_id() : null,
					'order_id'          => $order_id,
					'order_item_id'     => (int) $item_id,
					'quantity_slot'     => $slot,
					'customer_id'       => (int) $order->get_customer_id(),
					'generator_id'      => $policy['generator_id'],
					'activation_limit'  => $policy['activation_limit'],
					'valid_for_seconds' => $policy['valid_for_seconds'],
					'expires_at'        => $expires_at,
					'actor_type'        => $actor_type,
					'actor_id'          => $actor_id,
					'source'            => $policy['source'],
					'request_id'        => $operation_id,
					'metadata'          => array(
						'woocommerce' => array(
							'order_id'            => $order_id,
							'order_item_id'       => (int) $item_id,
							'quantity_slot'       => $slot,
							'issuance'            => $policy['issuance'],
							'source'              => $policy['source'],
							'refund_policy'       => $policy['refund_policy'],
							'cancellation_policy' => $policy['cancellation_policy'],
							'delivery_started_at' => (string) $item->get_meta( '_dreamax_lm_delivery_started_at', true ),
							'delivered_at'        => $now,
						),
					),
				);
				if ( array() !== $policy['generator'] ) {
					$attributes['generator'] = $policy['generator'];
				}
				try {
					'pool' === $policy['source'] ? $this->service->assign_pool( $policy['public_id'], $attributes ) : $this->service->create_generated( $attributes );
					$existing_by_slot[ $slot ] = true;
					++$result['allocated'];
				} catch ( Throwable $error ) {
					if ( $this->licenses->by_order_slot( (int) $item_id, $slot ) ) {
						++$result['existing'];
						continue;
					}
					++$result['failed'];
					$this->events->append(
						AuditEventCatalog::ORDER_ALLOCATION_FAILED,
						null,
						$actor_type,
						$actor_id,
						$operation_id,
						array(
							'order_id'      => $order_id,
							'order_item_id' => (int) $item_id,
							'quantity_slot' => $slot,
							'source'        => $policy['source'],
						),
						AuditEventCatalog::SCHEMA_V1
					);
					break;
				}
			}
		}

		if ( $result['allocated'] > 0 ) {
			$this->events->append(
				$explicit ? AuditEventCatalog::ORDER_EXPLICIT_ALLOCATION_COMPLETED : AuditEventCatalog::ORDER_AUTOMATIC_ALLOCATION_COMPLETED,
				null,
				$actor_type,
				$actor_id,
				$operation_id,
				array(
					'order_id'  => $order_id,
					'allocated' => $result['allocated'],
					'existing'  => $result['existing'],
					'failed'    => $result['failed'],
				),
				AuditEventCatalog::SCHEMA_V1
			);
			/* translators: %d: Number of licenses allocated. */
			$order->add_order_note( sprintf( __( 'Dreamax allocated %d license(s).', 'dreamax-license-manager' ), $result['allocated'] ) );
		}
		return $result;
	}

	/**
	 * Handles the preview operation.
	 *
	 * @param int $order_id Order id value.
	 * @throws RuntimeException When the operation cannot be completed.
	 * @return array{order_id:int,status:string,customer_id:int,license_count:int,assigned_count:int,has_billing_email:bool,missing:int,items:list<array<string,mixed>>,eligible:bool}
	 */
	public function preview( int $order_id ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			throw new RuntimeException( 'The order could not be found.' );
		}
		$items         = array();
		$missing_total = 0;
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}
			$policy = $this->products->resolve( $product );
			if ( ! $policy['enabled'] || '' === $policy['public_id'] ) {
				continue;
			}
			$eligible_slots = $this->eligible_slots( $order, $item, $policy['issuance'] );
			$rows           = $this->licenses->for_order_item( (int) $item_id );
			$existing_slots = array_fill_keys( array_map( static fn( array $row ): int => (int) $row['quantity_slot'], $rows ), true );
			$missing        = count( array_filter( $eligible_slots, static fn( int $slot ): bool => ! isset( $existing_slots[ $slot ] ) ) );
			$missing_total += $missing;
			$items[]        = array(
				'order_item_id' => (int) $item_id,
				'product'       => $item->get_name(),
				'quantity'      => (int) $item->get_quantity(),
				'issuance'      => $policy['issuance'],
				'desired'       => count( $eligible_slots ),
				'existing'      => count( $rows ),
				'missing'       => $missing,
			);
		}
		$order_licenses = $this->licenses->for_order( $order_id );
		$assigned_count = count( array_filter( $order_licenses, static fn( array $row ): bool => 'assigned' === (string) ( $row['lifecycle_status'] ?? '' ) ) );
		return array(
			'order_id'          => $order_id,
			'status'            => $order->get_status(),
			'customer_id'       => (int) $order->get_customer_id(),
			'license_count'     => count( $order_licenses ),
			'assigned_count'    => $assigned_count,
			'has_billing_email' => is_email( (string) $order->get_billing_email() ),
			'missing'           => $missing_total,
			'items'             => $items,
			'eligible'          => $order->is_paid() && ! $order->has_status( array( 'cancelled', 'failed', 'refunded' ) ),
		);
	}

	/**
	 * Handles the refunded operation.
	 *
	 * @param int $order_id Order id value.
	 * @param int $refund_id Refund id value.
	 */
	public function refunded( int $order_id, int $refund_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		try {
			$result = $this->policies->refund( $order_id, $refund_id );
			if ( $result['affected'] > 0 || $result['unmapped'] > 0 ) {
				$this->events->append(
					AuditEventCatalog::ORDER_REFUNDED,
					null,
					'woocommerce',
					null,
					'refund:' . $refund_id,
					array(
						'order_id'          => $order_id,
						'refund_id'         => $refund_id,
						'licenses_affected' => $result['affected'],
						'licenses_replayed' => $result['replayed'],
						'unmapped_items'    => $result['unmapped'],
					),
					AuditEventCatalog::SCHEMA_V1
				);
				/* translators: 1: Number of affected licenses. 2: Number of unmapped refund items. */
				$order->add_order_note( sprintf( __( 'Dreamax refund policy processed %1$d license(s); %2$d refund item(s) could not be mapped automatically.', 'dreamax-license-manager' ), $result['affected'], $result['unmapped'] ) );
			}
		} catch ( Throwable $error ) {
			$order->add_order_note( __( 'Dreamax could not apply the refund policy. License states were left unchanged where the operation could not be committed.', 'dreamax-license-manager' ) );
		}
	}

	/**
	 * Handles the cancelled operation.
	 *
	 * @param int           $order_id Order id value.
	 * @param WC_Order|null $order Order supplied by the status-change hook.
	 */
	public function cancelled( int $order_id, $order = null ): void {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		try {
			$result = $this->policies->cancellation( $order_id );
			if ( $result['affected'] > 0 ) {
				/* translators: %d: Number of licenses affected by the cancellation policy. */
				$order->add_order_note( sprintf( __( 'Dreamax cancellation policy processed %d license(s).', 'dreamax-license-manager' ), $result['affected'] ) );
			}
		} catch ( Throwable $error ) {
			$order->add_order_note( __( 'Dreamax could not apply the cancellation policy. Review License Manager activity before changing any license manually.', 'dreamax-license-manager' ) );
		}
	}

	/**
	 * Handles the quantity saved operation.
	 *
	 * @param int                      $order_id Order id value.
	 * @param array<string,mixed>|null $items Submitted order-item fields.
	 */
	public function quantity_saved( int $order_id, $items = null ): void {
		unset( $items );
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$rows = $this->licenses->for_order_item( (int) $item_id );
			if ( array() === $rows ) {
				continue;
			}
			$issuance = (string) $item->get_meta( '_dreamax_lm_delivery_issuance', true );
			$issuance = in_array( $issuance, array( 'per_quantity', 'per_item' ), true ) ? $issuance : 'per_quantity';
			if ( 'per_item' === $issuance ) {
				continue;
			}
			$delivered_quantity = max( 1, (int) $item->get_meta( '_dreamax_lm_delivery_quantity', true ) );
			$current_quantity   = max( 0, (int) $item->get_quantity() );
			$change             = $current_quantity > $delivered_quantity ? 'increase_requires_allocation' : ( $current_quantity < $delivered_quantity ? 'decrease_retains_licenses' : '' );
			if ( '' === $change ) {
				continue;
			}
			$signature = hash( 'sha256', $change . '|' . $current_quantity . '|' . $delivered_quantity );
			if ( hash_equals( (string) $item->get_meta( '_dreamax_lm_quantity_notice', true ), $signature ) ) {
				continue;
			}
			$item->update_meta_data( '_dreamax_lm_quantity_notice', $signature );
			$item->save();
			if ( 'increase_requires_allocation' === $change ) {
				/* translators: %d: WooCommerce order item ID. */
				$message = sprintf( __( 'Dreamax detected a delivered quantity increase for item #%d. No key was created automatically; preview and confirm allocation in License Manager → Order tools.', 'dreamax-license-manager' ), (int) $item_id );
			} else {
				/* translators: %d: WooCommerce order item ID. */
				$message = sprintf( __( 'Dreamax detected a delivered quantity decrease for item #%d. Existing delivered licenses were retained and were not returned to a shared pool.', 'dreamax-license-manager' ), (int) $item_id );
			}
			$order->add_order_note( $message );
			$this->events->append(
				AuditEventCatalog::ORDER_QUANTITY_CHANGED_AFTER_DELIVERY,
				(int) $rows[0]['id'],
				'woocommerce',
				null,
				null,
				array(
					'order_id'           => $order_id,
					'order_item_id'      => (int) $item_id,
					'delivery_quantity'  => $delivered_quantity,
					'requested_quantity' => $current_quantity,
					'policy_result'      => $change,
				),
				AuditEventCatalog::SCHEMA_V1
			);
		}
	}

	/**
	 * Handles the before delete order item operation.
	 *
	 * @param int $item_id Item id value.
	 */
	public function before_delete_order_item( int $item_id ): void {
		$rows = $this->licenses->for_order_item( $item_id );
		if ( array() === $rows ) {
			return;
		}
		$order_id = (int) $rows[0]['order_id'];
		$order    = $order_id > 0 ? wc_get_order( $order_id ) : null;
		if ( $order ) {
			/* translators: %d: WooCommerce order item ID. */
			$order->add_order_note( sprintf( __( 'Order item #%d was deleted after license delivery. Dreamax retained all license and audit history.', 'dreamax-license-manager' ), $item_id ) );
		}
		foreach ( $rows as $row ) {
			$this->events->append(
				AuditEventCatalog::ORDER_ITEM_DELETED_AFTER_DELIVERY,
				(int) $row['id'],
				'woocommerce',
				null,
				null,
				array(
					'order_id'                 => $order_id,
					'order_item_id'            => $item_id,
					'license_history_retained' => true,
				),
				AuditEventCatalog::SCHEMA_V1
			);
		}
	}

	/**
	 * Handles the order actions operation.
	 *
	 * @param array         $actions Actions value.
	 * @phpstan-param array<string,string> $actions Actions value.
	 * @param  WC_Order|null $order Order being edited.
	 * @return array<string,string>
	 */
	public function order_actions( array $actions, $order ): array {
		if ( current_user_can( Capabilities::MANAGE ) && $order instanceof WC_Order && array() !== $this->licenses->for_order( (int) $order->get_id() ) ) {
			$actions['dreamax_lm_resend_licenses'] = __( 'Resend Dreamax licenses', 'dreamax-license-manager' );
		}
		return $actions;
	}

	/**
	 * Handles the resend order action operation.
	 *
	 * @param WC_Order $order Order value.
	 */
	public function resend_order_action( WC_Order $order ): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			return;
		}
		try {
			$this->resend( (int) $order->get_id(), null, 'administrator', get_current_user_id() );
		} catch ( Throwable $error ) {
			$order->add_order_note( __( 'Dreamax could not resend licenses. No recipient or eligible assigned license was available.', 'dreamax-license-manager' ) );
		}
	}

	/**
	 * Handles the resend operation.
	 *
	 * @param int     $order_id Order id value.
	 * @param ?string $operation_id Operation id value.
	 * @phpstan-param string|null $operation_id Operation id value.
	 * @param string  $actor_type Actor type value.
	 * @param ?int    $actor_id Actor id value.
	 * @phpstan-param int|null $actor_id Actor id value.
	 * @throws RuntimeException When the operation cannot be completed.
	 * @return array{sent:int,replayed:bool}
	 */
	public function resend( int $order_id, ?string $operation_id, string $actor_type, ?int $actor_id ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! is_email( $order->get_billing_email() ) ) {
			throw new RuntimeException( 'The order does not have a valid billing email.' );
		}
		$rows = array_values( array_filter( $this->licenses->for_order( $order_id ), static fn( array $row ): bool => 'assigned' === $row['lifecycle_status'] ) );
		if ( array() === $rows ) {
			throw new RuntimeException( 'The order has no assigned licenses to resend.' );
		}
		/* translators: %d: WooCommerce order ID. */
		$lines = array( sprintf( __( 'Licenses for order #%d', 'dreamax-license-manager' ), $order_id ), '' );
		try {
			foreach ( $rows as $row ) {
				$lines[] = $this->licenses->decrypt_key( $row );
			}
		} catch ( Throwable $error ) {
			unset( $error );
			throw new RuntimeException( 'Secure key access is temporarily unavailable.' );
		}
		$claimed = false;
		if ( null !== $operation_id ) {
			$claimed = $this->operations->claim( 'order_resend', $order_id, $operation_id );
			if ( ! $claimed ) {
				return array(
					'sent'     => 0,
					'replayed' => true,
				);
			}
		}
		/* translators: %d: WooCommerce order ID. */
		$sent = wp_mail( (string) $order->get_billing_email(), sprintf( __( 'Your licenses for order #%d', 'dreamax-license-manager' ), $order_id ), implode( "\n", $lines ) );
		if ( ! $sent ) {
			if ( $claimed ) {
				$this->operations->release( 'order_resend', $order_id, (string) $operation_id );
			}
			throw new RuntimeException( 'The license email could not be sent.' );
		}
		if ( $claimed ) {
			$this->operations->complete( 'order_resend', $order_id, (string) $operation_id );
		}
		foreach ( $rows as $row ) {
			$this->events->append(
				AuditEventCatalog::LICENSE_RESENT,
				(int) $row['id'],
				$actor_type,
				$actor_id,
				$operation_id,
				array(
					'order_id' => $order_id,
					'channel'  => 'billing_email',
				),
				AuditEventCatalog::SCHEMA_V1
			);
		}
		/* translators: %d: Number of licenses resent. */
		$order->add_order_note( sprintf( __( 'Dreamax resent %d assigned license(s) to the current billing email.', 'dreamax-license-manager' ), count( $rows ) ) );
		return array(
			'sent'     => count( $rows ),
			'replayed' => false,
		);
	}

	/**
	 * Handles the email licenses operation.
	 *
	 * @param WC_Order $order Order value.
	 * @param bool     $sent_to_admin Sent to admin value.
	 * @param bool     $plain_text Plain text value.
	 * @param WC_Email $email Email being rendered.
	 */
	public function email_licenses( WC_Order $order, bool $sent_to_admin, bool $plain_text, $email ): void {
		$email_id = $email instanceof \WC_Email ? (string) $email->id : '';
		if ( $sent_to_admin || ! in_array( $email_id, array( 'customer_processing_order', 'customer_completed_order', 'customer_invoice' ), true ) ) {
			return;
		}
		$this->render( (int) $order->get_id(), $plain_text, true );
	}

	/**
	 * Handles the order licenses operation.
	 *
	 * @param WC_Order $order Order value.
	 */
	public function order_licenses( WC_Order $order ): void {
		if ( ! $this->customer_can_view( $order ) ) {
			return;
		}
		$this->render( (int) $order->get_id(), false, false );
	}

	/**
	 * Handles the thankyou licenses operation.
	 *
	 * @param int $order_id Order id value.
	 */
	public function thankyou_licenses( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->is_paid() ) {
			return;
		}
		if ( ! $this->customer_can_view( $order ) ) {
			return;
		}
		$this->render( $order_id, false, true );
	}

	/**
	 * Reuses WooCommerce's verified hook context and order-key validation.
	 *
	 * @param WC_Order $order Order value.
	 */
	private function customer_can_view( WC_Order $order ): bool {
		$hook                 = current_filter();
		$woocommerce_verified = in_array( $hook, array( 'woocommerce_order_details_after_order_table', 'woocommerce_thankyou' ), true );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The key is read-only WooCommerce ownership proof and is validated by WC_Order::key_is_valid().
		$key       = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['key'] ) ) : '';
		$key_valid = '' !== $key && $order->key_is_valid( $key );
		return $this->access->allows( (int) $order->get_customer_id(), get_current_user_id(), $woocommerce_verified, $key_valid );
	}

	/**
	 * Handles the render operation.
	 *
	 * @param int  $order_id Order id value.
	 * @param bool $plain Plain value.
	 * @param bool $assigned_only Assigned only value.
	 */
	private function render( int $order_id, bool $plain, bool $assigned_only ): void {
		$rows = $this->licenses->for_order( $order_id );
		if ( $assigned_only ) {
			$rows = array_values( array_filter( $rows, static fn( array $row ): bool => 'assigned' === $row['lifecycle_status'] ) );
		}
		if ( array() === $rows ) {
			return;
		}
		if ( $plain ) {
			echo "\n" . esc_html__( 'Licenses', 'dreamax-license-manager' ) . "\n";
			foreach ( $rows as $row ) {
				echo esc_html( $this->key_for_display( $row ) ) . ' — ' . esc_html( (string) $row['lifecycle_status'] ) . "\n";
			}
			return;
		}
		echo '<section class="dreamax-lm-order-licenses"><h2>' . esc_html__( 'Licenses', 'dreamax-license-manager' ) . '</h2><ul>';
		foreach ( $rows as $row ) {
			echo '<li><code>' . esc_html( $this->key_for_display( $row ) ) . '</code> <span>— ' . esc_html( (string) $row['lifecycle_status'] ) . '</span></li>';
		}
		echo '</ul></section>';
	}

	/**
	 * Decrypts a key without allowing recovery-mode failures to break order views.
	 *
	 * @param array $row License row.
	 * @phpstan-param array<string,mixed> $row License row.
	 */
	private function key_for_display( array $row ): string {
		try {
			return $this->licenses->decrypt_key( $row );
		} catch ( Throwable $error ) {
			unset( $error );
			return __( 'Temporarily unavailable', 'dreamax-license-manager' );
		}
	}

	/**
	 * Handles the eligible slots operation.
	 *
	 * @param WC_Order              $order Order value.
	 * @param  WC_Order_Item_Product $item Product line item.
	 * @param string                $issuance Issuance value.
	 * @return list<int>
	 */
	private function eligible_slots( WC_Order $order, $item, string $issuance ): array {
		$snapshot = (int) $item->get_meta( '_dreamax_lm_delivery_quantity', true );
		$original = max( 1, $snapshot > 0 ? $snapshot : (int) $item->get_quantity() );
		$refunded = abs( (int) $order->get_qty_refunded_for_item( (int) $item->get_id() ) );
		return $this->slots->eligible_slots( (int) $item->get_quantity(), $original, $refunded, $issuance );
	}
}
