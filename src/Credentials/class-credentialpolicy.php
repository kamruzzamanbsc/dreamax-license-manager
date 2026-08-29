<?php
/**
 * Defines the CredentialPolicy class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Credentials;

use InvalidArgumentException;

/**
 * Holds deterministic privileged credential lifecycle policy.
 */
final class CredentialPolicy {
	public const LAST_USED_INTERVAL = 300;
	public const RATE_CAPACITY      = 120;
	public const RATE_REFILL        = 2.0;

	/**
	 * Returns the frozen scope catalog.
	 *
	 * @return list<string>
	 */
	public function allowed_scopes(): array {
		return array( 'licenses:read', 'licenses:write', 'activations:read', 'generators:read' );
	}

	/**
	 * Validates, deduplicates, and orders requested scopes.
	 *
	 * @param array $scopes Requested scopes.
	 * @phpstan-param list<string> $scopes
	 * @return list<string>
	 * @throws InvalidArgumentException When a scope is missing or outside the frozen catalog.
	 */
	public function scopes( array $scopes ): array {
		$unique = array_values( array_unique( array_map( 'strval', $scopes ) ) );
		if ( array() === $unique || array_diff( $unique, $this->allowed_scopes() ) ) {
			throw new InvalidArgumentException( 'At least one valid credential scope is required.' );
		}
		return array_values( array_intersect( $this->allowed_scopes(), $unique ) );
	}

	/**
	 * Normalizes an optional future UTC expiration.
	 *
	 * @param string|null $value Expiration input.
	 * @param int|null    $now Current time for deterministic tests.
	 * @throws InvalidArgumentException When expiration is invalid or not future.
	 */
	public function expiration( ?string $value, ?int $now = null ): ?string {
		if ( null === $value || '' === trim( $value ) ) {
			return null;
		}
		$timestamp = strtotime( trim( $value ) . ( preg_match( '/(?:Z|[+-]\d\d:\d\d)$/i', trim( $value ) ) ? '' : ' UTC' ) );
		$current   = $now ?? time();
		if ( false === $timestamp || $timestamp <= $current ) {
			throw new InvalidArgumentException( 'Credential expiration must be a valid future UTC time.' );
		}
		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Returns whether an expiration has reached its fail-closed boundary.
	 *
	 * @param mixed $expires_at Stored expiration.
	 * @param int   $now Current time.
	 */
	public function expired( $expires_at, int $now ): bool {
		if ( ! is_string( $expires_at ) || '' === $expires_at ) {
			return false;
		}
		$timestamp = strtotime( $expires_at . ' UTC' );
		return false === $timestamp || $timestamp <= $now;
	}

	/**
	 * Classifies an authentication result without changing its public envelope.
	 *
	 * @param array|null $row Credential row.
	 * @phpstan-param array<string,mixed>|null $row
	 * @param bool       $secret_valid Whether the verifier matched.
	 * @param int        $now Current time.
	 */
	public function authentication_failure( ?array $row, bool $secret_valid, int $now ): ?string {
		if ( ! is_array( $row ) || ! $secret_valid ) {
			return 'unknown';
		}
		if ( 'revoked' === (string) ( $row['status'] ?? '' ) ) {
			return 'revoked';
		}
		if ( $this->expired( $row['expires_at'] ?? null, $now ) ) {
			return 'expired';
		}
		return 'active' === (string) ( $row['status'] ?? '' ) ? null : 'unknown';
	}

	/**
	 * Checks a fresh row against the authenticated version under the usage lock.
	 *
	 * @param array $row Credential row.
	 * @phpstan-param array<string,mixed> $row
	 * @param int   $authenticated_version Version authenticated before taking the lock.
	 * @param int   $now Current time.
	 */
	public function usable( array $row, int $authenticated_version, int $now ): bool {
		return 'active' === (string) ( $row['status'] ?? '' )
			&& (int) ( $row['secret_version'] ?? 0 ) === $authenticated_version
			&& ! $this->expired( $row['expires_at'] ?? null, $now );
	}

	/**
	 * Enforces optimistic generation matching for double/concurrent rotation.
	 *
	 * @param string $status Current status.
	 * @param int    $current_version Current secret version.
	 * @param int    $expected_version Actor-observed secret version.
	 */
	public function rotation_allowed( string $status, int $current_version, int $expected_version ): bool {
		return 'active' === $status && 0 < $current_version && $current_version === $expected_version;
	}

	/**
	 * Determines whether revocation creates a new business effect.
	 *
	 * @param string $status Current status.
	 */
	public function revocation_changes( string $status ): bool {
		return 'revoked' !== $status;
	}

	/**
	 * Determines whether the bounded last-used timestamp write is due.
	 *
	 * @param mixed $last_used_at Stored last-used timestamp.
	 * @param int   $now Current time.
	 */
	public function last_used_due( $last_used_at, int $now ): bool {
		if ( ! is_string( $last_used_at ) || '' === $last_used_at ) {
			return true;
		}
		$timestamp = strtotime( $last_used_at . ' UTC' );
		return false === $timestamp || $timestamp <= $now - self::LAST_USED_INTERVAL;
	}

	/**
	 * Returns the fixed public authentication error.
	 *
	 * @return array{code:string,message:string,status:int}
	 */
	public function public_authentication_failure(): array {
		return array(
			'code'    => 'authentication_required',
			'message' => 'Authentication is required.',
			'status'  => 401,
		);
	}
}
