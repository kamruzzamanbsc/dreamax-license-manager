<?php
/**
 * Defines the GuestClaimPolicy class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\CustomerPortal;

use InvalidArgumentException;

/**
 * Holds the deterministic security policy for guest-order claims.
 */
final class GuestClaimPolicy {
	public const DEFAULT_LIFETIME = 30 * MINUTE_IN_SECONDS;
	public const MIN_LIFETIME     = 5 * MINUTE_IN_SECONDS;
	public const MAX_LIFETIME     = 24 * HOUR_IN_SECONDS;

	/**
	 * Validates and returns a configured claim lifetime.
	 *
	 * @param int $seconds Lifetime in seconds.
	 * @throws InvalidArgumentException When the lifetime is outside the documented bounds.
	 */
	public function lifetime( int $seconds ): int {
		if ( $seconds < self::MIN_LIFETIME || $seconds > self::MAX_LIFETIME ) {
			throw new InvalidArgumentException( 'Claim lifetime must be between five minutes and 24 hours.' );
		}
		return $seconds;
	}

	/**
	 * Returns a stable internal failure classification, or null when verification may proceed.
	 *
	 * @param array  $claim Claim row.
	 * @phpstan-param array{status:string,target_user_id:mixed,expires_at:string,token_hash:mixed,ownership_hash:mixed} $claim
	 * @param int    $user_id Authenticated user ID.
	 * @param string $presented_hash Keyed hash of the presented proof.
	 * @param string $ownership_hash Current authoritative order ownership hash.
	 * @param int    $now Current Unix timestamp.
	 * @param ?int   $existing_owner Existing claimed owner.
	 * @phpstan-param int|null $existing_owner
	 */
	public function verification_failure( array $claim, int $user_id, string $presented_hash, string $ownership_hash, int $now, ?int $existing_owner ): ?string {
		$status = (string) $claim['status'];
		if ( 'consumed' === $status ) {
			return 'replay';
		}
		if ( null !== $existing_owner ) {
			return 'conflict';
		}
		if ( 'expired' === $status || strtotime( (string) $claim['expires_at'] . ' UTC' ) <= $now ) {
			return 'expired';
		}
		if ( 'issued' !== $status || $user_id < 1 || $user_id !== (int) $claim['target_user_id'] ) {
			return 'conflict';
		}
		if ( ! is_string( $claim['token_hash'] ) || ! hash_equals( $claim['token_hash'], $presented_hash ) ) {
			return 'conflict';
		}
		if ( ! is_string( $claim['ownership_hash'] ) || ! hash_equals( $claim['ownership_hash'], $ownership_hash ) ) {
			return 'conflict';
		}
		return null;
	}

	/**
	 * Determines whether an authoritative order change invalidates a pending proof.
	 *
	 * @param string $stored_hash Stored ownership hash.
	 * @param string $current_hash Current ownership hash.
	 */
	public function ownership_changed( string $stored_hash, string $current_hash ): bool {
		return ! hash_equals( $stored_hash, $current_hash );
	}

	/**
	 * Returns the fixed public failure shape used for all claim verification failures.
	 *
	 * @return array{success:false,code:string,message:string}
	 */
	public function public_failure(): array {
		return array(
			'success' => false,
			'code'    => 'claim_not_completed',
			'message' => 'The claim could not be completed. Check the details or request a new code.',
		);
	}

	/**
	 * Maps an internal failure to its versioned audit type.
	 *
	 * @param string $failure Failure classification.
	 */
	public function failure_event( string $failure ): string {
		if ( 'expired' === $failure ) {
			return 'guest_claim_expired_failed';
		}
		if ( 'replay' === $failure ) {
			return 'guest_claim_replay_failed';
		}
		return 'guest_claim_conflict_failed';
	}

	/**
	 * Returns rate-limit settings for one claim operation.
	 *
	 * @param string $operation Operation name.
	 * @return array{user:array{capacity:int,refill:float},network:array{capacity:int,refill:float},order:array{capacity:int,refill:float}}
	 */
	public function rate_limits( string $operation ): array {
		if ( 'verify' === $operation ) {
			return array(
				'user'    => array(
					'capacity' => 10,
					'refill'   => 1 / 180,
				),
				'network' => array(
					'capacity' => 20,
					'refill'   => 1 / 180,
				),
				'order'   => array(
					'capacity' => 10,
					'refill'   => 1 / 180,
				),
			);
		}
		return array(
			'user'    => array(
				'capacity' => 5,
				'refill'   => 1 / 600,
			),
			'network' => array(
				'capacity' => 10,
				'refill'   => 1 / 600,
			),
			'order'   => array(
				'capacity' => 5,
				'refill'   => 1 / 600,
			),
		);
	}

	/**
	 * Validates the capability and target shape for an administrator ownership action.
	 *
	 * @param bool   $can_manage Whether the actor has the dedicated management capability.
	 * @param string $operation Release or override.
	 * @param int    $target_user_id Target user ID for override.
	 */
	public function admin_action_allowed( bool $can_manage, string $operation, int $target_user_id ): bool {
		if ( ! $can_manage || ! in_array( $operation, array( 'release', 'override' ), true ) ) {
			return false;
		}
		return 'release' === $operation || $target_user_id > 0;
	}
}
