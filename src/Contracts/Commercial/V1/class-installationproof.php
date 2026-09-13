<?php
/**
 * Defines the InstallationProof class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Contracts\Commercial\V1;

/**
 * Holds an installation proof only for the lifetime of one request.
 */
final class InstallationProof {
	/**
	 * In-memory license proof.
	 *
	 * @var LicenseProof
	 */
	private LicenseProof $license_proof;
	/**
	 * Opaque activation identifier.
	 *
	 * @var string
	 */
	private string $activation_public_id;
	/**
	 * Presented installation identity.
	 *
	 * @var string
	 */
	private string $instance_id;

	/**
	 * Creates a bounded installation proof.
	 *
	 * @param LicenseProof $license_proof Validated-shape license proof.
	 * @param string       $activation_public_id Opaque activation identifier.
	 * @param string       $instance_id Presented installation identity.
	 * @throws ContractException When an identifier is invalid.
	 */
	public function __construct( LicenseProof $license_proof, string $activation_public_id, string $instance_id ) {
		if ( 1 !== preg_match( '/^act_[A-Za-z0-9_-]{22}$/D', $activation_public_id ) ) {
			throw new ContractException( 'The activation identifier is invalid.' );
		}
		if ( 1 !== preg_match( '/^[A-Za-z0-9._:-]{16,128}$/D', $instance_id ) ) {
			throw new ContractException( 'The installation identifier is invalid.' );
		}
		$this->license_proof        = $license_proof;
		$this->activation_public_id = $activation_public_id;
		$this->instance_id          = $instance_id;
	}

	/** Returns the nested in-memory license proof. */
	public function license_proof(): LicenseProof {
		return $this->license_proof;
	}

	/** Returns the activation public identifier. */
	public function activation_public_id(): string {
		return $this->activation_public_id;
	}

	/** Returns the presented installation identity for immediate validation. */
	public function instance_id(): string {
		return $this->instance_id;
	}

	/**
	 * Redacts secret proof and installation identity from debug output.
	 *
	 * @return array<string,string>
	 */
	public function __debugInfo(): array {
		return array(
			'license_proof'        => '[redacted]',
			'activation_public_id' => $this->activation_public_id,
			'instance_id'          => '[redacted]',
		);
	}

	/**
	 * Prevents secret-bearing proof serialization.
	 *
	 * @throws ContractException Always.
	 * @return array<never,never>
	 */
	public function __serialize(): array {
		return throw new ContractException( 'Secret-bearing proof objects cannot be serialized.' );
	}
}
