<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class GuestClaimSourceContractTest extends TestCase {
	private string $schema;
	private string $service;
	private string $privacy;

	protected function setUp(): void {
		$root          = dirname( __DIR__, 2 );
		$this->schema  = (string) file_get_contents( $root . '/src/Database/class-schema.php' );
		$this->service = (string) file_get_contents( $root . '/src/CustomerPortal/class-guestclaimservice.php' );
		$this->privacy = (string) file_get_contents( $root . '/src/Privacy/class-privacy.php' );
	}

	public function test_plaintext_token_has_no_repository_column_and_only_keyed_hash_is_stored(): void {
		self::assertStringContainsString( 'token_hash binary(32) NULL', $this->schema );
		self::assertStringNotContainsString( 'token_plaintext', $this->schema );
		self::assertStringContainsString( "keyed_hash( \$token, 'guest-claim-token' )", $this->service );
		self::assertStringContainsString( "token_hash=NULL", $this->service );
	}

	public function test_issuance_binds_authoritative_billing_email_to_authenticated_account(): void {
		self::assertStringContainsString( 'get_userdata( $user_id )', $this->service );
		self::assertStringContainsString( '$account->user_email', $this->service );
		self::assertStringContainsString( 'hash_equals( $authoritative_email, $account_email )', $this->service );
	}

	public function test_single_use_and_concurrent_claim_guards_are_database_backed(): void {
		self::assertStringContainsString( 'UNIQUE KEY active_order (active_order_id)', $this->schema );
		self::assertStringContainsString( 'PRIMARY KEY  (order_id)', $this->schema );
		self::assertStringContainsString( 'FOR UPDATE', $this->service );
		self::assertStringContainsString( "WHERE id=%d AND status='issued'", $this->service );
	}

	public function test_hpos_compatible_order_access_uses_woocommerce_crud(): void {
		self::assertStringContainsString( 'wc_get_order( $order_id )', $this->service );
		self::assertStringContainsString( '$order->set_customer_id( $customer_id )', $this->service );
		self::assertStringContainsString( '$order->save()', $this->service );
		self::assertStringNotContainsString( 'wp_posts', $this->service );
	}

	public function test_privacy_erasure_removes_claim_identity_and_proof_material(): void {
		self::assertStringContainsString( 'target_user_id=NULL', $this->privacy );
		self::assertStringContainsString( 'token_hash=NULL', $this->privacy );
		self::assertStringContainsString( 'dreamax_lm_order_owners', $this->privacy );
	}

	public function test_confirmed_permanent_uninstall_includes_claim_tables(): void {
		$uninstall = (string) file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' );
		self::assertStringContainsString( "'order_owners', 'guest_claims'", $uninstall );
	}

	public function test_required_claim_audit_events_are_present_without_token_metadata(): void {
		foreach ( array( 'guest_claim_issued', 'guest_claim_succeeded', 'guest_claim_replay_failed', 'guest_claim_expired_failed', 'guest_claim_conflict_failed', 'guest_claim_released', 'guest_claim_administrator_override' ) as $event ) {
			self::assertStringContainsString( $event, $this->service . file_get_contents( dirname( __DIR__, 2 ) . '/src/CustomerPortal/class-guestclaimpolicy.php' ) );
		}
		self::assertStringNotContainsString( "array( 'token'", $this->service );
		self::assertStringNotContainsString( "array( 'license_key'", $this->service );
	}
}
