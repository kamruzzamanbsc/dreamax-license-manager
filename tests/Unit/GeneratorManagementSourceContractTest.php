<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class GeneratorManagementSourceContractTest extends TestCase {
	public function test_generator_inventory_and_editor_are_registered_and_nonce_protected(): void {
		$plugin = $this->read( 'src/class-plugin.php' );
		$admin  = $this->read( 'src/Admin/class-generatoradmin.php' );

		self::assertStringContainsString( '( new GeneratorAdmin() )->register();', $plugin );
		self::assertStringContainsString( 'dreamax-license-manager-generators', $admin );
		self::assertStringContainsString( 'admin_post_dreamax_lm_save_generator', $admin );
		self::assertStringContainsString( "check_admin_referer( 'dreamax_lm_save_generator' )", $admin );
		self::assertStringContainsString( 'GeneratorRepository', $admin );
		self::assertStringContainsString( 'Random positions must provide at least 96 bits of entropy.', $admin );
	}

	public function test_product_and_variation_settings_expose_explicit_generator_precedence(): void {
		$settings = $this->read( 'src/Integrations/WooCommerce/class-productsettings.php' );
		$policy   = $this->read( 'src/Integrations/WooCommerce/class-productpolicy.php' );

		foreach ( array( '_dreamax_lm_generator_public_id', '_dreamax_lm_activation_mode', '_dreamax_lm_validity_mode', '_dreamax_lm_fixed_expiry' ) as $field ) {
			self::assertStringContainsString( $field, $settings );
		}
		self::assertStringContainsString( "'generator' => __( 'Use generator default'", $settings );
		self::assertStringContainsString( "'unlimited' => __( 'Unlimited activations'", $settings );
		self::assertStringContainsString( "'disabled'  => __( 'Activation disabled'", $settings );
		self::assertStringContainsString( 'private function activation_limit(', $policy );
		self::assertStringContainsString( 'private function validity(', $policy );
		self::assertStringContainsString( 'checkdate(', $policy );
	}

	public function test_order_allocation_snapshots_generator_and_validity_policy(): void {
		$orders = $this->read( 'src/Integrations/WooCommerce/class-orderlicensing.php' );

		self::assertStringContainsString( "'generator_id'      => \$policy['generator_id']", $orders );
		self::assertStringContainsString( "'valid_for_seconds' => \$policy['valid_for_seconds']", $orders );
		self::assertStringContainsString( "\$attributes['generator'] = \$policy['generator']", $orders );
		self::assertStringContainsString( "\$policy['fixed_expires_at']", $orders );
	}

	private function read( string $relative_path ): string {
		$contents = file_get_contents( dirname( __DIR__, 2 ) . '/' . $relative_path );
		self::assertIsString( $contents );
		return $contents;
	}
}
