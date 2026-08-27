<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class StableIdentitySourceContractTest extends TestCase {
	private string $activations;
	private string $product_settings;
	private string $schema;
	private string $verifier;

	protected function setUp(): void {
		$root                   = dirname( __DIR__, 2 );
		$this->activations      = (string) file_get_contents( $root . '/src/Activations/class-activationservice.php' );
		$this->product_settings = (string) file_get_contents( $root . '/src/Integrations/WooCommerce/class-productsettings.php' );
		$this->schema           = (string) file_get_contents( $root . '/src/Database/class-schema.php' );
		$this->verifier         = (string) file_get_contents( $root . '/scripts/verify-live-stable-identities.php' );
	}

	public function test_product_edits_preserve_identity_and_duplicates_replace_it(): void {
		self::assertStringContainsString( "if ( \$enabled && ! \$product->get_meta( '_dreamax_lm_product_public_id', true ) )", $this->product_settings );
		self::assertStringContainsString( "\$duplicate->update_meta_data( '_dreamax_lm_product_public_id', PublicId::generate( 'prd' ) );", $this->product_settings );
	}

	public function test_installation_identity_excludes_the_editable_label(): void {
		self::assertStringContainsString( "fingerprint( \$instance_id, 'instance-identity' )", $this->activations );
		self::assertStringContainsString( "'instance_label' => null === \$label ? null : sanitize_text_field( \$label )", $this->activations );
		self::assertStringContainsString( 'UNIQUE KEY license_instance (license_id,instance_fingerprint)', $this->schema );
	}

	public function test_reactivation_reuses_the_existing_public_identity(): void {
		self::assertStringContainsString( "\$public_id     = (string) \$existing['public_id'];", $this->activations );
		self::assertStringContainsString( "return \$this->result( \$license, \$existing, true );", $this->activations );
	}

	public function test_live_verifier_has_rollback_and_owned_cleanup_guards(): void {
		self::assertStringContainsString( "\$wpdb->query( 'ROLLBACK' )", $this->verifier );
		self::assertStringContainsString( "array( 'license_id' => \$fixture_license_id )", $this->verifier );
		self::assertStringContainsString( "'public_id' => \$fixture_public_id", $this->verifier );
		self::assertStringContainsString( "'sensitive_output'", $this->verifier );
	}
}
