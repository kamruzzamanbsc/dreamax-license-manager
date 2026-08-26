<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CredentialAdminReplaySourceContractTest extends TestCase {
	private string $admin;
	private string $operation;
	private string $repository;

	protected function setUp(): void {
		$root             = dirname( __DIR__, 2 );
		$this->admin      = (string) file_get_contents( $root . '/src/Admin/class-admin.php' );
		$this->operation  = (string) file_get_contents( $root . '/src/Credentials/class-credentialadminoperation.php' );
		$this->repository = (string) file_get_contents( $root . '/src/Credentials/class-wordpresscredentialadminoperationrepository.php' );
	}

	public function test_create_and_rotation_use_single_use_operation_fields_and_gate(): void {
		self::assertStringContainsString( 'credential_operation_id', $this->admin );
		self::assertStringContainsString( 'credential_operation_nonce', $this->admin );
		self::assertStringContainsString( 'CredentialAdminOperation::CREATE', $this->method( 'create_credential', 'rotate_credential' ) );
		self::assertStringContainsString( 'CredentialAdminOperation::ROTATE', $this->method( 'rotate_credential', 'revoke_credential' ) );
		self::assertGreaterThanOrEqual( 2, substr_count( $this->admin, '->execute(' ) );
	}

	public function test_revocation_remains_idempotent_post_redirect_get(): void {
		$revoke = $this->method( 'revoke_credential', 'authorize' );
		self::assertStringContainsString( '->revoke(', $revoke );
		self::assertStringContainsString( 'wp_safe_redirect(', $revoke );
		self::assertStringNotContainsString( 'CredentialAdminOperation::', $revoke );
	}

	public function test_reservation_is_atomic_actor_bound_and_stores_no_operation_result(): void {
		self::assertStringContainsString( 'INSERT IGNORE INTO', $this->repository );
		self::assertStringContainsString( "'|' . \$actor_id . '|' . \$operation_id", $this->repository );
		self::assertStringContainsString( "'credential-admin-operation'", $this->repository );
		self::assertStringContainsString( "result_metadata='{}'", $this->repository );
		self::assertStringNotContainsString( 'result_public_id=', $this->repository );
		self::assertStringNotContainsString( 'EventRepository', $this->repository );
		self::assertStringNotContainsString( 'EventRepository', $this->operation );
		self::assertStringNotContainsString( 'error_log', $this->repository );
		self::assertStringNotContainsString( 'error_log', $this->operation );
	}

	public function test_secret_response_is_no_store_and_failed_completion_is_scrubbed(): void {
		self::assertStringContainsString( 'Cache-Control: no-store, private, max-age=0', $this->admin );
		self::assertStringContainsString( 'Referrer-Policy: no-referrer', $this->admin );
		self::assertStringContainsString( "sodium_memzero( \$result['credential'] )", $this->operation );
		self::assertGreaterThanOrEqual( 2, substr_count( $this->operation, "'processed' => false" ) );
		self::assertGreaterThanOrEqual( 2, substr_count( $this->operation, "'result'    => null" ) );
	}

	private function method( string $start, string $next ): string {
		$start_position = strpos( $this->admin, 'public function ' . $start . '()' );
		$next_position  = strpos( $this->admin, 'private function ' . $next . '(', false === $start_position ? 0 : $start_position );
		if ( false === $next_position ) {
			$next_position = strpos( $this->admin, 'public function ' . $next . '()', false === $start_position ? 0 : $start_position );
		}

		self::assertNotFalse( $start_position );
		self::assertNotFalse( $next_position );
		return substr( $this->admin, (int) $start_position, (int) $next_position - (int) $start_position );
	}
}
