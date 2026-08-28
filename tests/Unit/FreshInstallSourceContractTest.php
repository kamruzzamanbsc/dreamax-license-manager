<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class FreshInstallSourceContractTest extends TestCase {
	private string $verifier;
	private string $installer;

	protected function setUp(): void {
		$root            = dirname( __DIR__, 2 );
		$this->verifier  = (string) file_get_contents( $root . '/scripts/verify-live-fresh-install.php' );
		$this->installer = (string) file_get_contents( $root . '/src/Database/class-installer.php' );
	}

	public function test_verifier_requires_disposable_isolation_and_exact_cleanup(): void {
		self::assertStringContainsString( 'DisposableEnvironmentGuard::assertSafe(', $this->verifier );
		self::assertStringContainsString( "DREAMAX_LM_F01_PREFIX          = 'dlm_f01_disposable_'", $this->verifier );
		self::assertStringContainsString( "const DREAMAX_LM_F01_RECOVERY_PREFIX = 'dlm_f01_recovery_'", $this->verifier );
		self::assertStringContainsString( "'/^dlm_f01_disposable_[A-Za-z0-9_]+$/D'", $this->verifier );
		self::assertStringContainsString( "'/^dlm_f01_recovery_[A-Za-z0-9_]+$/D'", $this->verifier );
		self::assertStringContainsString( "'diagnose-only'", $this->verifier );
		self::assertStringContainsString( "isset( \$options['diagnose-only'] )", $this->verifier );
		self::assertStringContainsString( "'cleanup-only'", $this->verifier );
		self::assertStringContainsString( "isset( \$options['cleanup-only'] )", $this->verifier );
		self::assertStringContainsString( "isset( \$options['discard-recovery'] )", $this->verifier );
		self::assertStringContainsString( 'if ( $prefix_switched && $plugin_activated )', $this->verifier );
		self::assertStringContainsString( 'aggregates_unchanged', $this->verifier );
		self::assertStringContainsString( 'dreamax_lm_f01_backup_owned_tables()', $this->verifier );
		self::assertStringContainsString( 'dreamax_lm_f01_drop_recovery_tables()', $this->verifier );
		self::assertStringContainsString( 'sensitive_output', $this->verifier );
	}

	public function test_verifier_covers_fresh_activation_contract(): void {
		self::assertStringContainsString( 'make_db_current_silent()', $this->verifier );
		self::assertStringContainsString( 'populate_options(', $this->verifier );
		self::assertStringContainsString( 'populate_roles()', $this->verifier );
		self::assertStringContainsString( '$wp_roles = null;', $this->verifier );
		self::assertStringContainsString( 'WC_Install::create_roles()', $this->verifier );
		self::assertStringContainsString( 'Installer::activate()', $this->verifier );
		self::assertStringContainsString( "'InnoDB' === \$table_status['Engine']", $this->verifier );
		self::assertStringContainsString( "get_option( 'dreamax_lm_schema_version', '0' )", $this->verifier );
		self::assertStringContainsString( 'Capabilities::all()', $this->verifier );
		self::assertStringContainsString( 'dreamax_lm_f01_cleanup_schedule_count()', $this->verifier );
	}

	public function test_production_activation_retains_schema_roles_endpoint_and_schedule(): void {
		self::assertStringContainsString( 'self::maybe_upgrade();', $this->installer );
		self::assertStringContainsString( 'Capabilities::grant_defaults();', $this->installer );
		self::assertStringContainsString( "add_rewrite_endpoint( 'licenses'", $this->installer );
		self::assertStringContainsString( "wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'dreamax_lm_cleanup' )", $this->installer );
	}
}
