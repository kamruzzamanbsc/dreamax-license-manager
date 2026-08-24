<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use Dreamax\LicenseManager\CustomerPortal\GuestClaimPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class GuestClaimPolicyTest extends TestCase {
	private GuestClaimPolicy $policy;

	protected function setUp(): void {
		$this->policy = new GuestClaimPolicy();
	}

	public function test_authenticated_matching_claim_can_proceed(): void {
		$hash  = str_repeat( 'h', 32 );
		$claim = $this->claim( 'issued', 41, $hash, str_repeat( 'o', 32 ), '2030-01-01 00:30:00' );
		self::assertNull( $this->policy->verification_failure( $claim, 41, $hash, str_repeat( 'o', 32 ), strtotime( '2030-01-01 00:00:00 UTC' ), null ) );
	}

	public function test_wrong_account_and_wrong_order_snapshot_are_conflicts(): void {
		$hash  = str_repeat( 'h', 32 );
		$claim = $this->claim( 'issued', 41, $hash, str_repeat( 'o', 32 ), '2030-01-01 00:30:00' );
		self::assertSame( 'conflict', $this->policy->verification_failure( $claim, 42, $hash, str_repeat( 'o', 32 ), strtotime( '2030-01-01 00:00:00 UTC' ), null ) );
		self::assertSame( 'conflict', $this->policy->verification_failure( $claim, 41, $hash, str_repeat( 'x', 32 ), strtotime( '2030-01-01 00:00:00 UTC' ), null ) );
	}

	public function test_expired_claim_is_rejected(): void {
		$hash = str_repeat( 'h', 32 );
		self::assertSame( 'expired', $this->policy->verification_failure( $this->claim( 'issued', 41, $hash, str_repeat( 'o', 32 ), '2030-01-01 00:00:00' ), 41, $hash, str_repeat( 'o', 32 ), strtotime( '2030-01-01 00:00:01 UTC' ), null ) );
	}

	public function test_consumed_claim_is_replay_and_existing_owner_is_conflict(): void {
		$hash = str_repeat( 'h', 32 );
		self::assertSame( 'replay', $this->policy->verification_failure( $this->claim( 'consumed', 41, $hash, str_repeat( 'o', 32 ), '2030-01-01 00:30:00' ), 41, $hash, str_repeat( 'o', 32 ), strtotime( '2030-01-01 00:00:00 UTC' ), null ) );
		self::assertSame( 'conflict', $this->policy->verification_failure( $this->claim( 'issued', 41, $hash, str_repeat( 'o', 32 ), '2030-01-01 00:30:00' ), 41, $hash, str_repeat( 'o', 32 ), strtotime( '2030-01-01 00:00:00 UTC' ), 42 ) );
	}

	public function test_wrong_code_is_a_non_enumerating_conflict(): void {
		$claim = $this->claim( 'issued', 41, str_repeat( 'h', 32 ), str_repeat( 'o', 32 ), '2030-01-01 00:30:00' );
		self::assertSame( 'conflict', $this->policy->verification_failure( $claim, 41, str_repeat( 'x', 32 ), str_repeat( 'o', 32 ), strtotime( '2030-01-01 00:00:00 UTC' ), null ) );
	}

	public function test_default_and_maximum_lifetime_boundaries(): void {
		self::assertSame( 1800, GuestClaimPolicy::DEFAULT_LIFETIME );
		self::assertSame( 86400, $this->policy->lifetime( GuestClaimPolicy::MAX_LIFETIME ) );
		self::assertSame( 300, $this->policy->lifetime( GuestClaimPolicy::MIN_LIFETIME ) );
	}

	public function test_lifetime_above_24_hours_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->policy->lifetime( GuestClaimPolicy::MAX_LIFETIME + 1 );
	}

	public function test_issue_and_verification_have_separate_rate_limits(): void {
		$issue  = $this->policy->rate_limits( 'issue' );
		$verify = $this->policy->rate_limits( 'verify' );
		self::assertSame( 5, $issue['user']['capacity'] );
		self::assertSame( 10, $verify['user']['capacity'] );
		self::assertArrayHasKey( 'network', $issue );
		self::assertArrayHasKey( 'order', $verify );
	}

	public function test_all_public_failures_have_the_same_shape(): void {
		$expected = $this->policy->public_failure();
		foreach ( array( 'expired', 'replay', 'conflict' ) as $failure ) {
			self::assertSame( $expected, $this->policy->public_failure(), $failure );
		}
	}

	public function test_ownership_change_invalidates_pending_claim(): void {
		self::assertTrue( $this->policy->ownership_changed( str_repeat( 'a', 32 ), str_repeat( 'b', 32 ) ) );
		self::assertFalse( $this->policy->ownership_changed( str_repeat( 'a', 32 ), str_repeat( 'a', 32 ) ) );
	}

	public function test_admin_release_and_override_require_capability_and_valid_target(): void {
		self::assertFalse( $this->policy->admin_action_allowed( false, 'release', 0 ) );
		self::assertTrue( $this->policy->admin_action_allowed( true, 'release', 0 ) );
		self::assertFalse( $this->policy->admin_action_allowed( true, 'override', 0 ) );
		self::assertTrue( $this->policy->admin_action_allowed( true, 'override', 41 ) );
	}

	public function test_failure_event_types_are_versioned_catalog_names(): void {
		self::assertSame( 'guest_claim_expired_failed', $this->policy->failure_event( 'expired' ) );
		self::assertSame( 'guest_claim_replay_failed', $this->policy->failure_event( 'replay' ) );
		self::assertSame( 'guest_claim_conflict_failed', $this->policy->failure_event( 'conflict' ) );
	}

	/** @return array{status:string,target_user_id:int,expires_at:string,token_hash:string,ownership_hash:string} */
	private function claim( string $status, int $user_id, string $token_hash, string $ownership_hash, string $expires_at ): array {
		return array(
			'status'          => $status,
			'target_user_id'  => $user_id,
			'expires_at'      => $expires_at,
			'token_hash'      => $token_hash,
			'ownership_hash'  => $ownership_hash,
		);
	}
}
