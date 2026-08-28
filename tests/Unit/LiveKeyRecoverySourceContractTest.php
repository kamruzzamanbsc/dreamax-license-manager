<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class LiveKeyRecoverySourceContractTest extends TestCase {
	private string $verifier;
	private string $public_routes;

	protected function setUp(): void {
		$root                = dirname( __DIR__, 2 );
		$this->verifier      = (string) file_get_contents( $root . '/scripts/verify-live-key-recovery.php' );
		$this->public_routes = (string) file_get_contents( $root . '/src/Api/class-publicroutes.php' );
	}

	public function test_verifier_uses_a_guarded_private_clone_and_exact_cleanup(): void {
		self::assertStringContainsString( 'DisposableEnvironmentGuard::assertSafe(', $this->verifier );
		self::assertStringContainsString( 'DREAMAX_LM_F11_DISPOSABLE_CLONE_CONFIRMED', $this->verifier );
		self::assertStringContainsString( 'dreamax-lm-f11-', $this->verifier );
		self::assertStringContainsString( 'dirname( $target_real ) !== $temp_root', $this->verifier );
		self::assertStringContainsString( 'original_config_unchanged', $this->verifier );
		self::assertStringContainsString( 'clone_config_restored', $this->verifier );
		self::assertStringContainsString( 'clone_cleanup_committed', $this->verifier );
	}

	public function test_verifier_exercises_missing_wrong_and_restored_key_modes(): void {
		self::assertStringContainsString( "array( 'missing', 'wrong' )", $this->verifier );
		self::assertStringContainsString( "dreamax_lm_f11_run_worker( 'correct_before'", $this->verifier );
		self::assertStringContainsString( "dreamax_lm_f11_run_worker( 'correct_after'", $this->verifier );
		self::assertStringContainsString( 'temporarily omits the master-key constant', $this->verifier );
		self::assertStringContainsString( 'str_repeat( chr( 165 ), 32 )', $this->verifier );
		self::assertStringContainsString( 'key-recovery-wrong-prepend.php', $this->verifier );
		self::assertStringContainsString( "isset( \$options['diagnose-wrong'] )", $this->verifier );
		self::assertStringContainsString( '[ \\t]*\\r?$', $this->verifier );
		self::assertStringContainsString( 'correct_key_restored_ready', $this->verifier );
	}

	public function test_verifier_checks_recovery_guards_and_database_non_mutation(): void {
		self::assertStringContainsString( 'dreamax_lm_f11_database_digest()', $this->verifier );
		self::assertStringContainsString( 'create_blocked', $this->verifier );
		self::assertStringContainsString( 'import_blocked', $this->verifier );
		self::assertStringContainsString( 'assign_blocked', $this->verifier );
		self::assertStringContainsString( 'reveal_blocked', $this->verifier );
		self::assertStringContainsString( 'public_503', $this->verifier );
		self::assertStringContainsString( 'database_unchanged', $this->verifier );
		self::assertStringContainsString( 'sensitive_output', $this->verifier );
	}

	public function test_public_routes_fail_closed_before_key_dependent_work(): void {
		self::assertStringContainsString( 'private Crypto $crypto;', $this->public_routes );
		self::assertSame( 2, substr_count( $this->public_routes, '$this->assert_ready();' ) );
		self::assertStringContainsString( "throw new LicenseException( 'server_unavailable', 'The licensing service is temporarily unavailable.', 503 );", $this->public_routes );
		self::assertLessThan(
			strpos( $this->public_routes, '$this->transport->assert_public_request();' ),
			strpos( $this->public_routes, '$this->assert_ready();' )
		);
	}
}
