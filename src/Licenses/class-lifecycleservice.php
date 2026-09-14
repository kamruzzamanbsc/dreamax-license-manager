<?php
/**
 * Defines the LifecycleService class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Licenses;

use Dreamax\LicenseManager\Database\Transaction;
use Dreamax\LicenseManager\Events\AuditEventCatalog;
use Dreamax\LicenseManager\Events\EventRepository;
use InvalidArgumentException;
use RuntimeException;

/**
 * Handles Lifecycle service operations.
 */
final class LifecycleService {
	/**
	 * Transaction value.
	 *
	 * @var Transaction
	 */
	private Transaction $transaction;
	/**
	 * Events value.
	 *
	 * @var EventRepository
	 */
	private EventRepository $events;
	/**
	 * Machine value.
	 *
	 * @var StateMachine
	 */
	private StateMachine $machine;
	/**
	 * Expiry value.
	 *
	 * @var ExpiryPolicy
	 */
	private ExpiryPolicy $expiry;

	/**
	 * Initializes the service.
	 */
	public function __construct() {
		$this->transaction = new Transaction();
		$this->events      = new EventRepository();
		$this->machine     = new StateMachine();
		$this->expiry      = new ExpiryPolicy();
	}

	/**
	 * Handles the transition operation.
	 *
	 * @param string $public_id Public id value.
	 * @param string $target Target value.
	 * @param string $reason Reason value.
	 * @param string $operation_id Operation id value.
	 * @param string $actor_type Actor type value.
	 * @param ?int   $actor_id Actor id value.
	 * @phpstan-param int|null $actor_id Actor id value.
	 * @throws InvalidArgumentException When the operation cannot be completed.
	 * @return array<string,mixed>
	 */
	public function transition( string $public_id, string $target, string $reason, string $operation_id, string $actor_type, ?int $actor_id ): array {
		$this->assert_public_id( $public_id );
		$this->assert_reason( $reason );
		$this->assert_operation_id( $operation_id );
		if ( ! in_array( $target, $this->machine->states(), true ) ) {
			throw new InvalidArgumentException( 'The requested lifecycle state is invalid.' );
		}

		return $this->transaction->run(
			function () use ( $public_id, $target, $reason, $operation_id, $actor_type, $actor_id ): array {
				global $wpdb;
				$row = $this->lock( $public_id );
				if ( $this->operation_applied( $row, $operation_id ) ) {
					return array(
						'public_id' => $public_id,
						'status'    => (string) $row['lifecycle_status'],
						'replayed'  => true,
					);
				}

				$from = (string) $row['lifecycle_status'];
				if ( $from === $target ) {
					throw new InvalidArgumentException( 'The license is already in the requested state.' );
				}
				if ( ! $this->machine->can_transition( $from, $target ) ) {
					throw new InvalidArgumentException( 'That lifecycle transition is not allowed.' );
				}

				$metadata = $this->mark_operation( $row, $operation_id );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional tables require direct, fresh database reads and writes; object caching would break locking and replay guarantees.
				$updated = $wpdb->update(
					$wpdb->prefix . 'dreamax_lm_licenses',
					array(
						'lifecycle_status' => $target,
						'updated_at'       => gmdate( 'Y-m-d H:i:s' ),
						'metadata'         => $metadata,
					),
					array( 'id' => (int) $row['id'] ),
					array( '%s', '%s', '%s' ),
					array( '%d' )
				);
				if ( false === $updated ) {
					throw new RuntimeException( 'The lifecycle change could not be stored.' );
				}

				$this->events->append(
					'suspended' === $target ? AuditEventCatalog::LICENSE_SUSPENDED : ( 'revoked' === $target ? AuditEventCatalog::LICENSE_REVOKED : AuditEventCatalog::LICENSE_RESTORED ),
					(int) $row['id'],
					$actor_type,
					$actor_id,
					$operation_id,
					array(
						'from'   => $from,
						'to'     => $target,
						'reason' => $reason,
					),
					AuditEventCatalog::SCHEMA_V1
				);
				return array(
					'public_id' => $public_id,
					'status'    => $target,
					'replayed'  => false,
				);
			}
		);
	}

	/**
	 * Handles the restore operation.
	 *
	 * @param string $public_id Public id value.
	 * @param string $reason Reason value.
	 * @param string $operation_id Operation id value.
	 * @param string $actor_type Actor type value.
	 * @param ?int   $actor_id Actor id value.
	 * @phpstan-param int|null $actor_id Actor id value.
	 * @throws InvalidArgumentException When the operation cannot be completed.
	 * @return array<string,mixed>
	 */
	public function restore( string $public_id, string $reason, string $operation_id, string $actor_type, ?int $actor_id ): array {
		$this->assert_public_id( $public_id );
		$this->assert_reason( $reason );
		$this->assert_operation_id( $operation_id );
		return $this->transaction->run(
			function () use ( $public_id, $reason, $operation_id, $actor_type, $actor_id ): array {
				global $wpdb;
				$row = $this->lock( $public_id );
				if ( $this->operation_applied( $row, $operation_id ) ) {
					return array(
						'public_id' => $public_id,
						'status'    => (string) $row['lifecycle_status'],
						'replayed'  => true,
					);
				}
				if ( 'suspended' !== $row['lifecycle_status'] ) {
					throw new InvalidArgumentException( 'Only a suspended license can be restored.' );
				}
				$target = empty( $row['customer_id'] ) && empty( $row['order_id'] ) ? 'available' : 'assigned';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional tables require direct, fresh database reads and writes; object caching would break locking and replay guarantees.
				if ( false === $wpdb->update(
					$wpdb->prefix . 'dreamax_lm_licenses',
					array(
						'lifecycle_status' => $target,
						'updated_at'       => gmdate( 'Y-m-d H:i:s' ),
						'metadata'         => $this->mark_operation( $row, $operation_id ),
					),
					array( 'id' => (int) $row['id'] ),
					array( '%s', '%s', '%s' ),
					array( '%d' )
				) ) {
					throw new RuntimeException( 'The lifecycle restoration could not be stored.' );
				}
				$this->events->append(
					AuditEventCatalog::LICENSE_RESTORED,
					(int) $row['id'],
					$actor_type,
					$actor_id,
					$operation_id,
					array(
						'from'   => 'suspended',
						'to'     => $target,
						'reason' => $reason,
					),
					AuditEventCatalog::SCHEMA_V1
				);
				return array(
					'public_id' => $public_id,
					'status'    => $target,
					'replayed'  => false,
				);
			}
		);
	}

	/**
	 * Handles the extend operation.
	 *
	 * @param string $public_id Public id value.
	 * @param int    $days Days value.
	 * @param string $reason Reason value.
	 * @param string $operation_id Operation id value.
	 * @param string $actor_type Actor type value.
	 * @param ?int   $actor_id Actor id value.
	 * @phpstan-param int|null $actor_id Actor id value.
	 * @throws InvalidArgumentException When the operation cannot be completed.
	 * @return array<string,mixed>
	 */
	public function extend( string $public_id, int $days, string $reason, string $operation_id, string $actor_type, ?int $actor_id ): array {
		$this->assert_public_id( $public_id );
		$this->assert_reason( $reason );
		$this->assert_operation_id( $operation_id );
		if ( $days < 1 || $days > 3650 ) {
			throw new InvalidArgumentException( 'The extension must be between 1 and 3650 days.' );
		}

		return $this->transaction->run(
			function () use ( $public_id, $days, $reason, $operation_id, $actor_type, $actor_id ): array {
				global $wpdb;
				$row = $this->lock( $public_id );
				if ( $this->operation_applied( $row, $operation_id ) ) {
					return array(
						'public_id'  => $public_id,
						'expires_at' => $row['expires_at'],
						'replayed'   => true,
					);
				}

				$old_expiry = null === $row['expires_at'] ? null : (string) $row['expires_at'];
				$new_expiry = $this->expiry->extend( $old_expiry, $days * 86400, time() );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional tables require direct, fresh database reads and writes; object caching would break locking and replay guarantees.
				$updated = $wpdb->update(
					$wpdb->prefix . 'dreamax_lm_licenses',
					array(
						'expires_at' => $new_expiry,
						'updated_at' => gmdate( 'Y-m-d H:i:s' ),
						'metadata'   => $this->mark_operation( $row, $operation_id ),
					),
					array( 'id' => (int) $row['id'] ),
					array( '%s', '%s', '%s' ),
					array( '%d' )
				);
				if ( false === $updated ) {
					throw new RuntimeException( 'The expiry extension could not be stored.' );
				}
				$this->events->append(
					AuditEventCatalog::LICENSE_EXTENDED,
					(int) $row['id'],
					$actor_type,
					$actor_id,
					$operation_id,
					array(
						'old_expiry'     => $old_expiry,
						'new_expiry'     => $new_expiry,
						'extension_days' => $days,
						'reason'         => $reason,
					),
					AuditEventCatalog::SCHEMA_V1
				);
				return array(
					'public_id'  => $public_id,
					'expires_at' => $new_expiry,
					'replayed'   => false,
				);
			}
		);
	}

	/**
	 * Applies a product-bound expiry extension requested through the commercial contract.
	 *
	 * @param string $public_id License public identifier.
	 * @param string $product_public_id Expected product public identifier.
	 * @param int    $days Extension duration in days.
	 * @param int    $effective_timestamp Authoritative billing-event timestamp.
	 * @param string $operation_id Deterministic operation identifier.
	 * @param string $request_digest Canonical command digest.
	 * @throws InvalidArgumentException When command input is invalid.
	 * @return array<string,mixed>
	 */
	public function extend_from_commercial_contract(
		string $public_id,
		string $product_public_id,
		int $days,
		int $effective_timestamp,
		string $operation_id,
		string $request_digest
	): array {
		$this->assert_public_id( $public_id );
		$this->assert_operation_id( $operation_id );
		if ( 1 !== preg_match( '/^prd_[A-Za-z0-9_-]{22}$/D', $product_public_id ) ) {
			throw new InvalidArgumentException( 'The product public ID is invalid.' );
		}
		if ( $days < 1 || $days > 3650 ) {
			throw new InvalidArgumentException( 'The extension must be between 1 and 3650 days.' );
		}
		if ( $effective_timestamp < 946684800 || $effective_timestamp > time() + 300 ) {
			throw new InvalidArgumentException( 'The effective timestamp is outside the accepted range.' );
		}
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $request_digest ) ) {
			throw new InvalidArgumentException( 'The command digest is invalid.' );
		}

		return $this->transaction->run(
			function () use ( $public_id, $product_public_id, $days, $effective_timestamp, $operation_id, $request_digest ): array {
				global $wpdb;
				$row = $this->lock( $public_id );
				if ( (string) ( $row['product_public_id'] ?? '' ) !== $product_public_id ) {
					throw new LicenseException( 'product_mismatch', 'The license is not assigned to the expected product.', 409 );
				}

				$record = $this->commercial_operation_record( $row, $operation_id );
				if ( null !== $record ) {
					if ( ! hash_equals( (string) ( $record['request_digest'] ?? '' ), $request_digest ) ) {
						throw new LicenseException( 'idempotency_conflict', 'The operation identifier was already used for a different command.', 409 );
					}
					return array(
						'public_id'  => $public_id,
						'expires_at' => (string) ( $record['expires_at'] ?? '' ),
						'replayed'   => true,
					);
				}
				if ( $this->operation_applied( $row, $operation_id ) ) {
					throw new LicenseException( 'idempotency_conflict', 'The operation identifier was already used outside this command contract.', 409 );
				}

				$old_expiry = null === $row['expires_at'] ? null : (string) $row['expires_at'];
				$new_expiry = $this->expiry->extend( $old_expiry, $days * 86400, $effective_timestamp );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional tables require direct, fresh database writes for locking and replay guarantees.
				$updated = $wpdb->update(
					$wpdb->prefix . 'dreamax_lm_licenses',
					array(
						'expires_at' => $new_expiry,
						'updated_at' => gmdate( 'Y-m-d H:i:s' ),
						'metadata'   => $this->mark_commercial_operation( $row, $operation_id, $request_digest, $new_expiry ),
					),
					array( 'id' => (int) $row['id'] ),
					array( '%s', '%s', '%s' ),
					array( '%d' )
				);
				if ( false === $updated ) {
					throw new RuntimeException( 'The expiry extension could not be stored.' );
				}
				$this->events->append(
					AuditEventCatalog::LICENSE_EXPIRY_EXTENSION_APPLIED,
					(int) $row['id'],
					'system',
					null,
					$operation_id,
					array(
						'old_expiry'     => $old_expiry,
						'new_expiry'     => $new_expiry,
						'extension_days' => $days,
						'reason'         => 'Verified commercial renewal',
						'source'         => 'commercial_contract_v1',
					),
					AuditEventCatalog::SCHEMA_V1
				);

				return array(
					'public_id'  => $public_id,
					'expires_at' => $new_expiry,
					'replayed'   => false,
				);
			}
		);
	}

	/**
	 * Handles the reset activations operation.
	 *
	 * @param string $public_id Public id value.
	 * @param string $reason Reason value.
	 * @param string $operation_id Operation id value.
	 * @param string $actor_type Actor type value.
	 * @param ?int   $actor_id Actor id value.
	 * @phpstan-param int|null $actor_id Actor id value.
	 * @throws RuntimeException When the operation cannot be completed.
	 * @return array<string,mixed>
	 */
	public function reset_activations( string $public_id, string $reason, string $operation_id, string $actor_type, ?int $actor_id ): array {
		$this->assert_public_id( $public_id );
		$this->assert_reason( $reason );
		$this->assert_operation_id( $operation_id );

		return $this->transaction->run(
			function () use ( $public_id, $reason, $operation_id, $actor_type, $actor_id ): array {
				global $wpdb;
				$row = $this->lock( $public_id );
				if ( $this->operation_applied( $row, $operation_id ) ) {
					return array(
						'public_id'   => $public_id,
						'reset_count' => 0,
						'replayed'    => true,
					);
				}

				$now = gmdate( 'Y-m-d H:i:s' );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional tables require direct, fresh database reads and writes; object caching would break locking and replay guarantees.
				$reset = $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->prefix}dreamax_lm_activations SET status='inactive',deactivated_at=%s,updated_at=%s WHERE license_id=%d AND status='active'",
						$now,
						$now,
						(int) $row['id']
					)
				);
				if ( false === $reset ) {
					throw new RuntimeException( 'The activation reset could not be stored.' );
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional tables require direct, fresh database reads and writes; object caching would break locking and replay guarantees.
				if ( false === $wpdb->update(
					$wpdb->prefix . 'dreamax_lm_licenses',
					array(
						'updated_at' => $now,
						'metadata'   => $this->mark_operation( $row, $operation_id ),
					),
					array( 'id' => (int) $row['id'] ),
					array( '%s', '%s' ),
					array( '%d' )
				) ) {
					throw new RuntimeException( 'The activation reset marker could not be stored.' );
				}
				$this->events->append(
					AuditEventCatalog::LICENSE_ACTIVATIONS_RESET,
					(int) $row['id'],
					$actor_type,
					$actor_id,
					$operation_id,
					array(
						'reset_count' => (int) $reset,
						'reason'      => $reason,
					),
					AuditEventCatalog::SCHEMA_V1
				);
				return array(
					'public_id'   => $public_id,
					'reset_count' => (int) $reset,
					'replayed'    => false,
				);
			}
		);
	}

	/**
	 * Handles the reassign operation.
	 *
	 * @param string $public_id Public id value.
	 * @param array  $target Target value.
	 * @phpstan-param array<string,mixed> $target Target value.
	 * @param bool   $reset_activations Reset activations value.
	 * @param bool   $notify Notify value.
	 * @param string $reason Reason value.
	 * @param string $operation_id Operation id value.
	 * @param string $actor_type Actor type value.
	 * @param ?int   $actor_id Actor id value.
	 * @phpstan-param int|null $actor_id Actor id value.
	 * @throws InvalidArgumentException When the operation cannot be completed.
	 * @return array{
	 *     public_id: string,
	 *     customer_id: int,
	 *     order_id: int|null,
	 *     reset_count: int,
	 *     replayed: bool,
	 *     notification_sent?: bool
	 * }
	 */
	public function reassign( string $public_id, array $target, bool $reset_activations, bool $notify, string $reason, string $operation_id, string $actor_type, ?int $actor_id ): array {
		$this->assert_public_id( $public_id );
		$this->assert_reason( $reason );
		$this->assert_operation_id( $operation_id );
		$customer_id = isset( $target['customer_id'] ) ? (int) $target['customer_id'] : 0;
		if ( $customer_id < 1 || ! get_userdata( $customer_id ) ) {
			throw new InvalidArgumentException( 'Choose an existing customer account.' );
		}
		$order_id = isset( $target['order_id'] ) ? max( 0, (int) $target['order_id'] ) : 0;
		if ( $order_id > 0 ) {
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
			if ( ! $order || (int) $order->get_customer_id() !== $customer_id ) {
				throw new InvalidArgumentException( 'The target order must belong to the target customer.' );
			}
		}
		$product_public_id = isset( $target['product_public_id'] ) ? trim( (string) $target['product_public_id'] ) : '';
		if ( '' !== $product_public_id && ! preg_match( '/^prd_[A-Za-z0-9_-]{22}$/D', $product_public_id ) ) {
			throw new InvalidArgumentException( 'The target product public ID is invalid.' );
		}
		$existing_license = ( new LicenseRepository() )->by_public_id( $public_id );
		if ( ! is_array( $existing_license ) ) {
			throw new InvalidArgumentException( 'The license could not be found.' );
		}
		if ( '' !== $product_public_id && $product_public_id !== (string) $existing_license['product_public_id'] ) {
			$matches = get_posts(
				array(
					'post_type'      => array( 'product', 'product_variation' ),
					'post_status'    => array( 'publish', 'private', 'draft', 'pending' ),
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- WooCommerce product identity is stored and queried through the plugin's post-meta contract.
					'meta_key'       => '_dreamax_lm_product_public_id',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- WooCommerce product identity is stored and queried through the plugin's post-meta contract.
					'meta_value'     => $product_public_id,
					'fields'         => 'ids',
					'posts_per_page' => 1,
					'no_found_rows'  => true,
				)
			);
			if ( array() === $matches ) {
				throw new InvalidArgumentException( 'The target product public ID does not belong to an active product or variation.' );
			}
		}

		$result = $this->transaction->run(
			function () use ( $public_id, $customer_id, $order_id, $product_public_id, $reset_activations, $reason, $operation_id, $actor_type, $actor_id ): array {
				global $wpdb;
				$row = $this->lock( $public_id );
				if ( $this->operation_applied( $row, $operation_id ) ) {
					return array(
						'public_id'   => $public_id,
						'customer_id' => (int) $row['customer_id'],
						'order_id'    => null === $row['order_id'] ? null : (int) $row['order_id'],
						'reset_count' => 0,
						'replayed'    => true,
					);
				}
				if ( 'revoked' === $row['lifecycle_status'] ) {
					throw new InvalidArgumentException( 'A permanently revoked license cannot be reassigned.' );
				}

				$before = array(
					'customer_id'       => null === $row['customer_id'] ? null : (int) $row['customer_id'],
					'order_id'          => null === $row['order_id'] ? null : (int) $row['order_id'],
					'product_public_id' => $row['product_public_id'],
					'lifecycle_status'  => $row['lifecycle_status'],
				);
				$after  = array(
					'customer_id'       => $customer_id,
					'order_id'          => $order_id > 0 ? $order_id : null,
					'product_public_id' => '' !== $product_public_id ? $product_public_id : $row['product_public_id'],
					'lifecycle_status'  => 'suspended' === $row['lifecycle_status'] ? 'suspended' : 'assigned',
				);
				$now    = gmdate( 'Y-m-d H:i:s' );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional tables require direct, fresh database reads and writes; object caching would break locking and replay guarantees.
				$updated = $wpdb->update(
					$wpdb->prefix . 'dreamax_lm_licenses',
					array(
						'customer_id'       => $after['customer_id'],
						'order_id'          => $after['order_id'],
						'order_item_id'     => null,
						'quantity_slot'     => null,
						'product_public_id' => $after['product_public_id'],
						'product_id'        => '' !== $product_public_id ? null : $row['product_id'],
						'variation_id'      => '' !== $product_public_id ? null : $row['variation_id'],
						'lifecycle_status'  => $after['lifecycle_status'],
						'updated_at'        => $now,
						'metadata'          => $this->mark_operation( $row, $operation_id ),
					),
					array( 'id' => (int) $row['id'] ),
					array( '%d', '%d', '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%s' ),
					array( '%d' )
				);
				if ( false === $updated ) {
					throw new RuntimeException( 'The reassignment could not be stored.' );
				}

				if ( ! empty( $before['order_id'] ) ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- An administrator ownership override invalidates every outstanding proof for the former order inside the same transaction.
					$invalidated = $wpdb->query(
						$wpdb->prepare(
							"UPDATE {$wpdb->prefix}dreamax_lm_guest_claims SET status='invalidated',active_order_id=NULL,token_hash=NULL,invalidated_at=%s,updated_at=%s WHERE order_id=%d AND status IN ('pending','issued')",
							$now,
							$now,
							(int) $before['order_id']
						)
					);
					if ( false === $invalidated ) {
						throw new RuntimeException( 'Outstanding guest claims could not be invalidated.' );
					}
					$this->events->append(
						AuditEventCatalog::GUEST_CLAIM_ADMINISTRATOR_OVERRIDE,
						(int) $row['id'],
						$actor_type,
						$actor_id,
						$operation_id,
						array(
							'order_id'    => (int) $before['order_id'],
							'customer_id' => $customer_id,
							'scope'       => 'single_license_reassignment',
						),
						AuditEventCatalog::SCHEMA_V1
					);
				}

				$reset_count = 0;
				if ( $reset_activations ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional tables require direct, fresh database reads and writes; object caching would break locking and replay guarantees.
					$reset = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}dreamax_lm_activations SET status='inactive',deactivated_at=%s,updated_at=%s WHERE license_id=%d AND status='active'", $now, $now, (int) $row['id'] ) );
					if ( false === $reset ) {
						throw new RuntimeException( 'The reassignment activation reset could not be stored.' );
					}
					$reset_count = (int) $reset;
				}

				$this->events->append(
					AuditEventCatalog::LICENSE_REASSIGNED,
					(int) $row['id'],
					$actor_type,
					$actor_id,
					$operation_id,
					array(
						'before'           => $before,
						'after'            => $after,
						'activation_reset' => $reset_activations,
						'reset_count'      => $reset_count,
						'reason'           => $reason,
					),
					AuditEventCatalog::SCHEMA_V1
				);
				return array(
					'public_id'   => $public_id,
					'customer_id' => $customer_id,
					'order_id'    => $after['order_id'],
					'reset_count' => $reset_count,
					'replayed'    => false,
				);
			}
		);

		if ( $notify && ! $result['replayed'] ) {
			$user = get_user_by( 'id', $customer_id );
			/* translators: %s: License public ID. */
			$sent = $user && wp_mail( (string) $user->user_email, __( 'A license was assigned to your account', 'dreamax-license-manager' ), sprintf( __( 'License %s is now available in the Licenses section of your account.', 'dreamax-license-manager' ), $public_id ) );
			$row  = ( new LicenseRepository() )->by_public_id( $public_id );
			if ( is_array( $row ) ) {
				$this->events->append( $sent ? AuditEventCatalog::LICENSE_REASSIGNMENT_NOTIFIED : AuditEventCatalog::LICENSE_REASSIGNMENT_NOTIFICATION_FAILED, (int) $row['id'], $actor_type, $actor_id, $operation_id, array( 'customer_id' => $customer_id ), AuditEventCatalog::SCHEMA_V1 );
			}
			$result['notification_sent'] = (bool) $sent;
		}

		return $result;
	}

	/**
	 * Handles the delete available operation.
	 *
	 * @param string $public_id Public id value.
	 * @param string $reason Reason value.
	 * @param string $operation_id Operation id value.
	 * @param string $actor_type Actor type value.
	 * @param ?int   $actor_id Actor id value.
	 * @phpstan-param int|null $actor_id Actor id value.
	 * @throws InvalidArgumentException When the operation cannot be completed.
	 * @throws RuntimeException When the operation cannot be completed.
	 * @return array<string,mixed>
	 */
	public function delete_available( string $public_id, string $reason, string $operation_id, string $actor_type, ?int $actor_id ): array {
		$this->assert_public_id( $public_id );
		$this->assert_reason( $reason );
		$this->assert_operation_id( $operation_id );

		return $this->transaction->run(
			function () use ( $public_id, $reason, $operation_id, $actor_type, $actor_id ): array {
				global $wpdb;
				$row = $this->lock( $public_id );
				if ( 'available' !== $row['lifecycle_status'] || ! empty( $row['customer_id'] ) || ! empty( $row['order_id'] ) ) {
					throw new InvalidArgumentException( 'Only unassigned pool licenses without customer or order history can be permanently deleted.' );
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional tables require direct, fresh database reads and writes; object caching would break locking and replay guarantees.
				$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_activations WHERE license_id=%d", (int) $row['id'] ) );
				if ( $count > 0 ) {
					throw new InvalidArgumentException( 'A license with activation history cannot be permanently deleted.' );
				}
				$this->events->append(
					AuditEventCatalog::LICENSE_DELETED,
					(int) $row['id'],
					$actor_type,
					$actor_id,
					$operation_id,
					array(
						'license_public_id' => $public_id,
						'reason'            => $reason,
					),
					AuditEventCatalog::SCHEMA_V1
				);
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional tables require direct, fresh database reads and writes; object caching would break locking and replay guarantees.
				$deleted = $wpdb->delete( $wpdb->prefix . 'dreamax_lm_licenses', array( 'id' => (int) $row['id'] ), array( '%d' ) );
				if ( 1 !== $deleted ) {
					throw new RuntimeException( 'The license could not be permanently deleted.' );
				}
				return array(
					'public_id' => $public_id,
					'deleted'   => true,
					'replayed'  => false,
				);
			}
		);
	}

	/**
	 * Handles the lock operation.
	 *
	 * @param string $public_id Public id value.
	 * @throws InvalidArgumentException When the operation cannot be completed.
	 * @return array<string,mixed>
	 */
	private function lock( string $public_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional tables require direct, fresh database reads and writes; object caching would break locking and replay guarantees.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}dreamax_lm_licenses WHERE public_id=%s FOR UPDATE", $public_id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			throw new InvalidArgumentException( 'The license could not be found.' );
		}
		return $row;
	}

	/**
	 * Handles the operation applied operation.
	 *
	 * @param array  $row Row value.
	 * @phpstan-param array<string,mixed> $row Row value.
	 * @param string $operation_id Operation id value.
	 */
	private function operation_applied( array $row, string $operation_id ): bool {
		$metadata   = json_decode( (string) ( $row['metadata'] ?? '{}' ), true );
		$operations = is_array( $metadata ) && is_array( $metadata['lifecycle_operations'] ?? null ) ? $metadata['lifecycle_operations'] : array();
		return in_array( hash( 'sha256', $operation_id ), $operations, true );
	}

	/**
	 * Handles the mark operation operation.
	 *
	 * @param array  $row Row value.
	 * @phpstan-param array<string,mixed> $row Row value.
	 * @param string $operation_id Operation id value.
	 * @throws RuntimeException When the operation cannot be completed.
	 */
	private function mark_operation( array $row, string $operation_id ): string {
		$metadata                         = json_decode( (string) ( $row['metadata'] ?? '{}' ), true );
		$metadata                         = is_array( $metadata ) ? $metadata : array();
		$operations                       = is_array( $metadata['lifecycle_operations'] ?? null ) ? $metadata['lifecycle_operations'] : array();
		$operations[]                     = hash( 'sha256', $operation_id );
		$metadata['lifecycle_operations'] = array_slice( array_values( array_unique( $operations ) ), -50 );
		$encoded                          = wp_json_encode( $metadata );
		if ( ! is_string( $encoded ) ) {
			throw new RuntimeException( 'The lifecycle operation marker could not be encoded.' );
		}
		return $encoded;
	}

	/**
	 * Returns a stored commercial-command replay record.
	 *
	 * @param array<string,mixed> $row License row.
	 * @param string              $operation_id Operation identifier.
	 * @return array<string,mixed>|null
	 */
	private function commercial_operation_record( array $row, string $operation_id ): ?array {
		$metadata = json_decode( (string) ( $row['metadata'] ?? '{}' ), true );
		$records  = is_array( $metadata ) && is_array( $metadata['commercial_extension_operations'] ?? null ) ? $metadata['commercial_extension_operations'] : array();
		$key      = hash( 'sha256', $operation_id );
		$record   = $records[ $key ] ?? null;
		return is_array( $record ) ? $record : null;
	}

	/**
	 * Stores a bounded command digest and its original result for exact replay.
	 *
	 * @param array<string,mixed> $row License row.
	 * @param string              $operation_id Operation identifier.
	 * @param string              $request_digest Canonical request digest.
	 * @param string              $expires_at Resulting UTC expiry.
	 * @throws RuntimeException When metadata encoding fails.
	 */
	private function mark_commercial_operation( array $row, string $operation_id, string $request_digest, string $expires_at ): string {
		$metadata                         = json_decode( (string) ( $row['metadata'] ?? '{}' ), true );
		$metadata                         = is_array( $metadata ) ? $metadata : array();
		$operations                       = is_array( $metadata['lifecycle_operations'] ?? null ) ? $metadata['lifecycle_operations'] : array();
		$key                              = hash( 'sha256', $operation_id );
		$operations[]                     = $key;
		$metadata['lifecycle_operations'] = array_slice( array_values( array_unique( $operations ) ), -50 );

		$records                                     = is_array( $metadata['commercial_extension_operations'] ?? null ) ? $metadata['commercial_extension_operations'] : array();
		$records[ $key ]                             = array(
			'request_digest' => $request_digest,
			'expires_at'     => $expires_at,
		);
		$metadata['commercial_extension_operations'] = array_slice( $records, -50, null, true );
		$encoded                                     = wp_json_encode( $metadata );
		if ( ! is_string( $encoded ) ) {
			throw new RuntimeException( 'The commercial command marker could not be encoded.' );
		}
		return $encoded;
	}

	/**
	 * Handles the assert public id operation.
	 *
	 * @param string $public_id Public id value.
	 * @throws InvalidArgumentException When the operation cannot be completed.
	 */
	private function assert_public_id( string $public_id ): void {
		if ( ! preg_match( '/^lic_[A-Za-z0-9_-]{22}$/D', $public_id ) ) {
			throw new InvalidArgumentException( 'The license public ID is invalid.' );
		}
	}

	/**
	 * Handles the assert reason operation.
	 *
	 * @param string $reason Reason value.
	 * @throws InvalidArgumentException When the operation cannot be completed.
	 */
	private function assert_reason( string $reason ): void {
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $reason ) : strlen( $reason );
		if ( $length < 3 || $length > 500 ) {
			throw new InvalidArgumentException( 'Provide a reason between 3 and 500 characters.' );
		}
	}

	/**
	 * Handles the assert operation id operation.
	 *
	 * @param string $operation_id Operation id value.
	 * @throws InvalidArgumentException When the operation cannot be completed.
	 */
	private function assert_operation_id( string $operation_id ): void {
		if ( ! preg_match( '/^[A-Za-z0-9._:-]{16,64}$/D', $operation_id ) ) {
			throw new InvalidArgumentException( 'The operation identifier is invalid.' );
		}
	}
}
