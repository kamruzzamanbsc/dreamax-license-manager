<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class LiveGuestClaimSourceContractTest extends TestCase {
	private string $verifier;
	private string $worker;
	private string $endpoint;

	protected function setUp(): void {
		$root           = dirname( __DIR__, 2 );
		$this->verifier = (string) file_get_contents( $root . '/scripts/verify-live-guest-claim.php' );
		$this->worker   = (string) file_get_contents( $root . '/scripts/lib/guest-claim-worker.php' );
		$this->endpoint = (string) file_get_contents( $root . '/src/CustomerPortal/class-accountendpoint.php' );
	}

	public function test_live_verifier_is_disposable_guarded_and_uses_only_loopback_mail(): void {
		self::assertStringContainsString( 'DisposableEnvironmentGuard::assertSafe', $this->verifier );
		self::assertStringContainsString( '127.0.0.1:8025', $this->verifier );
		self::assertStringContainsString( "mailer->Host        = '127.0.0.1'", $this->verifier );
		self::assertStringContainsString( "'outbound_email_external'            => false", $this->verifier );
	}

	public function test_proof_moves_only_through_private_memory_and_child_stdin(): void {
		self::assertStringContainsString( "file_get_contents( 'php://stdin' )", $this->worker );
		self::assertStringContainsString( "fwrite( \$pipes[0], \$encoded )", $this->verifier );
		self::assertStringContainsString( 'sodium_memzero( $proof )', $this->worker );
		self::assertStringNotContainsString( "'--proof='", $this->verifier );
	}

	public function test_live_matrix_covers_single_use_fail_closed_and_administrator_paths(): void {
		self::assertStringContainsString( 'dreamax_lm_f25_race', $this->verifier );
		self::assertStringContainsString( 'single_use_consumption', $this->verifier );
		self::assertStringContainsString( 'expiry_failed_closed', $this->verifier );
		self::assertStringContainsString( 'ownership_change_invalidated_proof', $this->verifier );
		self::assertStringContainsString( 'admin_release_and_override', $this->verifier );
		self::assertStringContainsString( 'audit_payloads_sanitized', $this->verifier );
	}

	public function test_human_actions_remain_authenticated_nonce_protected_posts(): void {
		self::assertStringContainsString( "admin_post_dreamax_lm_guest_claim_issue", $this->endpoint );
		self::assertStringContainsString( "admin_post_dreamax_lm_guest_claim_verify", $this->endpoint );
		self::assertStringContainsString( "check_admin_referer( 'dreamax_lm_guest_claim_issue' )", $this->endpoint );
		self::assertStringContainsString( "check_admin_referer( 'dreamax_lm_guest_claim_verify' )", $this->endpoint );
		self::assertStringContainsString( 'get_current_user_id()', $this->endpoint );
		self::assertStringNotContainsString( "name=\"claim_code\" value=", $this->endpoint );
	}

	public function test_cleanup_is_exact_and_produces_only_sanitized_output(): void {
		self::assertStringContainsString( "Filesystem::removeTree( \$temp_dir, sys_get_temp_dir() )", $this->verifier );
		self::assertStringContainsString( 'database_aggregates_unchanged', $this->verifier );
		self::assertStringContainsString( 'owned_fixture_rows_remaining', $this->verifier );
		self::assertStringContainsString( "'sensitive_output'                   => false", $this->verifier );
	}
}
