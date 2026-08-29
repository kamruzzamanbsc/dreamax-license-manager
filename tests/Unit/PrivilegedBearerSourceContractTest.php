<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PrivilegedBearerSourceContractTest extends TestCase {
	private string $verifier;
	private string $worker;

	protected function setUp(): void {
		$root           = dirname( __DIR__, 2 );
		$this->verifier = (string) file_get_contents( $root . '/scripts/verify-live-privileged-bearer.php' );
		$this->worker   = (string) file_get_contents( $root . '/scripts/lib/credential-concurrency-worker.php' );
	}

	public function test_live_verifier_is_disposable_guarded_and_cleanup_scoped(): void {
		self::assertStringContainsString( 'DisposableEnvironmentGuard::assertSafe', $this->verifier );
		self::assertStringContainsString( "'environment-marker:'", $this->verifier );
		self::assertStringContainsString( 'START TRANSACTION', $this->verifier );
		self::assertStringContainsString( "'database_aggregates_unchanged'", $this->verifier );
		self::assertStringContainsString( "'owned_fixture_rows_remaining'", $this->verifier );
	}

	public function test_live_verifier_covers_http_scope_and_transport_contracts(): void {
		self::assertStringContainsString( 'wp_remote_request', $this->verifier );
		self::assertStringContainsString( "'Authorization: Bearer ' . \$credential", $this->verifier );
		self::assertStringContainsString( "'licenses:read'", $this->verifier );
		self::assertStringContainsString( "'licenses:write'", $this->verifier );
		self::assertStringContainsString( "'activations:read'", $this->verifier );
		self::assertStringContainsString( "'generators:read'", $this->verifier );
		self::assertStringContainsString( "'duplicate_raw_header_rejected'", $this->verifier );
		self::assertStringContainsString( "'query_and_body_proof_rejected'", $this->verifier );
		self::assertStringContainsString( "'proxy_boundary_verified'", $this->verifier );
	}

	public function test_live_verifier_covers_lifecycle_load_and_failure_rollback(): void {
		self::assertStringContainsString( "'expiration_boundary_closed'", $this->verifier );
		self::assertStringContainsString( "'per_credential_rate_limited'", $this->verifier );
		self::assertStringContainsString( "'last_used_write_coalesced'", $this->verifier );
		self::assertStringContainsString( 'CREDENTIAL_ROTATION_SUCCEEDED', $this->verifier );
		self::assertStringContainsString( "'audit_failure_rolled_back'", $this->verifier );
	}

	public function test_parallel_worker_receives_private_inputs_only_through_stdin(): void {
		self::assertStringContainsString( 'stream_get_contents( STDIN )', $this->worker );
		self::assertStringContainsString( "'rotate_barrier'", $this->worker );
		self::assertStringContainsString( "'use_hold'", $this->worker );
		self::assertStringContainsString( "'revoke'", $this->worker );
		self::assertStringContainsString( 'sodium_memzero', $this->worker );
		self::assertStringNotContainsString( "'credential='", $this->worker );
	}

	public function test_verifier_output_is_explicitly_sanitized(): void {
		self::assertStringContainsString( "'sensitive_output'", $this->verifier );
		self::assertStringContainsString( "'outbound_email_sent'", $this->verifier );
		self::assertStringNotContainsString( "echo \$credential", $this->verifier );
		self::assertStringNotContainsString( "fwrite( STDERR, \$error", $this->verifier );
	}
}
