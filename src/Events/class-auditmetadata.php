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
	/**
	 * Sanitizes an audit metadata array.
	 *
	 * @param array $metadata Metadata value.
	 * @phpstan-param array<string,mixed> $metadata
	 * @return array<string,mixed>
	 */
	public function sanitize( array $metadata ): array {
		$clean = array();
		foreach ( $metadata as $field => $value ) {
			if ( preg_match( '/(^|_)(authorization|password|secret|token|code|key|raw_ip|idempotency)($|_)/i', (string) $field ) ) {
				continue;
			}
			if ( is_array( $value ) ) {
				/**
				 * Normalize nested metadata to the same string-keyed contract.
				 *
				 * @var array<string,mixed> $value
				 */
				$clean[ $field ] = $this->sanitize( $value );
				continue;
			}
			$clean[ $field ] = $value;
		}
		return $clean;
	}
}
