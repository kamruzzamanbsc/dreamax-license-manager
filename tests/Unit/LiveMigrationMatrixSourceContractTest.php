<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class LiveMigrationMatrixSourceContractTest extends TestCase {
	private string $verifier;

	protected function setUp(): void {
		$this->verifier = (string) file_get_contents( dirname( __DIR__, 2 ) . '/scripts/verify-live-migration-matrix.php' );
	}

	public function test_verifier_is_disposable_prefix_scoped_and_cleanup_guarded(): void {
		self::assertStringContainsString( 'DisposableEnvironmentGuard::assertSafe(', $this->verifier );
		self::assertStringContainsString( 'DREAMAX_LM_F02_PREFIXES', $this->verifier );
		self::assertStringContainsString( "'/^dlm_f02_disposable_[1-4]_[A-Za-z0-9_]+$/D'", $this->verifier );
		self::assertStringContainsString( "isset( \$options['diagnose-only'] )", $this->verifier );
		self::assertStringContainsString( "isset( \$options['cleanup-only'] )", $this->verifier );
		self::assertStringContainsString( 'aggregates_unchanged', $this->verifier );
		self::assertStringContainsString( 'sensitive_output', $this->verifier );
	}

	public function test_verifier_covers_historical_upgrade_replay_and_fault_recovery(): void {
		self::assertStringContainsString( "dreamax_lm_f02_install_checkpoint( '1' )", $this->verifier );
		self::assertStringContainsString( "dreamax_lm_f02_install_checkpoint( '2' )", $this->verifier );
		self::assertStringContainsString( 'dreamax_lm_f02_preservation_digest', $this->verifier );
		self::assertStringContainsString( 'CREATE VIEW', $this->verifier );
		self::assertStringContainsString( 'RENAME TABLE', $this->verifier );
		self::assertGreaterThanOrEqual( 8, substr_count( $this->verifier, 'Installer::maybe_upgrade();' ) );
		self::assertStringContainsString( 'v1_interruption_checkpoint', $this->verifier );
		self::assertStringContainsString( 'v2_interruption_checkpoint', $this->verifier );
		self::assertStringContainsString( 'v3_interruption_checkpoint', $this->verifier );
	}

	public function test_verifier_covers_current_schema_isolation_and_downgrade_refusal(): void {
		self::assertStringContainsString( 'information_schema.TABLES', $this->verifier );
		self::assertStringContainsString( 'SHOW COLUMNS', $this->verifier );
		self::assertStringContainsString( 'SHOW INDEX', $this->verifier );
		self::assertStringContainsString( 'SHOW CREATE TABLE', $this->verifier );
		self::assertStringContainsString( 'second_prefix_isolation', $this->verifier );
		self::assertStringContainsString( "update_option( 'dreamax_lm_schema_version', '999', false )", $this->verifier );
		self::assertStringContainsString( 'future_version_preserved', $this->verifier );
	}
}
