<?php
/**
 * Defines the KdfSaltRepository interface.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Encryption;

/**
 * Persists one site-local KDF salt without exposing its value.
 */
interface KdfSaltRepository {
	/**
	 * Reads the authoritative stored representation.
	 */
	public function read(): ?string;

	/**
	 * Atomically initializes a missing value without replacing a winner.
	 *
	 * @param string $encoded Canonical ASCII-safe representation.
	 */
	public function initialize( string $encoded ): bool;

	/**
	 * Replaces an existing representation during a key-preserving migration.
	 *
	 * @param string $encoded Canonical ASCII-safe representation.
	 */
	public function replace( string $encoded ): bool;

	/**
	 * Checks the supplied non-secret root identifier against authoritative storage.
	 *
	 * @param string $identifier Non-secret root identifier.
	 */
	public function identifier_matches( string $identifier ): bool;

	/**
	 * Checks whether losing the salt could orphan existing protected records.
	 */
	public function has_protected_data(): bool;
}
