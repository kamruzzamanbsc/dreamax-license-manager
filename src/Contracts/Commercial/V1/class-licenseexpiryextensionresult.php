<?php
/**
 * Defines the LicenseExpiryExtensionResult class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Contracts\Commercial\V1;

/** Reports a sanitized authoritative expiry-extension outcome. */
final class LicenseExpiryExtensionResult {
	/**
	 * Whether Free applied or exactly replayed the command.
	 *
	 * @var bool
	 */
	private bool $applied;
	/**
	 * Stable machine code.
	 *
	 * @var string
	 */
	private string $code;
	/**
	 * License public identifier for successful results.
	 *
	 * @var string|null
	 */
	private ?string $license_public_id;
	/**
	 * Authoritative UTC expiry for successful results.
	 *
	 * @var string|null
	 */
	private ?string $expires_at;
	/**
	 * Whether this is an exact replay.
	 *
	 * @var bool
	 */
	private bool $replayed;
	/**
	 * UTC evaluation time.
	 *
	 * @var string
	 */
	private string $evaluated_at;

	/**
	 * Creates a typed command result.
	 *
	 * @param bool        $applied Whether Free applied or replayed the command.
	 * @param string      $code Stable machine code.
	 * @param string|null $license_public_id Successful license public identifier.
	 * @param string|null $expires_at Successful authoritative UTC expiry.
	 * @param bool        $replayed Whether this is an exact replay.
	 * @param string      $evaluated_at UTC evaluation time.
	 * @throws ContractException When the result is malformed.
	 */
	public function __construct( bool $applied, string $code, ?string $license_public_id, ?string $expires_at, bool $replayed, string $evaluated_at ) {
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{1,63}$/D', $code ) ) {
			throw new ContractException( 'The result code is invalid.' );
		}
		if ( $applied && ( null === $license_public_id || null === $expires_at ) ) {
			throw new ContractException( 'An applied command requires its authoritative result.' );
		}
		$this->applied           = $applied;
		$this->code              = $code;
		$this->license_public_id = $license_public_id;
		$this->expires_at        = $expires_at;
		$this->replayed          = $replayed;
		$this->evaluated_at      = $evaluated_at;
	}

	/** Returns whether Free applied or replayed the command. */
	public function applied(): bool {
		return $this->applied;
	}

	/** Returns the stable machine code. */
	public function code(): string {
		return $this->code;
	}

	/** Returns the successful license public identifier. */
	public function license_public_id(): ?string {
		return $this->license_public_id;
	}

	/** Returns the successful authoritative UTC expiry. */
	public function expires_at(): ?string {
		return $this->expires_at;
	}

	/** Returns whether this result is an exact replay. */
	public function replayed(): bool {
		return $this->replayed;
	}

	/** Returns the UTC evaluation time. */
	public function evaluated_at(): string {
		return $this->evaluated_at;
	}

	/**
	 * Returns the sanitized result fields.
	 *
	 * @return array<string,bool|string|null>
	 */
	public function to_array(): array {
		return array(
			'applied'           => $this->applied,
			'code'              => $this->code,
			'license_public_id' => $this->license_public_id,
			'expires_at'        => $this->expires_at,
			'replayed'          => $this->replayed,
			'evaluated_at'      => $this->evaluated_at,
		);
	}
}
