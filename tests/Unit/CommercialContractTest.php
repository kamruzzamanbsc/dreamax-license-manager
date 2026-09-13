<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use Dreamax\LicenseManager\Contracts\Commercial\V1\BillingEventAdapterInterface;
use Dreamax\LicenseManager\Contracts\Commercial\V1\BillingEventEnvelope;
use Dreamax\LicenseManager\Contracts\Commercial\V1\CanonicalBillingEvent;
use Dreamax\LicenseManager\Contracts\Commercial\V1\CommercialProviderRegistry;
use Dreamax\LicenseManager\Contracts\Commercial\V1\ContractException;
use Dreamax\LicenseManager\Contracts\Commercial\V1\CoreAuthorityInterface;
use Dreamax\LicenseManager\Contracts\Commercial\V1\CoreLicenseSnapshot;
use Dreamax\LicenseManager\Contracts\Commercial\V1\EntitlementProviderInterface;
use Dreamax\LicenseManager\Contracts\Commercial\V1\EntitlementRequest;
use Dreamax\LicenseManager\Contracts\Commercial\V1\EntitlementSnapshot;
use Dreamax\LicenseManager\Contracts\Commercial\V1\InstallationDecision;
use Dreamax\LicenseManager\Contracts\Commercial\V1\InstallationProof;
use Dreamax\LicenseManager\Contracts\Commercial\V1\IssuedCredential;
use Dreamax\LicenseManager\Contracts\Commercial\V1\LicenseDecision;
use Dreamax\LicenseManager\Contracts\Commercial\V1\LicenseProof;
use PHPUnit\Framework\TestCase;

final class CommercialContractTest extends TestCase {
	public function test_contract_data_is_allowlisted_immutable_and_recursively_normalized(): void {
		$license = $this->license_snapshot();
		$request = new EntitlementRequest(
			array(
				'core_license'       => $license,
				'product_public_id'  => 'prd_1234567890123456789012',
				'requested_features' => array( 'updates' ),
			)
		);

		self::assertSame( 'lic_1234567890123456789012', $request->to_array()['core_license']['license_public_id'] );
		self::assertNull( $request->get( 'channel' ) );

		$this->expectException( ContractException::class );
		new EntitlementRequest(
			array(
				'core_license'       => $license,
				'product_public_id'  => 'prd_1234567890123456789012',
				'requested_features' => array( 'updates' ),
				'unknown'            => true,
			)
		);
	}

	public function test_contract_data_rejects_nested_secret_fields(): void {
		$this->expectException( ContractException::class );
		new EntitlementRequest(
			array(
				'core_license'       => $this->license_snapshot(),
				'product_public_id'  => 'prd_1234567890123456789012',
				'requested_features' => array( array( 'secret' => 'must-not-cross' ) ),
			)
		);
	}

	public function test_license_and_installation_proofs_redact_and_cannot_serialize(): void {
		$license = new LicenseProof( 'DLM-SECRET-LICENSE', 'prd_1234567890123456789012' );
		$proof   = new InstallationProof( $license, 'act_1234567890123456789012', 'site-12345678901' );

		self::assertStringNotContainsString( 'DLM-SECRET-LICENSE', print_r( $license, true ) );
		self::assertStringNotContainsString( 'site-12345678901', print_r( $proof, true ) );

		$this->expectException( ContractException::class );
		serialize( $proof );
	}

	public function test_issued_credential_secret_can_be_revealed_only_once(): void {
		$credential = new IssuedCredential( 'ins_1234567890123456789012', 'one-time-secret', '2027-01-01T00:00:00Z' );
		self::assertStringNotContainsString( 'one-time-secret', print_r( $credential, true ) );
		self::assertSame( 'one-time-secret', $credential->reveal_once() );

		$this->expectException( ContractException::class );
		$credential->reveal_once();
	}

	public function test_allowed_decisions_require_authoritative_snapshots(): void {
		$this->expectException( ContractException::class );
		new LicenseDecision( true, 'valid', null, '2026-09-14T00:00:00Z' );
	}

	public function test_registry_rejects_duplicates_and_late_registration(): void {
		$registry = new CommercialProviderRegistry( $this->authority() );
		$provider = new class() implements EntitlementProviderInterface {
			public function resolve( EntitlementRequest $request ): EntitlementSnapshot {
				return new EntitlementSnapshot(
					array(
						'allowed'         => true,
						'code'            => 'allowed',
						'grants'          => array(),
						'source_revision' => 1,
						'issued_at'       => '2026-09-14T00:00:00Z',
					)
				);
			}
		};

		$registry->register_entitlement_provider( $provider );
		self::assertSame( $provider, $registry->entitlement_provider() );
		$registry->lock();
		self::assertTrue( $registry->locked() );

		$this->expectException( ContractException::class );
		$registry->register_entitlement_provider( $provider );
	}

	public function test_registry_supports_one_adapter_per_normalized_provider(): void {
		$registry = new CommercialProviderRegistry( $this->authority() );
		$adapter  = new class() implements BillingEventAdapterInterface {
			public function provider(): string {
				return 'Woo_Subscriptions';
			}

			public function normalize( BillingEventEnvelope $event ): CanonicalBillingEvent {
				return new CanonicalBillingEvent(
					array(
						'provider'          => 'woo_subscriptions',
						'external_event_id' => (string) $event->get( 'external_event_id' ),
						'event_type'        => 'renewed',
						'effective_at'      => '2026-09-14T00:00:00Z',
						'product_ids'       => array(),
						'status'            => 'active',
					)
				);
			}
		};

		$registry->register_billing_adapter( $adapter );
		self::assertSame( $adapter, $registry->billing_adapter( 'WOO_SUBSCRIPTIONS' ) );

		$this->expectException( ContractException::class );
		$registry->register_billing_adapter( $adapter );
	}

	private function license_snapshot(): CoreLicenseSnapshot {
		return new CoreLicenseSnapshot(
			array(
				'license_public_id' => 'lic_1234567890123456789012',
				'product_public_id' => 'prd_1234567890123456789012',
				'lifecycle_status'  => 'assigned',
				'activation_limit'  => 1,
				'expires_at'        => null,
				'order_id'          => 10,
				'order_item_id'     => 20,
				'customer_id'       => 30,
				'evaluated_at'      => '2026-09-14T00:00:00Z',
			)
		);
	}

	private function authority(): CoreAuthorityInterface {
		return new class( $this->license_snapshot() ) implements CoreAuthorityInterface {
			private CoreLicenseSnapshot $snapshot;

			public function __construct( CoreLicenseSnapshot $snapshot ) {
				$this->snapshot = $snapshot;
			}

			public function license_by_public_id( string $license_public_id ): ?CoreLicenseSnapshot {
				return $this->snapshot;
			}

			public function licenses_for_order_item( int $order_item_id ): array {
				return array( $this->snapshot );
			}

			public function evaluate_license( LicenseProof $proof ): LicenseDecision {
				return new LicenseDecision( true, 'valid', $this->snapshot, '2026-09-14T00:00:00Z' );
			}

			public function verify_installation( InstallationProof $proof ): InstallationDecision {
				return new InstallationDecision( true, 'active', $this->snapshot, 'act_1234567890123456789012', 'active', '2026-09-14T00:00:00Z' );
			}
		};
	}
}
