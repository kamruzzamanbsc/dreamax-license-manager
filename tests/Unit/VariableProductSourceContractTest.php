<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class VariableProductSourceContractTest extends TestCase {
	private string $allocation;
	private string $policy;
	private string $verifier;

	protected function setUp(): void {
		$root             = dirname( __DIR__, 2 );
		$this->allocation = (string) file_get_contents( $root . '/src/Integrations/WooCommerce/class-orderlicensing.php' );
		$this->policy     = (string) file_get_contents( $root . '/src/Integrations/WooCommerce/class-productpolicy.php' );
		$this->verifier   = (string) file_get_contents( $root . '/scripts/verify-live-variable-product.php' );
	}

	public function test_variations_resolve_explicit_values_before_parent_fallback(): void {
		self::assertStringContainsString( "\$value = (string) \$product->get_meta( \$key, true );", $this->policy );
		self::assertStringContainsString( "return '' !== \$value || ! \$parent ? \$value : (string) \$parent->get_meta( \$key, true );", $this->policy );
	}

	public function test_allocation_records_parent_and_variation_identity(): void {
		self::assertStringContainsString( "'product_public_id' => \$policy['public_id']", $this->allocation );
		self::assertStringContainsString( "'product_id'        => \$product->get_parent_id() ? \$product->get_parent_id() : \$product->get_id()", $this->allocation );
		self::assertStringContainsString( "'variation_id'      => \$product->is_type( 'variation' ) ? \$product->get_id() : null", $this->allocation );
	}

	public function test_live_verifier_covers_distinct_policy_issuance_and_owned_cleanup(): void {
		self::assertStringContainsString( "'per_quantity' !== \$policy_a['issuance']", $this->verifier );
		self::assertStringContainsString( "'per_item' !== \$policy_b['issuance']", $this->verifier );
		self::assertStringContainsString( "hash_equals( \$policy_a['public_id'], \$policy_b['public_id'] )", $this->verifier );
		self::assertStringContainsString( "array( 'license_id' => \$license_id )", $this->verifier );
		self::assertStringContainsString( "'automatic:' . \$order_id", $this->verifier );
		self::assertStringContainsString( "\$wpdb->query( 'ROLLBACK' )", $this->verifier );
		self::assertStringContainsString( "'sensitive_output'", $this->verifier );
	}
}
