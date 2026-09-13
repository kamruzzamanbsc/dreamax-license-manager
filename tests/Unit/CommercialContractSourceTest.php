<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use Dreamax\LicenseManager\Contracts\Commercial\V1\CommercialContracts;
use PHPUnit\Framework\TestCase;

final class CommercialContractSourceTest extends TestCase {
	private string $root;

	protected function setUp(): void {
		$this->root = dirname( __DIR__, 2 );
	}

	public function test_contract_version_and_capabilities_are_frozen(): void {
		self::assertSame( 1, CommercialContracts::VERSION );
		$source = (string) file_get_contents( $this->root . '/src/Contracts/Commercial/V1/class-commercialcontracts.php' );
		foreach ( array( 'core_authority.v1', 'entitlement_provider.v1', 'installation_credential_issuer.v1', 'billing_event_adapter.v1', 'release_metadata_provider.v1', 'package_authorization.v1' ) as $capability ) {
			self::assertStringContainsString( "'{$capability}'", $source );
		}
	}

	public function test_authority_adapter_does_not_name_or_query_free_tables(): void {
		$source = (string) file_get_contents( $this->root . '/src/Contracts/Commercial/V1/class-coreauthority.php' );
		self::assertStringNotContainsString( 'global $wpdb', $source );
		self::assertStringNotContainsString( 'dreamax_lm_', $source );
		self::assertStringNotContainsString( 'key_ciphertext', $source );
		self::assertStringNotContainsString( 'instance_fingerprint', $source );
	}

	public function test_bootstrap_has_one_registration_action_and_locks_after_it(): void {
		$source = (string) file_get_contents( $this->root . '/src/Contracts/Commercial/V1/class-commercialcontracts.php' );
		self::assertSame( 1, substr_count( $source, "do_action( 'dreamax_lm_register_commercial_providers_v1'" ) );
		self::assertStringContainsString( 'catch ( Throwable $exception )', $source );
		self::assertStringContainsString( 'new CommercialProviderRegistry( $authority )', $source );
		self::assertStringContainsString( '$registry->lock();', $source );
		self::assertStringContainsString( 'CommercialContracts::bootstrap();', (string) file_get_contents( $this->root . '/src/class-plugin.php' ) );
	}

	public function test_activation_service_owns_exact_installation_proof_query(): void {
		$source = (string) file_get_contents( $this->root . '/src/Activations/class-activationservice.php' );
		self::assertStringContainsString( 'public function verify_installation(', $source );
		self::assertStringContainsString( "instance_fingerprint=UNHEX(%s) AND status='active'", $source );
		self::assertStringContainsString( "'authentication_required'", $source );
	}

	public function test_every_published_contract_type_autoloads(): void {
		$types = array(
			'BillingEventAdapterInterface',
			'BillingEventEnvelope',
			'CanonicalBillingEvent',
			'CommercialProviderRegistry',
			'ContractData',
			'ContractDescriptor',
			'ContractException',
			'CoreAuthority',
			'CoreAuthorityInterface',
			'CoreLicenseSnapshot',
			'CredentialRevocationResult',
			'EntitlementProviderInterface',
			'EntitlementRequest',
			'EntitlementSnapshot',
			'InstallationCredentialIssuerInterface',
			'InstallationCredentialRequest',
			'InstallationCredentialRevocation',
			'InstallationCredentialRotation',
			'InstallationDecision',
			'InstallationProof',
			'IssuedCredential',
			'LicenseDecision',
			'LicenseProof',
			'PackageAuthorizationDecision',
			'PackageAuthorizationInterface',
			'PackageAuthorizationRequest',
			'ReleaseMetadataDecision',
			'ReleaseMetadataProviderInterface',
			'ReleaseMetadataRequest',
		);
		foreach ( $types as $type ) {
			self::assertTrue( class_exists( "Dreamax\\LicenseManager\\Contracts\\Commercial\\V1\\{$type}" ) || interface_exists( "Dreamax\\LicenseManager\\Contracts\\Commercial\\V1\\{$type}" ), $type );
		}
	}
}
