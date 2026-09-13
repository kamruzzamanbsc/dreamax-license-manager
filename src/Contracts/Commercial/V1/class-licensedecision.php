<?php
/**
 * Defines the LicenseDecision class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Contracts\Commercial\V1;

/**
 * Reports an authoritative Free license decision.
 */
final class LicenseDecision {
	/**
	 * Whether Free allowed the proof.
	 *
	 * @var bool
	 */
	private bool $allowed;
	/**
	 * Stable machine code.
	 *
	 * @var string
	 */
	private string $code;
	/**
	 * Sanitized license state.
	 *
	 * @var CoreLicenseSnapshot|null
	 */
	private ?CoreLicenseSnapshot $license;
	/**
	 * UTC evaluation time.
	 *
	 * @var string
	 */
	private string $evaluated_at;

	/**
	 * Creates an authoritative license decision.
	 *
	 * @param bool                     $allowed Whether Free allowed the proof.
	 * @param string                   $code Stable machine code.
	 * @param CoreLicenseSnapshot|null $license Sanitized license state.
	 * @param string                   $evaluated_at UTC evaluation time.
	 * @throws ContractException When an allowed decision lacks a snapshot.
	 */
	public function __construct( bool $allowed, string $code, ?CoreLicenseSnapshot $license, string $evaluated_at ) {
		if ( $allowed && null === $license ) {
			throw new ContractException( 'An allowed license decision requires a snapshot.' );
		}
		$this->allowed      = $allowed;
		$this->code         = $code;
		$this->license      = $license;
		$this->evaluated_at = $evaluated_at;
	}

	/** Returns whether Free allowed the proof. */
	public function allowed(): bool {
		return $this->allowed;
	}

	/** Returns the stable machine code. */
	public function code(): string {
		return $this->code;
	}

	/** Returns sanitized license state when available. */
	public function license(): ?CoreLicenseSnapshot {
		return $this->license;
	}

	/** Returns the UTC evaluation time. */
	public function evaluated_at(): string {
		return $this->evaluated_at;
	}
}
