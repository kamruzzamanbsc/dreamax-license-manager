<?php
/**
 * Defines the GuestClaimService class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\CustomerPortal;

use Dreamax\LicenseManager\Api\RateLimiter;
use Dreamax\LicenseManager\Api\SourceAddress;
use Dreamax\LicenseManager\Database\Transaction;
use Dreamax\LicenseManager\Encryption\Crypto;
use Dreamax\LicenseManager\Events\EventRepository;
use Dreamax\LicenseManager\Support\PublicId;
use RuntimeException;
use WC_Order;

/**
 * Coordinates secure guest-order ownership claims.
 */
final class GuestClaimService {
	/**
	 * Prevents the service's own WooCommerce CRUD save from invalidating its transaction.
	 *
	 * @var bool
	 */
	private static bool $internal_order_update = false;

	/**
	 * Claim policy value.
	 *
	 * @var GuestClaimPolicy
	 */
	private GuestClaimPolicy $policy;

	/**
	 * Claim token value.
	 *
	 * @var GuestClaimToken
	 */
	private GuestClaimToken $tokens;

	/**
	 * Crypto value.
	 *
	 * @var Crypto
	 */
	private Crypto $crypto;

	/**
	 * Transaction value.
	 *
	 * @var Transaction
	 */
	private Transaction $transaction;

	/**
	 * Event repository value.
	 *
	 * @var EventRepository
	 */
	private EventRepository $events;

	/**
	 * Rate limiter value.
	 *
	 * @var RateLimiter
	 */
	private RateLimiter $rates;

	/**
	 * Source address value.
	 *
	 * @var SourceAddress
	 */
	private SourceAddress $source;

	/**
	 * Initializes the service.
	 */
	public function __construct() {
		$this->policy      = new GuestClaimPolicy();
		$this->tokens      = new GuestClaimToken();
		$this->crypto      = new Crypto();
		$this->transaction = new Transaction();
		$this->events      = new EventRepository();
		$this->rates       = new RateLimiter();
		$this->source      = new SourceAddress();
	}

	/**
	 * Registers authoritative WooCommerce ownership-change invalidation.
	 */
	public function register(): void {
		add_action( 'woocommerce_update_order', array( $this, 'order_updated' ), 20, 2 );
	}

	/**
	 * Issues a proof to the authoritative billing email when the request is eligible.
	 *
	 * The boolean is internal only. Callers must always show the same public response.
	 *
	 * @param int    $user_id Authenticated WordPress user ID.
	 * @param int    $order_id WooCommerce order ID.
	 * @param string $billing_email Supplied billing email.
	 * @param ?int   $lifetime Optional lifetime override.
	 * @phpstan-param int|null $lifetime
	 */
	public function issue( int $user_id, int $order_id, string $billing_email, ?int $lifetime = null ): bool {
		$this->rate_limit( 'issue', $user_id, $order_id );
		$configured_lifetime = null === $lifetime
			? (int) apply_filters( 'dreamax_lm_guest_claim_lifetime', GuestClaimPolicy::DEFAULT_LIFETIME )
			: $lifetime;
		$lifetime            = $this->policy->lifetime( $configured_lifetime );
		$order               = $order_id > 0 ? wc_get_order( $order_id ) : null;
		$account             = $user_id > 0 ? get_userdata( $user_id ) : false;
		$normalized_email    = strtolower( sanitize_email( $billing_email ) );

		if ( ! $account || ! $order instanceof WC_Order || ! $order->is_paid() || (int) $order->get_customer_id() > 0 ) {
			return false;
		}
		$authoritative_email = strtolower( sanitize_email( (string) $order->get_billing_email() ) );
		$account_email       = strtolower( sanitize_email( (string) $account->user_email ) );
		if ( '' === $normalized_email || '' === $authoritative_email || '' === $account_email || ! hash_equals( $authoritative_email, $normalized_email ) || ! hash_equals( $authoritative_email, $account_email ) ) {
			return false;
		}
		if ( array() === $this->license_rows( $order_id, false ) ) {
			return false;
		}

		$token          = $this->tokens->generate();
		$token_hash     = $this->crypto->keyed_hash( $token, 'guest-claim-token' );
		$ownership_hash = $this->ownership_hash( $order );
		$expires_at     = gmdate( 'Y-m-d H:i:s', time() + $lifetime );
		$claim_id       = $this->transaction->run(
			function () use ( $order_id, $user_id, $token_hash, $ownership_hash, $expires_at ): ?string {
				global $wpdb;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reissuance must lock the unique active claim without a cache.
				$active = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT id,public_id FROM {$wpdb->prefix}dreamax_lm_guest_claims WHERE active_order_id=%d FOR UPDATE",
						$order_id
					),
					ARRAY_A
				);
				if ( is_array( $active ) ) {
					$this->invalidate_claim( (int) $active['id'], 'invalidated' );
				}
				$owner = $this->owner_row( $order_id, true );
				if ( is_array( $owner ) && ! empty( $owner['customer_id'] ) ) {
					return null;
				}

				$public_id = PublicId::generate( 'clm' );
				$now       = gmdate( 'Y-m-d H:i:s' );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin-owned transactional claim storage requires direct writes.
				$inserted = $wpdb->insert(
					$wpdb->prefix . 'dreamax_lm_guest_claims',
					array(
						'public_id'       => $public_id,
						'order_id'        => $order_id,
						'active_order_id' => $order_id,
						'target_user_id'  => $user_id,
						'token_hash'      => $token_hash,
						'ownership_hash'  => $ownership_hash,
						'status'          => 'pending',
						'expires_at'      => $expires_at,
						'created_at'      => $now,
						'updated_at'      => $now,
					),
					array( '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
				);
				if ( false === $inserted ) {
					throw new RuntimeException( 'The guest claim could not be stored.' );
				}
				return $public_id;
			}
		);

		if ( null === $claim_id ) {
			sodium_memzero( $token );
			return false;
		}

		/* translators: %d: WooCommerce order ID. */
		$subject = sprintf( __( 'Your one-time claim code for order #%d', 'dreamax-license-manager' ), $order_id );
		/* translators: 1: WooCommerce order ID. 2: One-time claim code. 3: Claim lifetime in minutes. */
		$message = sprintf( __( "A signed-in customer requested to claim the licenses for order #%1\$d.\n\nEnter this one-time code in My Account > Licenses:\n\n%2\$s\n\nThe code expires in %3\$d minutes and can be used once. If you did not request this, ignore this email.", 'dreamax-license-manager' ), $order_id, $token, (int) ceil( $lifetime / MINUTE_IN_SECONDS ) );
		$sent    = wp_mail( $authoritative_email, $subject, $message );
		sodium_memzero( $token );
		unset( $message );

		if ( ! $sent ) {
			$this->transaction->run(
				function () use ( $claim_id ): void {
					$claim = $this->claim_by_public_id( $claim_id, true );
					if ( is_array( $claim ) ) {
						$this->invalidate_claim( (int) $claim['id'], 'invalidated' );
					}
				}
			);
			return false;
		}

		$this->transaction->run(
			function () use ( $claim_id, $user_id, $order_id, $lifetime ): void {
				global $wpdb;
				$claim = $this->claim_by_public_id( $claim_id, true );
				if ( ! is_array( $claim ) || 'pending' !== $claim['status'] ) {
					throw new RuntimeException( 'The delivered guest claim is no longer active.' );
				}
				$now = gmdate( 'Y-m-d H:i:s' );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Claim state transition must be atomic and fresh.
				$updated = $wpdb->update(
					$wpdb->prefix . 'dreamax_lm_guest_claims',
					array(
						'status'     => 'issued',
						'issued_at'  => $now,
						'updated_at' => $now,
					),
					array( 'id' => (int) $claim['id'] ),
					array( '%s', '%s', '%s' ),
					array( '%d' )
				);
				if ( false === $updated ) {
					throw new RuntimeException( 'The delivered guest claim could not be activated.' );
				}
				$this->events->append(
					'guest_claim_issued',
					null,
					'customer',
					$user_id,
					$claim_id,
					array(
						'order_id'         => $order_id,
						'lifetime_seconds' => $lifetime,
					)
				);
			}
		);
		return true;
	}

	/**
	 * Atomically verifies and consumes one claim proof.
	 *
	 * @param int    $user_id Authenticated WordPress user ID.
	 * @param int    $order_id WooCommerce order ID.
	 * @param string $token Presented one-time proof.
	 * @return array{success:bool,code:string,message:string}
	 */
	public function verify( int $user_id, int $order_id, string $token ): array {
		$this->rate_limit( 'verify', $user_id, $order_id );
		$order = $order_id > 0 ? wc_get_order( $order_id ) : null;
		$proof = $this->tokens->valid_format( $token ) ? $token : str_repeat( 'A', 43 );
		$hash  = $this->crypto->keyed_hash( $proof, 'guest-claim-token' );
		if ( ! $order instanceof WC_Order || $user_id < 1 || ! $this->tokens->valid_format( $token ) ) {
			return $this->policy->public_failure();
		}

		return $this->transaction->run(
			function () use ( $user_id, $order_id, $hash, $order ): array {
				global $wpdb;
				$claim = $this->latest_claim( $order_id, true );
				if ( ! is_array( $claim ) ) {
					return $this->policy->public_failure();
				}
				$owner          = $this->owner_row( $order_id, true );
				$existing_owner = is_array( $owner ) && ! empty( $owner['customer_id'] ) ? (int) $owner['customer_id'] : null;
				$current_hash   = $this->ownership_hash( $order );
				$failure        = $this->policy->verification_failure( $claim, $user_id, $hash, $current_hash, time(), $existing_owner );
				if ( null !== $failure ) {
					$ownership_changed = is_string( $claim['ownership_hash'] ) && $this->policy->ownership_changed( $claim['ownership_hash'], $current_hash );
					if ( 'expired' === $failure || $ownership_changed ) {
						$this->invalidate_claim( (int) $claim['id'], 'expired' === $failure ? 'expired' : 'invalidated' );
					}
					$this->events->append(
						$this->policy->failure_event( $failure ),
						null,
						'customer',
						$user_id,
						(string) $claim['public_id'],
						array(
							'order_id' => $order_id,
							'failure'  => $failure,
						)
					);
					return $this->policy->public_failure();
				}

				$licenses = $this->license_rows( $order_id, true );
				if ( array() === $licenses || ( (int) $order->get_customer_id() > 0 && (int) $order->get_customer_id() !== $user_id ) ) {
					$this->events->append(
						'guest_claim_conflict_failed',
						null,
						'customer',
						$user_id,
						(string) $claim['public_id'],
						array(
							'order_id' => $order_id,
							'failure'  => 'conflict',
						)
					);
					return $this->policy->public_failure();
				}
				foreach ( $licenses as $license ) {
					if ( ! empty( $license['customer_id'] ) && (int) $license['customer_id'] !== $user_id ) {
						$this->events->append(
							'guest_claim_conflict_failed',
							null,
							'customer',
							$user_id,
							(string) $claim['public_id'],
							array(
								'order_id' => $order_id,
								'failure'  => 'conflict',
							)
						);
						return $this->policy->public_failure();
					}
				}

				$this->save_order_customer( $order, $user_id );
				$now          = gmdate( 'Y-m-d H:i:s' );
				$claimed_hash = $this->ownership_hash( $order );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Claimed ownership is stored atomically with the order owner and consumed proof.
				$license_update = $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->prefix}dreamax_lm_licenses SET customer_id=%d,updated_at=%s WHERE order_id=%d AND (customer_id IS NULL OR customer_id=0 OR customer_id=%d)",
						$user_id,
						$now,
						$order_id,
						$user_id
					)
				);
				if ( false === $license_update ) {
					throw new RuntimeException( 'Claimed license ownership could not be stored.' );
				}
				$this->store_owner( $order_id, $user_id, (int) $claim['id'], $claimed_hash, 'guest_claim', $owner );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Single-use consumption must be an atomic direct update.
				$consumed = $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->prefix}dreamax_lm_guest_claims SET status='consumed',active_order_id=NULL,token_hash=NULL,consumed_at=%s,updated_at=%s WHERE id=%d AND status='issued'",
						$now,
						$now,
						(int) $claim['id']
					)
				);
				if ( 1 !== $consumed ) {
					throw new RuntimeException( 'The guest claim could not be consumed exactly once.' );
				}
				$this->invalidate_other_claims( $order_id, (int) $claim['id'] );
				$this->events->append(
					'guest_claim_succeeded',
					null,
					'customer',
					$user_id,
					(string) $claim['public_id'],
					array(
						'order_id'      => $order_id,
						'license_count' => count( $licenses ),
					)
				);
				return array(
					'success' => true,
					'code'    => 'claim_completed',
					'message' => 'The order licenses are now linked to your account.',
				);
			}
		);
	}

	/**
	 * Invalidates outstanding proofs after an authoritative WooCommerce ownership change.
	 *
	 * @param int           $order_id Order ID.
	 * @param WC_Order|null $order Optional order object from WooCommerce.
	 */
	public function order_updated( int $order_id, $order = null ): void {
		if ( self::$internal_order_update ) {
			return;
		}
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$current_hash = $this->ownership_hash( $order );
		$this->transaction->run(
			function () use ( $order_id, $current_hash ): void {
				global $wpdb;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Pending proofs must be locked against concurrent verification.
				$claims = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}dreamax_lm_guest_claims WHERE active_order_id=%d FOR UPDATE", $order_id ), ARRAY_A );
				foreach ( is_array( $claims ) ? $claims : array() as $claim ) {
					if ( is_string( $claim['ownership_hash'] ) && $this->policy->ownership_changed( $claim['ownership_hash'], $current_hash ) ) {
						$this->invalidate_claim( (int) $claim['id'], 'invalidated' );
						$this->events->append(
							'guest_claim_conflict_failed',
							null,
							'woocommerce',
							null,
							(string) $claim['public_id'],
							array(
								'order_id' => $order_id,
								'failure'  => 'ownership_changed',
							)
						);
					}
				}
			}
		);
	}

	/**
	 * Releases claimed ownership back to guest status.
	 *
	 * @param int $order_id Order ID.
	 * @param int $actor_id Administrator user ID.
	 * @throws RuntimeException When the ownership release cannot be completed.
	 */
	public function admin_release( int $order_id, int $actor_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			throw new RuntimeException( 'The order could not be found.' );
		}
		$this->transaction->run(
			function () use ( $order_id, $actor_id, $order ): void {
				global $wpdb;
				$licenses = $this->license_rows( $order_id, true );
				if ( array() === $licenses ) {
					throw new RuntimeException( 'The order has no licenses to release.' );
				}
				$this->owner_row( $order_id, true );
				$this->invalidate_other_claims( $order_id, 0 );
				$this->save_order_customer( $order, 0 );
				$now = gmdate( 'Y-m-d H:i:s' );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Administrative release is a confirmed transactional ownership change.
				$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}dreamax_lm_licenses SET customer_id=NULL,updated_at=%s WHERE order_id=%d", $now, $order_id ) );
				if ( false === $updated ) {
					throw new RuntimeException( 'The license ownership release could not be stored.' );
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The unique ownership row is removed only by confirmed release.
				$wpdb->delete( $wpdb->prefix . 'dreamax_lm_order_owners', array( 'order_id' => $order_id ), array( '%d' ) );
				$this->events->append(
					'guest_claim_released',
					null,
					'administrator',
					$actor_id,
					null,
					array(
						'order_id'      => $order_id,
						'license_count' => count( $licenses ),
					)
				);
			}
		);
	}

	/**
	 * Overrides claimed ownership after an administrator verifies the target.
	 *
	 * @param int $order_id Order ID.
	 * @param int $customer_id Target user ID.
	 * @param int $actor_id Administrator user ID.
	 * @throws RuntimeException When the ownership override cannot be completed.
	 */
	public function admin_override( int $order_id, int $customer_id, int $actor_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || $customer_id < 1 || ! get_userdata( $customer_id ) ) {
			throw new RuntimeException( 'Choose an existing order and customer account.' );
		}
		$this->transaction->run(
			function () use ( $order_id, $customer_id, $actor_id, $order ): void {
				global $wpdb;
				$licenses = $this->license_rows( $order_id, true );
				if ( array() === $licenses ) {
					throw new RuntimeException( 'The order has no licenses to override.' );
				}
				$owner = $this->owner_row( $order_id, true );
				$this->invalidate_other_claims( $order_id, 0 );
				$this->save_order_customer( $order, $customer_id );
				$now = gmdate( 'Y-m-d H:i:s' );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Confirmed override updates plugin-owned license rows inside the ownership transaction.
				$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}dreamax_lm_licenses SET customer_id=%d,updated_at=%s WHERE order_id=%d", $customer_id, $now, $order_id ) );
				if ( false === $updated ) {
					throw new RuntimeException( 'The administrative ownership override could not be stored.' );
				}
				$this->store_owner( $order_id, $customer_id, null, $this->ownership_hash( $order ), 'admin_override', $owner );
				$this->events->append(
					'guest_claim_administrator_override',
					null,
					'administrator',
					$actor_id,
					null,
					array(
						'order_id'      => $order_id,
						'customer_id'   => $customer_id,
						'license_count' => count( $licenses ),
					)
				);
			}
		);
	}

	/**
	 * Applies all claim rate-limit buckets.
	 *
	 * @param string $operation Operation name.
	 * @param int    $user_id User ID.
	 * @param int    $order_id Order ID.
	 */
	private function rate_limit( string $operation, int $user_id, int $order_id ): void {
		$limits = $this->policy->rate_limits( $operation );
		$this->rates->consume( "guest-claim:{$operation}:user:{$user_id}", $limits['user']['capacity'], $limits['user']['refill'] );
		$this->rates->consume( "guest-claim:{$operation}:network:" . $this->source->network(), $limits['network']['capacity'], $limits['network']['refill'] );
		$this->rates->consume( "guest-claim:{$operation}:order:{$order_id}", $limits['order']['capacity'], $limits['order']['refill'] );
	}

	/**
	 * Computes a keyed snapshot of authoritative WooCommerce ownership fields.
	 *
	 * @param WC_Order $order Order object.
	 */
	private function ownership_hash( WC_Order $order ): string {
		$value = implode(
			'|',
			array(
				(string) $order->get_id(),
				(string) $order->get_customer_id(),
				strtolower( sanitize_email( (string) $order->get_billing_email() ) ),
				(string) $order->get_order_key(),
			)
		);
		return $this->crypto->keyed_hash( $value, 'guest-claim-ownership' );
	}

	/**
	 * Persists an HPOS-compatible order customer change inside the active transaction.
	 *
	 * @param WC_Order $order Order object.
	 * @param int      $customer_id Customer ID, or zero for guest.
	 */
	private function save_order_customer( WC_Order $order, int $customer_id ): void {
		self::$internal_order_update = true;
		try {
			$order->set_customer_id( $customer_id );
			$order->save();
		} finally {
			self::$internal_order_update = false;
		}
	}

	/**
	 * Returns order license rows, optionally locked.
	 *
	 * @param int  $order_id Order ID.
	 * @param bool $lock Whether to lock rows.
	 * @return list<array<string,mixed>>
	 */
	private function license_rows( int $order_id, bool $lock ): array {
		global $wpdb;
		$suffix = $lock ? ' FOR UPDATE' : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The suffix is one fixed optional FOR UPDATE clause; claim ownership requires a direct fresh read.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,customer_id FROM {$wpdb->prefix}dreamax_lm_licenses WHERE order_id=%d ORDER BY id{$suffix}", $order_id ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Loads the latest claim for an order.
	 *
	 * @param int  $order_id Order ID.
	 * @param bool $lock Whether to lock the row.
	 * @return array<string,mixed>|null
	 */
	private function latest_claim( int $order_id, bool $lock ): ?array {
		global $wpdb;
		$suffix = $lock ? ' FOR UPDATE' : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The suffix is one fixed optional FOR UPDATE clause; verification requires the latest fresh claim.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}dreamax_lm_guest_claims WHERE order_id=%d ORDER BY id DESC LIMIT 1{$suffix}", $order_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Loads a claim by public ID.
	 *
	 * @param string $public_id Claim public ID.
	 * @param bool   $lock Whether to lock the row.
	 * @return array<string,mixed>|null
	 */
	private function claim_by_public_id( string $public_id, bool $lock ): ?array {
		global $wpdb;
		$suffix = $lock ? ' FOR UPDATE' : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The suffix is one fixed optional FOR UPDATE clause; finalization requires a fresh claim.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}dreamax_lm_guest_claims WHERE public_id=%s LIMIT 1{$suffix}", $public_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Loads the unique claimed owner row.
	 *
	 * @param int  $order_id Order ID.
	 * @param bool $lock Whether to lock the row.
	 * @return array<string,mixed>|null
	 */
	private function owner_row( int $order_id, bool $lock ): ?array {
		global $wpdb;
		$suffix = $lock ? ' FOR UPDATE' : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The suffix is one fixed optional FOR UPDATE clause; ownership requires a fresh unique row.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}dreamax_lm_order_owners WHERE order_id=%d{$suffix}", $order_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Stores the unique order owner.
	 *
	 * @param int        $order_id Order ID.
	 * @param int        $customer_id Customer ID.
	 * @param int|null   $claim_id Claim database ID.
	 * @param string     $ownership_hash Authoritative ownership hash.
	 * @param string     $source Ownership source.
	 * @param array|null $existing Existing owner row.
	 * @phpstan-param array<string,mixed>|null $existing
	 * @throws RuntimeException When unique ownership cannot be stored.
	 */
	private function store_owner( int $order_id, int $customer_id, ?int $claim_id, string $ownership_hash, string $source, ?array $existing ): void {
		global $wpdb;
		$now  = gmdate( 'Y-m-d H:i:s' );
		$data = array(
			'customer_id'    => $customer_id,
			'claim_id'       => $claim_id,
			'ownership_hash' => $ownership_hash,
			'source'         => $source,
			'updated_at'     => $now,
		);
		if ( is_array( $existing ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Unique ownership is updated inside the same transaction as order and license ownership.
			$stored = $wpdb->update( $wpdb->prefix . 'dreamax_lm_order_owners', $data, array( 'order_id' => $order_id ), array( '%d', '%d', '%s', '%s', '%s' ), array( '%d' ) );
		} else {
			$data['order_id']   = $order_id;
			$data['claimed_at'] = $now;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Database uniqueness is the final concurrent-claim guard.
			$stored = $wpdb->insert( $wpdb->prefix . 'dreamax_lm_order_owners', $data, array( '%d', '%d', '%s', '%s', '%s', '%d', '%s' ) );
		}
		if ( false === $stored ) {
			throw new RuntimeException( 'The unique order owner could not be stored.' );
		}
	}

	/**
	 * Invalidates one active claim and clears its keyed token hash.
	 *
	 * @param int    $claim_id Claim database ID.
	 * @param string $status Final status.
	 * @throws RuntimeException When invalidation cannot be stored.
	 */
	private function invalidate_claim( int $claim_id, string $status ): void {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Invalidation must be atomic with the ownership operation.
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}dreamax_lm_guest_claims SET status=%s,active_order_id=NULL,token_hash=NULL,invalidated_at=%s,updated_at=%s WHERE id=%d", $status, $now, $now, $claim_id ) );
		if ( false === $updated ) {
			throw new RuntimeException( 'The guest claim could not be invalidated.' );
		}
	}

	/**
	 * Invalidates all other outstanding claims for an order.
	 *
	 * @param int $order_id Order ID.
	 * @param int $except_id Claim ID to preserve, or zero.
	 * @throws RuntimeException When outstanding claims cannot be invalidated.
	 */
	private function invalidate_other_claims( int $order_id, int $except_id ): void {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Successful claim/admin ownership changes invalidate every other outstanding proof atomically.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}dreamax_lm_guest_claims SET status='invalidated',active_order_id=NULL,token_hash=NULL,invalidated_at=%s,updated_at=%s WHERE order_id=%d AND id<>%d AND status IN ('pending','issued')",
				$now,
				$now,
				$order_id,
				$except_id
			)
		);
		if ( false === $updated ) {
			throw new RuntimeException( 'Outstanding guest claims could not be invalidated.' );
		}
	}
}
