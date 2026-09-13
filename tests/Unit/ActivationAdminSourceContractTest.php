<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ActivationAdminSourceContractTest extends TestCase {
	public function test_global_activation_inventory_is_registered_and_privacy_conscious(): void {
		$plugin     = $this->read( 'src/class-plugin.php' );
		$admin      = $this->read( 'src/Admin/class-activationadmin.php' );
		$repository = $this->read( 'src/Activations/class-activationrepository.php' );

		self::assertStringContainsString( '( new ActivationAdmin() )->register();', $plugin );
		self::assertStringContainsString( 'dreamax-license-manager-activations', $admin );
		self::assertStringContainsString( 'Capabilities::MANAGE', $admin );
		self::assertStringContainsString( 'License public ID', $admin );
		self::assertStringContainsString( 'instance_label', $repository );
		self::assertStringNotContainsString( 'instance_fingerprint', $repository );
		self::assertStringNotContainsString( 'key_ciphertext', $repository );
	}

	private function read( string $relative_path ): string {
		$contents = file_get_contents( dirname( __DIR__, 2 ) . '/' . $relative_path );
		self::assertIsString( $contents );
		return $contents;
	}
}
