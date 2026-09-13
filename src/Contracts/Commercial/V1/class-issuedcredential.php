<?php
/**
 * Defines the IssuedCredential class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Contracts\Commercial\V1;

/** Protects a newly issued credential secret from serialization and debug output. */
final class IssuedCredential {
	/**
	 * Opaque credential identifier.
	 *
	 * @var string
	 */
	private string $public_id;
	/**
	 * One-time credential secret.
	 *
	 * @var string|null
	 */
	private ?string $secret;
	/**
	 * UTC expiry.
	 *
	 * @var string
	 */
	private string $expires_at;

	/**
	 * Creates a one-time credential result.
	 *
	 * @param string $public_id Opaque credential identifier.
	 * @param string $secret One-time secret.
	 * @param string $expires_at UTC expiry.
	 * @throws ContractException When a required value is empty.
	 */
	public function __construct( string $public_id, string $secret, string $expires_at ) {
		if ( '' === $public_id || '' === $secret || '' === $expires_at ) {
			throw new ContractException( 'The issued credential is incomplete.' );
		}
		$this->public_id  = $public_id;
		$this->secret     = $secret;
		$this->expires_at = $expires_at;
	}

	/** Returns the opaque credential identifier. */
	public function public_id(): string {
		return $this->public_id;
	}

	/**
	 * Reveals and clears the credential secret.
	 *
	 * @throws ContractException When the secret was already revealed.
	 */
	public function reveal_once(): string {
		if ( null === $this->secret ) {
			throw new ContractException( 'The credential secret has already been revealed.' );
		}
		$secret       = $this->secret;
		$this->secret = null;
		return $secret;
	}

	/** Returns the UTC expiry. */
	public function expires_at(): string {
		return $this->expires_at;
	}

	/**
	 * Redacts the credential secret from debug output.
	 *
	 * @return array<string,string>
	 */
	public function __debugInfo(): array {
		return array(
			'public_id'  => $this->public_id,
			'secret'     => '[redacted]',
			'expires_at' => $this->expires_at,
		);
	}

	/**
	 * Prevents credential serialization.
	 *
	 * @throws ContractException Always.
	 * @return array<never,never>
	 */
	public function __serialize(): array {
		return throw new ContractException( 'Credential secrets cannot be serialized.' );
	}
}
