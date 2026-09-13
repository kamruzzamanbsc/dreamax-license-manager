<?php
/**
 * Verifies protected, secret-free operational diagnostics.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DiagnosticsSourceContractTest extends TestCase {
	private string $diagnostics;
	private string $admin;
	private string $plugin;

	protected function setUp(): void {
		$root              = dirname( __DIR__, 2 );
		$this->diagnostics = (string) file_get_contents( $root . '/src/Support/class-diagnostics.php' );
		$this->admin       = (string) file_get_contents( $root . '/src/Admin/class-diagnosticsadmin.php' );
		$this->plugin      = (string) file_get_contents( $root . '/src/class-plugin.php' );
	}

	public function test_diagnostics_cover_free_release_readiness(): void {
		foreach ( array( 'environment', 'schema', 'storage_engine', 'encryption', 'delivery', 'generator', 'test_license', 'customer_portal', 'api', 'background', 'proxy_rate_limit', 'backup' ) as $check ) {
			self::assertStringContainsString( "'{$check}'", $this->diagnostics );
		}
		self::assertStringContainsString( 'rest_do_request', $this->diagnostics );
		self::assertStringContainsString( "'no-store'", $this->diagnostics );
		self::assertStringContainsString( "'private'", $this->diagnostics );
		self::assertStringContainsString( 'information_schema.TABLES', $this->diagnostics );
	}

	public function test_report_and_email_actions_are_protected(): void {
		self::assertStringContainsString( '( new DiagnosticsAdmin() )->register();', $this->plugin );
		self::assertStringContainsString( "check_admin_referer( 'dreamax_lm_download_system_report' )", $this->admin );
		self::assertStringContainsString( "check_admin_referer( 'dreamax_lm_send_test_email' )", $this->admin );
		self::assertStringContainsString( 'current_user_can( Capabilities::DIAGNOSTICS )', $this->admin );
		self::assertStringContainsString( 'Cache-Control: no-store, no-cache, must-revalidate, private', $this->admin );
		self::assertStringContainsString( 'No license key or customer data is included.', $this->admin );
	}

	public function test_report_uses_a_fixed_non_sensitive_shape(): void {
		foreach ( array( 'plugin_version', 'schema_version', 'wordpress_version', 'woocommerce_version', 'php_version', 'database_server_version', 'multisite', 'https', 'wp_cron_disabled', 'generated_at_utc', 'checks' ) as $field ) {
			self::assertStringContainsString( "'{$field}'", $this->diagnostics );
		}
		self::assertStringNotContainsString( 'wp-config.php' . "' =>", $this->diagnostics );
		self::assertStringNotContainsString( "'master_key'", $this->diagnostics );
		self::assertStringNotContainsString( "'server_path'", $this->diagnostics );
		self::assertStringNotContainsString( "'user_email'", $this->diagnostics );
	}
}
