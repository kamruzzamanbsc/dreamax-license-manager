<?php
/**
 * Defines the LicenseProof class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Contracts\Commercial\V1;

/**
 * Holds a presented license proof only for the lifetime of one request.
 */
final class LicenseProof {
	/**
	 * Presented secret, held only in memory.
	 *
	 * @var string
	 */
	private string $license_key;
	/**
	 * Opaque product identifier.
	 *
	 * @var string
	 */
	private string $product_public_id;

	/**
	 * Creates a bounded license proof.
	 *
	 * @param string $license_key Presented license key.
	 * @param string $product_public_id Opaque product identifier.
	 * @throws ContractException When either value is invalid.
	 */
	public function __construct( string $license_key, string $product_public_id ) {
		if ( '' === $license_key || strlen( $license_key ) > 512 ) {
			throw new ContractException( 'The license proof is invalid.' );
		}
		if ( 1 !== preg_match( '/^prd_[A-Za-z0-9_-]{22}$/D', $product_public_id ) ) {
			throw new ContractException( 'The product identifier is invalid.' );
		}
		$this->license_key       = $license_key;
		$this->product_public_id = $product_public_id;
	}

	/** Returns the presented key for immediate authoritative validation. */
	public function license_key(): string {
		return $this->license_key;
	}

	/** Returns the bound product identifier. */
	public function product_public_id(): string {
		return $this->product_public_id;
	}

	/**
	 * Redacts the presented key from debug output.
	 *
	 * @return array<string,string>
	 */
	public function __debugInfo(): array {
		return array(
			'license_key'       => '[redacted]',
			'product_public_id' => $this->product_public_id,
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
