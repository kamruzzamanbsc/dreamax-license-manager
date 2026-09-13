<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SettingsSourceContractTest extends TestCase {
	public function test_configurable_delivery_statuses_replace_hard_coded_status_hooks(): void {
		$order    = $this->read( 'src/Integrations/WooCommerce/class-orderlicensing.php' );
		$settings = $this->read( 'src/Support/class-settings.php' );

		self::assertStringContainsString( "woocommerce_order_status_changed', array( \$this, 'status_changed' ), 10, 4", $order );
		self::assertStringNotContainsString( 'woocommerce_order_status_processing', $order );
		self::assertStringNotContainsString( 'woocommerce_order_status_completed', $order );
		self::assertStringContainsString( 'Settings::allocation_statuses()', $order );
		self::assertStringContainsString( "array( 'processing', 'completed' )", $settings );
	}

	public function test_settings_screen_is_capability_and_nonce_protected(): void {
		$plugin = $this->read( 'src/class-plugin.php' );
		$admin  = $this->read( 'src/Admin/class-settingsadmin.php' );

		self::assertStringContainsString( '( new SettingsAdmin() )->register();', $plugin );
		self::assertStringContainsString( 'dreamax-license-manager-settings', $admin );
		self::assertStringContainsString( "check_admin_referer( 'dreamax_lm_save_settings' )", $admin );
		self::assertStringContainsString( 'Capabilities::MANAGE', $admin );
		self::assertStringContainsString( 'customer_activation_management', $admin );
	}

	private function read( string $relative_path ): string {
		$contents = file_get_contents( dirname( __DIR__, 2 ) . '/' . $relative_path );
		self::assertIsString( $contents );
		return $contents;
	}
}
