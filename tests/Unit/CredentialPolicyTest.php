<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use Dreamax\LicenseManager\Credentials\CredentialPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CredentialPolicyTest extends TestCase {
	private CredentialPolicy $policy;

	protected function setUp(): void {
		$this->policy = new CredentialPolicy();
	}

	public function test_scope_catalog_is_exact_and_normalized(): void {
		$expected = array( 'licenses:read', 'licenses:write', 'activations:read', 'generators:read' );
		self::assertSame( $expected, $this->policy->allowed_scopes() );
		self::assertSame( $expected, $this->policy->scopes( array_reverse( array_merge( $expected, array( 'licenses:read' ) ) ) ) );
	}

	public function test_unknown_scope_is_rejected_instead_of_silently_granted_or_dropped(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->policy->scopes( array( 'licenses:read', 'administrator' ) );
	}

	public function test_unknown_wrong_expired_and_revoked_credentials_share_one_public_failure(): void {
		$now     = strtotime( '2030-01-01 00:00:00 UTC' );
		$active  = array( 'status' => 'active', 'expires_at' => '2030-01-01 00:05:00' );
		$expired = array( 'status' => 'active', 'expires_at' => '2030-01-01 00:00:00' );
		$revoked = array( 'status' => 'revoked', 'expires_at' => null );

		self::assertSame( 'unknown', $this->policy->authentication_failure( null, false, $now ) );
		self::assertSame( 'unknown', $this->policy->authentication_failure( $active, false, $now ) );
		self::assertSame( 'expired', $this->policy->authentication_failure( $expired, true, $now ) );
		self::assertSame( 'revoked', $this->policy->authentication_failure( $revoked, true, $now ) );
		self::assertNull( $this->policy->authentication_failure( $active, true, $now ) );
		foreach ( array( 'unknown', 'expired', 'revoked' ) as $reason ) {
			self::assertSame( array( 'code' => 'authentication_required', 'message' => 'Authentication is required.', 'status' => 401 ), $this->policy->public_authentication_failure(), $reason );
		}
	}

	public function test_expiration_boundary_fails_closed_and_future_values_normalize(): void {
		$now = strtotime( '2030-01-01 00:00:00 UTC' );
		self::assertTrue( $this->policy->expired( '2030-01-01 00:00:00', $now ) );
		self::assertFalse( $this->policy->expired( '2030-01-01 00:00:01', $now ) );
		self::assertSame( '2030-01-01 00:00:01', $this->policy->expiration( '2030-01-01 00:00:01', $now ) );
	}

	public function test_nonfuture_expiration_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->policy->expiration( '2030-01-01 00:00:00', strtotime( '2030-01-01 00:00:00 UTC' ) );
	}

	public function test_version_check_gives_zero_overlap_and_rejects_double_rotation(): void {
		self::assertTrue( $this->policy->rotation_allowed( 'active', 1, 1 ) );
		self::assertFalse( $this->policy->rotation_allowed( 'active', 2, 1 ) );
		self::assertFalse( $this->policy->rotation_allowed( 'revoked', 1, 1 ) );
		self::assertFalse( $this->policy->usable( array( 'status' => 'active', 'secret_version' => 2, 'expires_at' => null ), 1, time() ) );
		self::assertTrue( $this->policy->usable( array( 'status' => 'active', 'secret_version' => 2, 'expires_at' => null ), 2, time() ) );
	}

	public function test_repeated_revocation_has_no_second_business_effect(): void {
		self::assertTrue( $this->policy->revocation_changes( 'active' ) );
		self::assertFalse( $this->policy->revocation_changes( 'revoked' ) );
	}

	public function test_last_used_writes_are_coalesced_at_five_minute_boundary(): void {
		$now = strtotime( '2030-01-01 00:10:00 UTC' );
		self::assertFalse( $this->policy->last_used_due( '2030-01-01 00:05:01', $now ) );
		self::assertTrue( $this->policy->last_used_due( '2030-01-01 00:05:00', $now ) );
		self::assertTrue( $this->policy->last_used_due( null, $now ) );
	}

	public function test_per_credential_rate_policy_is_bounded(): void {
		self::assertSame( 120, CredentialPolicy::RATE_CAPACITY );
		self::assertSame( 2.0, CredentialPolicy::RATE_REFILL );
	}
}
