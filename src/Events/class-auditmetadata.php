<?php
/**
 * Defines the AuditMetadata class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Events;

/**
 * Recursively removes secret-bearing fields from audit metadata.
 */
final class AuditMetadata {
	private const MAX_DEPTH = 8;
	private const MAX_ITEMS = 256;

	/**
	 * Sanitizes an audit metadata array.
	 *
	 * @param array $metadata Metadata value.
	 * @phpstan-param array<string,mixed> $metadata
	 * @return array<string,mixed>
	 */
	public function sanitize( array $metadata ): array {
		return $this->sanitize_array( $metadata, 0 );
	}

	/**
	 * Sanitizes a bounded metadata array at any nesting level.
	 *
	 * @param array $metadata Metadata value.
	 * @phpstan-param array<array-key,mixed> $metadata
	 * @param int   $depth Current recursive depth.
	 * @return array<array-key,mixed>
	 */
	private function sanitize_array( array $metadata, int $depth ): array {
		if ( $depth >= self::MAX_DEPTH ) {
			return array();
		}
		$clean = array();
		foreach ( array_slice( $metadata, 0, self::MAX_ITEMS, true ) as $field => $value ) {
			$name = strtolower( (string) $field );
			if ( 'credential_public_id' !== $name && preg_match( '/(^|_)(authorization|password|secret|token|code|key|credential|verifier|request_body|raw_ip|idempotency|cookie|nonce|session|master_key|email|url)($|_)/i', $name ) ) {
				continue;
			}
			if ( is_object( $value ) ) {
				$value = get_object_vars( $value );
			}
			if ( is_array( $value ) ) {
				$nested          = $this->sanitize_array( $value, $depth + 1 );
				$clean[ $field ] = array_is_list( $value ) ? array_values( $nested ) : $nested;
				continue;
			}
			if ( is_string( $value ) && $this->secret_value( $value ) ) {
				continue;
			}
			if ( ! is_string( $value ) && ! is_int( $value ) && ! is_float( $value ) && ! is_bool( $value ) && null !== $value ) {
				continue;
			}
			$clean[ $field ] = $value;
		}
		return $clean;
	}

	/**
	 * Detects high-confidence secret material carried under an innocent key.
	 *
	 * @param string $value Metadata string.
	 */
	private function secret_value( string $value ): bool {
		if ( strlen( $value ) > 4096 || 1 !== preg_match( '//u', $value ) ) {
			return true;
		}
		$patterns = array(
			'/\bBearer\s+\S+/i',
			'/\bdlm_v1_[A-Za-z0-9_-]{22}\.[A-Za-z0-9_-]{43}\b/',
			'/\bDLM-[A-Z2-9-]{20,}\b/i',
			'/(?:^|[^A-Za-z0-9_-])[A-Za-z0-9_-]{43}(?:$|[^A-Za-z0-9_-])/',
			'/\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b/',
			'/-----BEGIN (?:RSA |EC |OPENSSH |DSA )?PRIVATE KEY-----/',
			'/\$(?:2[ayb]\$|argon2(?:i|id|d)\$)/i',
			'/https?:\/\/[^\s?]+\?[^\s]*(?:authorization|password|secret|token|code|key|nonce|session|cookie)=/i',
		);
		foreach ( $patterns as $pattern ) {
			if ( 1 === preg_match( $pattern, $value ) ) {
				return true;
			}
		}
		return false;
	}
}
