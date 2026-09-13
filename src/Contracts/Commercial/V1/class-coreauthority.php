<?php
/**
 * Defines the CoreAuthority class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Contracts\Commercial\V1;

use Dreamax\LicenseManager\Activations\ActivationService;
use Dreamax\LicenseManager\Encryption\Crypto;
use Dreamax\LicenseManager\Licenses\LicenseException;
use Dreamax\LicenseManager\Licenses\LicenseRepository;
use Throwable;

/**
 * Adapts Free-owned services to a sanitized commercial contract.
 */
final class CoreAuthority implements CoreAuthorityInterface {
	/**
	 * Free-owned license persistence.
	 *
	 * @var LicenseRepository
	 */
	private LicenseRepository $licenses;
	/**
	 * Free-owned activation policy.
	 *
	 * @var ActivationService
	 */
	private ActivationService $activations;
	/**
	 * Free-owned cryptographic readiness.
	 *
	 * @var Crypto
	 */
	private Crypto $crypto;

	/** Initializes the adapter with Free-owned services. */
	public function __construct() {
		$this->crypto      = new Crypto();
		$this->licenses    = new LicenseRepository( null, $this->crypto );
		$this->activations = new ActivationService();
	}

	/**
	 * Returns one sanitized license snapshot by opaque public identifier.
	 *
	 * @param string $license_public_id Opaque license identifier.
	 */
	public function license_by_public_id( string $license_public_id ): ?CoreLicenseSnapshot {
		if ( 1 !== preg_match( '/^lic_[A-Za-z0-9_-]{22}$/D', $license_public_id ) ) {
			return null;
		}
		try {
			$row = $this->licenses->by_public_id( $license_public_id );
			return is_array( $row ) ? $this->snapshot( $row ) : null;
		} catch ( Throwable $exception ) {
			return null;
		}
	}

	/**
	 * Returns sanitized licenses allocated to one order item.
	 *
	 * @param int $order_item_id WooCommerce order-item identifier.
	 * @return list<CoreLicenseSnapshot>
	 */
	public function licenses_for_order_item( int $order_item_id ): array {
		if ( $order_item_id < 1 ) {
			return array();
		}
		try {
			return array_map( array( $this, 'snapshot' ), $this->licenses->for_order_item( $order_item_id ) );
		} catch ( Throwable $exception ) {
			return array();
		}
	}

	/**
	 * Evaluates a presented license through authoritative Free policy.
	 *
	 * @param LicenseProof $proof In-memory license proof.
	 */
	public function evaluate_license( LicenseProof $proof ): LicenseDecision {
		$now = gmdate( 'c' );
		if ( ! $this->crypto->ready() ) {
			return new LicenseDecision( false, 'core_encryption_unavailable', null, $now );
		}
		try {
			$result   = $this->activations->validate( $proof->license_key(), $proof->product_public_id() );
			$snapshot = $this->license_by_public_id( (string) $result['license_public_id'] );
			if ( null === $snapshot ) {
				return new LicenseDecision( false, 'core_storage_unavailable', null, $now );
			}
			return new LicenseDecision( true, 'valid', $snapshot, $now );
		} catch ( LicenseException $exception ) {
			return new LicenseDecision( false, $exception->machine_code(), null, $now );
		} catch ( Throwable $exception ) {
			return new LicenseDecision( false, 'core_storage_unavailable', null, $now );
		}
	}

	/**
	 * Verifies an installation through authoritative Free policy.
	 *
	 * @param InstallationProof $proof In-memory installation proof.
	 */
	public function verify_installation( InstallationProof $proof ): InstallationDecision {
		$now = gmdate( 'c' );
		if ( ! $this->crypto->ready() ) {
			return new InstallationDecision( false, 'core_encryption_unavailable', null, null, null, $now );
		}
		try {
			$license  = $proof->license_proof();
			$result   = $this->activations->verify_installation(
				$license->license_key(),
				$license->product_public_id(),
				$proof->activation_public_id(),
				$proof->instance_id()
			);
			$snapshot = $this->license_by_public_id( (string) $result['license_public_id'] );
			if ( null === $snapshot ) {
				return new InstallationDecision( false, 'core_storage_unavailable', null, null, null, $now );
			}
			return new InstallationDecision( true, 'active', $snapshot, (string) $result['activation_public_id'], 'active', $now );
		} catch ( LicenseException $exception ) {
			return new InstallationDecision( false, $exception->machine_code(), null, null, null, $now );
		} catch ( Throwable $exception ) {
			return new InstallationDecision( false, 'core_storage_unavailable', null, null, null, $now );
		}
	}

	/**
	 * Converts a private Free row to a sanitized contract snapshot.
	 *
	 * @param array<string,mixed> $row Free-owned license row.
	 */
	private function snapshot( array $row ): CoreLicenseSnapshot {
		return new CoreLicenseSnapshot(
			array(
				'license_public_id' => (string) $row['public_id'],
				'product_public_id' => null === $row['product_public_id'] ? null : (string) $row['product_public_id'],
				'lifecycle_status'  => (string) $row['lifecycle_status'],
				'activation_limit'  => null === $row['activation_limit'] ? null : (int) $row['activation_limit'],
				'expires_at'        => null === $row['expires_at'] ? null : (string) $row['expires_at'],
				'order_id'          => null === $row['order_id'] ? null : (int) $row['order_id'],
				'order_item_id'     => null === $row['order_item_id'] ? null : (int) $row['order_item_id'],
				'customer_id'       => null === $row['customer_id'] ? null : (int) $row['customer_id'],
				'evaluated_at'      => gmdate( 'c' ),
			)
		);
	}
}
