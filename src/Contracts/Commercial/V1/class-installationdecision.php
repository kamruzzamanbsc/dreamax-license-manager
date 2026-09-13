<?php
/**
 * Defines the InstallationDecision class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Contracts\Commercial\V1;

/**
 * Reports an authoritative Free installation decision.
 */
final class InstallationDecision {
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
	 * Opaque activation identifier.
	 *
	 * @var string|null
	 */
	private ?string $activation_public_id;
	/**
	 * Sanitized activation status.
	 *
	 * @var string|null
	 */
	private ?string $status;
	/**
	 * UTC evaluation time.
	 *
	 * @var string
	 */
	private string $evaluated_at;

	/**
	 * Creates an authoritative installation decision.
	 *
	 * @param bool                     $allowed Whether Free allowed the proof.
	 * @param string                   $code Stable machine code.
	 * @param CoreLicenseSnapshot|null $license Sanitized license state.
	 * @param string|null              $activation_public_id Opaque activation identifier.
	 * @param string|null              $status Sanitized activation status.
	 * @param string                   $evaluated_at UTC evaluation time.
	 * @throws ContractException When an allowed decision lacks active state.
	 */
	public function __construct( bool $allowed, string $code, ?CoreLicenseSnapshot $license, ?string $activation_public_id, ?string $status, string $evaluated_at ) {
		if ( $allowed && ( null === $license || null === $activation_public_id || 'active' !== $status ) ) {
			throw new ContractException( 'An allowed installation decision requires an active installation snapshot.' );
		}
		$this->allowed              = $allowed;
		$this->code                 = $code;
		$this->license              = $license;
		$this->activation_public_id = $activation_public_id;
		$this->status               = $status;
		$this->evaluated_at         = $evaluated_at;
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

	/** Returns the verified activation public identifier. */
	public function activation_public_id(): ?string {
		return $this->activation_public_id;
	}

	/** Returns the verified activation status. */
	public function status(): ?string {
		return $this->status;
	}

	/** Returns the UTC evaluation time. */
	public function evaluated_at(): string {
		return $this->evaluated_at;
	}
}
