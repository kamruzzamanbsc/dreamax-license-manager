<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class IdempotencyContractSourceTest extends TestCase {
	private string $repository;
	private string $plugin;
	private string $verifier;

	protected function setUp(): void {
		$root             = dirname( __DIR__, 2 );
		$this->repository = (string) file_get_contents( $root . '/src/Api/class-idempotencyrepository.php' );
		$this->plugin     = (string) file_get_contents( $root . '/src/class-plugin.php' );
		$this->verifier   = (string) file_get_contents( $root . '/scripts/verify-live-idempotency-contract.php' );
	}

	public function test_repository_hashes_scope_and_stores_only_bounded_results(): void {
		self::assertStringContainsString( "'idempotency-scope'", $this->repository );
		self::assertStringContainsString( "'idempotency-license'", $this->repository );
		self::assertStringContainsString( 'UNHEX(%s),UNHEX(%s)', $this->repository );
		self::assertStringContainsString( 'bounded_result( $data )', $this->repository );
		self::assertStringContainsString( "array( 'license_public_id', 'product_public_id', 'activation_public_id', 'status', 'expires_at', 'replayed', 'activations' )", $this->repository );
	}

	public function test_repository_enforces_replay_conflict_and_key_bounds(): void {
		self::assertStringContainsString( '/^[\\x21-\\x7E]{8,128}$/D', $this->repository );
		self::assertStringContainsString( "'idempotency_conflict'", $this->repository );
		self::assertStringContainsString( 'hash_equals( bin2hex( $digest )', $this->repository );
		self::assertStringContainsString( "if ( 'completed' === \$row['state'] )", $this->repository );
	}

	public function test_cleanup_is_scheduled_and_bounded(): void {
		self::assertStringContainsString( "add_action( 'dreamax_lm_cleanup', array( \$this, 'cleanup' ) );", $this->plugin );
		self::assertStringContainsString( 'WHERE expires_at < %s LIMIT 500', $this->plugin );
		self::assertStringContainsString( "wp_next_scheduled( 'dreamax_lm_cleanup' )", $this->verifier );
		self::assertStringContainsString( "do_action( 'dreamax_lm_cleanup' )", $this->verifier );
	}

	public function test_live_verifier_checks_exact_cleanup_and_zeroes_synthetic_inputs(): void {
		self::assertStringContainsString( "'exact_result_replay'", $this->verifier );
		self::assertStringContainsString( "'changed_payload_conflict_409'", $this->verifier );
		self::assertStringContainsString( "'bounded_secret_free_storage'", $this->verifier );
		self::assertStringContainsString( "sodium_memzero( \$raw_key )", $this->verifier );
		self::assertStringContainsString( "'sensitive_output'             => false", $this->verifier );
	}
}
