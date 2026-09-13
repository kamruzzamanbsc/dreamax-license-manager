<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DisasterRecoverySourceContractTest extends TestCase {
	private string $verifier;

	protected function setUp(): void {
		$this->verifier = (string) file_get_contents( dirname( __DIR__, 2 ) . '/scripts/verify-live-disaster-recovery.php' );
	}

	public function test_verifier_requires_explicit_disposable_guards_before_mutation(): void {
		self::assertStringContainsString( 'DisposableEnvironmentGuard::assertSafe(', $this->verifier );
		self::assertStringContainsString( 'DREAMAX_LM_F12_DISASTER_RECOVERY_CONFIRMED', $this->verifier );
		self::assertStringContainsString( "isset( \$options['diagnose-only'] )", $this->verifier );
		self::assertStringContainsString( 'no_stale_database_residue', $this->verifier );
		self::assertStringContainsString( 'no_stale_clone_residue', $this->verifier );
		self::assertStringContainsString( 'dreamax_lm_f12_assert_owned_database', $this->verifier );
	}

	public function test_verifier_creates_a_backup_then_an_independent_restore(): void {
		self::assertStringContainsString( "'create-fixture'", $this->verifier );
		self::assertStringContainsString( "'source'            => 'recovery_verifier'", $this->verifier );
		self::assertStringContainsString( "'fixture_removed'", $this->verifier );
		self::assertStringContainsString( "'backup_test_'", $this->verifier );
		self::assertStringContainsString( "'restore_test_'", $this->verifier );
		self::assertStringContainsString( 'dreamax_lm_f12_copy_database( $source_database, $backup_database )', $this->verifier );
		self::assertStringContainsString( 'dreamax_lm_f12_copy_database( $backup_database, $restore_database )', $this->verifier );
		self::assertStringContainsString( 'backup_matches_source', $this->verifier );
		self::assertStringContainsString( 'restore_matches_backup', $this->verifier );
		self::assertStringContainsString( "preg_replace( '/\\sAUTO_INCREMENT=\\d+/D'", $this->verifier );
	}

	public function test_verifier_exercises_database_only_and_complete_key_restore_modes(): void {
		self::assertStringContainsString( "dreamax_lm_f12_run_worker( 'database_only'", $this->verifier );
		self::assertStringContainsString( "dreamax_lm_f12_run_worker( 'complete_restore'", $this->verifier );
		self::assertStringContainsString( 'database-only restore intentionally omits the external key', $this->verifier );
		self::assertStringContainsString( 'recovery_signaled', $this->verifier );
		self::assertStringContainsString( 'prebackup_decrypts', $this->verifier );
		self::assertStringContainsString( 'prebackup_validates', $this->verifier );
	}

	public function test_verifier_proves_exact_preservation_and_cleanup(): void {
		self::assertStringContainsString( 'source_database_unchanged', $this->verifier );
		self::assertStringContainsString( 'dreamax_lm_f12_plugin_digest( $restore_database )', $this->verifier );
		self::assertStringContainsString( 'restore_plugin_state_preserved', $this->verifier );
		self::assertStringContainsString( 'SHA2(CAST(option_value AS CHAR), 256)', $this->verifier );
		self::assertStringContainsString( 'original_config_unchanged', $this->verifier );
		self::assertStringContainsString( 'clone_key_backup_restored', $this->verifier );
		self::assertStringContainsString( 'dreamax_lm_f12_drop_database( $restore_database )', $this->verifier );
		self::assertStringContainsString( 'dreamax_lm_f12_drop_database( $backup_database )', $this->verifier );
		self::assertStringContainsString( 'dreamax_lm_f12_remove_tree( $clone_root )', $this->verifier );
		self::assertStringContainsString( 'sensitive_output', $this->verifier );
		self::assertStringContainsString( "'worker_stages'", $this->verifier );
	}
}
