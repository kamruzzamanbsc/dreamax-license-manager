<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CommercialExpiryExtensionSourceContractTest extends TestCase {
	private string $root;

	protected function setUp(): void {
		$this->root = dirname( __DIR__, 2 );
	}

	public function test_authority_uses_only_the_free_owned_lifecycle_boundary(): void {
		$source = (string) file_get_contents( $this->root . '/src/Contracts/Commercial/V1/class-licenseexpiryextensionauthority.php' );
		self::assertStringContainsString( 'extend_from_commercial_contract(', $source );
		self::assertStringContainsString( 'Health::storage_ready()', $source );
		self::assertStringContainsString( "( new Crypto() )->ready()", $source );
		self::assertStringNotContainsString( 'global $wpdb', $source );
		self::assertStringNotContainsString( 'dreamax_lm_', $source );
	}

	public function test_lifecycle_command_is_locked_product_bound_and_conflict_aware(): void {
		$source = (string) file_get_contents( $this->root . '/src/Licenses/class-lifecycleservice.php' );
		self::assertStringContainsString( 'extend_from_commercial_contract(', $source );
		self::assertStringContainsString( '$this->lock( $public_id )', $source );
		self::assertStringContainsString( "'product_mismatch'", $source );
		self::assertStringContainsString( 'hash_equals(', $source );
		self::assertStringContainsString( "'idempotency_conflict'", $source );
		self::assertStringContainsString( '$effective_timestamp', $source );
		self::assertStringContainsString( 'commercial_extension_operations', $source );
		self::assertStringContainsString( 'array_slice( $records, -50, null, true )', $source );
		self::assertStringContainsString( 'LICENSE_EXPIRY_EXTENSION_APPLIED', $source );
	}

	public function test_command_surface_does_not_expose_broad_license_mutations(): void {
		$interface = (string) file_get_contents( $this->root . '/src/Contracts/Commercial/V1/class-licenseexpiryextensionauthorityinterface.php' );
		self::assertSame( 1, substr_count( $interface, 'public function ' ) );
		self::assertStringContainsString( 'public function extend(', $interface );
		foreach ( array( 'create(', 'import(', 'reveal(', 'reassign(', 'suspend(', 'revoke(', 'delete(' ) as $forbidden ) {
			self::assertStringNotContainsString( $forbidden, $interface );
		}
	}
}
