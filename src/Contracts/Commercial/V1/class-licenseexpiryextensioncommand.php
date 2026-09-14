<?php
/**
 * Defines the LicenseExpiryExtensionCommand class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Contracts\Commercial\V1;

use DateTimeImmutable;
use DateTimeZone;

/** Carries one product-bound, idempotent expiry extension request. */
final class LicenseExpiryExtensionCommand {
	/**
	 * License public identifier.
	 *
	 * @var string
	 */
	private string $license_public_id;
	/**
	 * Expected product public identifier.
	 *
	 * @var string
	 */
	private string $product_public_id;
	/**
	 * Extension duration in days.
	 *
	 * @var int
	 */
	private int $extension_days;
	/**
	 * Authoritative UTC effective time.
	 *
	 * @var string
	 */
	private string $effective_at;
	/**
	 * Deterministic operation identifier.
	 *
	 * @var string
	 */
	private string $operation_id;

	/**
	 * Creates and validates a non-secret command value.
	 *
	 * @param string $license_public_id License public identifier.
	 * @param string $product_public_id Expected product public identifier.
	 * @param int    $extension_days Extension duration in days.
	 * @param string $effective_at Authoritative whole-second UTC time.
	 * @param string $operation_id Deterministic operation identifier.
	 * @throws ContractException When any command field is invalid.
	 */
	public function __construct(
		string $license_public_id,
		string $product_public_id,
		int $extension_days,
		string $effective_at,
		string $operation_id
	) {
		if ( 1 !== preg_match( '/^lic_[A-Za-z0-9_-]{22}$/D', $license_public_id ) ) {
			throw new ContractException( 'The license public ID is invalid.' );
		}
		if ( 1 !== preg_match( '/^prd_[A-Za-z0-9_-]{22}$/D', $product_public_id ) ) {
			throw new ContractException( 'The product public ID is invalid.' );
		}
		if ( $extension_days < 1 || $extension_days > 3650 ) {
			throw new ContractException( 'The extension must be between 1 and 3650 days.' );
		}
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $effective_at ) ) {
			throw new ContractException( 'The effective time must be a whole-second UTC timestamp.' );
		}
		$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s\Z', $effective_at, new DateTimeZone( 'UTC' ) );
		if ( false === $parsed || $parsed->format( 'Y-m-d\TH:i:s\Z' ) !== $effective_at ) {
			throw new ContractException( 'The effective time is invalid.' );
		}
		if ( 1 !== preg_match( '/^[A-Za-z0-9._:-]{16,64}$/D', $operation_id ) ) {
			throw new ContractException( 'The operation identifier is invalid.' );
		}

		$this->license_public_id = $license_public_id;
		$this->product_public_id = $product_public_id;
		$this->extension_days    = $extension_days;
		$this->effective_at      = $effective_at;
		$this->operation_id      = $operation_id;
	}

	/** Returns the license public identifier. */
	public function license_public_id(): string {
		return $this->license_public_id;
	}

	/** Returns the expected product public identifier. */
	public function product_public_id(): string {
		return $this->product_public_id;
	}

	/** Returns the extension duration in days. */
	public function extension_days(): int {
		return $this->extension_days;
	}

	/** Returns the authoritative UTC effective time. */
	public function effective_at(): string {
		return $this->effective_at;
	}

	/** Returns the effective time as a Unix timestamp. */
	public function effective_timestamp(): int {
		return (int) strtotime( $this->effective_at );
	}

	/** Returns the deterministic operation identifier. */
	public function operation_id(): string {
		return $this->operation_id;
	}

	/** Returns the canonical digest used to detect changed-payload replay. */
	public function digest(): string {
		return hash( 'sha256', implode( "\n", array( $this->license_public_id, $this->product_public_id, (string) $this->extension_days, $this->effective_at ) ) );
	}

	/**
	 * Returns the non-secret command fields.
	 *
	 * @return array<string,int|string>
	 */
	public function to_array(): array {
		return array(
			'license_public_id' => $this->license_public_id,
			'product_public_id' => $this->product_public_id,
			'extension_days'    => $this->extension_days,
			'effective_at'      => $this->effective_at,
			'operation_id'      => $this->operation_id,
		);
	}
}
