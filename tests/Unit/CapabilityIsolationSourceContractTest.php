<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use Dreamax\LicenseManager\Support\Capabilities;
use PHPUnit\Framework\TestCase;

final class CapabilityIsolationSourceContractTest extends TestCase {
	private string $admin;
	private string $csv;
	private string $health;
	private string $orderLicensing;
	private string $orderTools;

	protected function setUp(): void {
		$root                 = dirname( __DIR__, 2 );
		$this->admin          = (string) file_get_contents( $root . '/src/Admin/class-admin.php' );
		$this->csv            = (string) file_get_contents( $root . '/src/ImportExport/class-csvcontroller.php' );
		$this->health         = (string) file_get_contents( $root . '/src/Support/class-health.php' );
		$this->orderLicensing = (string) file_get_contents( $root . '/src/Integrations/WooCommerce/class-orderlicensing.php' );
		$this->orderTools     = (string) file_get_contents( $root . '/src/Integrations/WooCommerce/class-orderworkflowadmin.php' );
	}

	public function test_the_seven_narrow_capabilities_remain_distinct(): void {
		self::assertSame(
			array(
				'dreamax_lm_manage_licenses',
				'dreamax_lm_reveal_license_keys',
				'dreamax_lm_export_license_keys',
				'dreamax_lm_manage_api_credentials',
				'dreamax_lm_delete_license_records',
				'dreamax_lm_manage_security',
				'dreamax_lm_view_diagnostics',
			),
			Capabilities::all()
		);
	}

	public function test_admin_menus_use_the_narrow_capabilities(): void {
		self::assertGreaterThanOrEqual( 5, substr_count( $this->admin, 'Capabilities::MANAGE' ) );
		self::assertStringContainsString( "Capabilities::CREDENTIALS, 'dreamax-license-manager-credentials'", $this->admin );
		self::assertStringContainsString( "Capabilities::DIAGNOSTICS, 'dreamax-license-manager-status'", $this->admin );
		self::assertStringContainsString( 'Capabilities::MANAGE', $this->orderTools );
	}

	public function test_admin_mutations_authorize_before_nonce_or_input_handling(): void {
		$this->assertGuardBeforeNonce( $this->admin, 'create_license', 'bulk_lifecycle', 'Capabilities::MANAGE' );
		$this->assertGuardBeforeNonce( $this->admin, 'bulk_lifecycle', 'reassign_license', 'Capabilities::MANAGE' );
		$this->assertGuardBeforeNonce( $this->admin, 'reassign_license', 'master_key', 'Capabilities::MANAGE' );
		$this->assertGuardBeforeNonce( $this->admin, 'master_key', 'create_credential', 'Capabilities::SECURITY' );
		$this->assertGuardBeforeNonce( $this->admin, 'create_credential', 'rotate_credential', 'Capabilities::CREDENTIALS' );
		$this->assertGuardBeforeNonce( $this->admin, 'rotate_credential', 'revoke_credential', 'Capabilities::CREDENTIALS' );
		$this->assertGuardBeforeNonce( $this->admin, 'revoke_credential', 'authorize', 'Capabilities::CREDENTIALS' );
	}

	public function test_transfer_order_and_sensitive_ui_paths_keep_separate_guards(): void {
		$this->assertGuardBeforeNonce( $this->csv, 'import', 'export', 'Capabilities::MANAGE' );
		$this->assertGuardBeforeNonce( $this->csv, 'export', 'authorize', 'Capabilities::MANAGE' );
		self::assertStringContainsString( 'current_user_can( Capabilities::EXPORT )', $this->csv );
		self::assertStringContainsString( 'current_user_can( Capabilities::DELETE )', $this->admin );
		self::assertStringContainsString( 'current_user_can( Capabilities::SECURITY )', $this->admin );
		self::assertStringContainsString( 'current_user_can( Capabilities::SECURITY )', $this->health );
		self::assertStringContainsString( 'current_user_can( Capabilities::MANAGE )', $this->orderTools );
		self::assertGreaterThanOrEqual( 2, substr_count( $this->orderLicensing, 'current_user_can( Capabilities::MANAGE )' ) );
	}

	private function assertGuardBeforeNonce( string $source, string $start, string $next, string $capability ): void {
		$method   = $this->method( $source, $start, $next );
		$guard    = strpos( $method, 'authorize( ' . $capability . ' )' );
		$nonce    = strpos( $method, 'check_admin_referer(' );
		$input    = strpos( $method, '$_POST' );
		$boundary = false !== $nonce ? $nonce : $input;

		self::assertNotFalse( $guard );
		self::assertNotFalse( $boundary );
		self::assertLessThan( $boundary, $guard );
	}

	private function method( string $source, string $start, string $next ): string {
		$start_position = strpos( $source, 'function ' . $start . '(' );
		$next_position  = strpos( $source, 'function ' . $next . '(', false === $start_position ? 0 : $start_position );

		self::assertNotFalse( $start_position );
		self::assertNotFalse( $next_position );
		return substr( $source, (int) $start_position, (int) $next_position - (int) $start_position );
	}
}
