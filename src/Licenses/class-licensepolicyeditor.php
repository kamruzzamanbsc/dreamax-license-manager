<?php
/**
 * Defines safe per-license policy editing.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Licenses;

use DateTimeImmutable;
use DateTimeZone;
use Dreamax\LicenseManager\Database\Transaction;
use Dreamax\LicenseManager\Events\AuditEventCatalog;
use Dreamax\LicenseManager\Events\EventRepository;
use InvalidArgumentException;
use RuntimeException;

/**
 * Updates effective activation and expiry values under a row lock.
 */
final class LicensePolicyEditor {
	/**
	 * Returns the revision bound to the displayed policy state.
	 *
	 * @param array<string,mixed> $row License row.
	 */
	public function revision( array $row ): string {
		$values = array(
			$row['activation_limit'] ?? null,
			$row['expires_at'] ?? null,
			$row['updated_at'] ?? null,
			$row['metadata'] ?? null,
		);
		$parts  = array_map(
			static function ( $value ): string {
				if ( null === $value ) {
					return 'N';
				}
				$value = (string) $value;
				return 'S' . strlen( $value ) . ':' . $value;
			},
			$values
		);
		return hash( 'sha256', implode( '|', $parts ) );
	}

	/**
	 * Parses a strict UTC datetime-local value.
	 *
	 * @param string $value Browser datetime-local value.
	 * @return string|null UTC database datetime, or null for never.
	 * @throws InvalidArgumentException When the timestamp is invalid.
	 */
	public function expiry( string $value ): ?string {
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}
		$zone   = new DateTimeZone( 'UTC' );
		$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $value, $zone );
		$errors = DateTimeImmutable::getLastErrors();
		if ( false === $parsed || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) || $parsed->format( 'Y-m-d\TH:i' ) !== $value ) {
			throw new InvalidArgumentException( 'Enter a valid UTC expiry date and time.' );
		}
		return $parsed->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Atomically changes effective policy values and preserves shared metadata.
	 *
	 * @param string      $public_id License public ID.
	 * @param string      $revision Displayed policy revision.
	 * @param int|null    $limit Activation limit; null means unlimited and zero disables activation.
	 * @param string|null $expires_at Fixed UTC expiry or null for lifetime.
	 * @param string      $reason Audit reason.
	 * @param int         $actor_id Administrator ID.
	 * @return bool Whether any policy field changed.
	 * @throws InvalidArgumentException When input or current state is invalid.
	 */
	public function save( string $public_id, string $revision, ?int $limit, ?string $expires_at, string $reason, int $actor_id ): bool {
		if ( 1 !== preg_match( '/^lic_[A-Za-z0-9_-]{22}$/D', $public_id ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $revision ) || $actor_id < 1 ) {
			throw new InvalidArgumentException( 'The license policy request is invalid.' );
		}
		if ( null !== $limit && ( $limit < 0 || $limit > 1000000 ) ) {
			throw new InvalidArgumentException( 'The activation limit must be between 0 and 1,000,000, or unlimited.' );
		}
		$reason = trim( sanitize_text_field( $reason ) );
		if ( strlen( $reason ) < 3 || strlen( $reason ) > 500 ) {
			throw new InvalidArgumentException( 'Enter a reason between 3 and 500 characters.' );
		}

		return ( new Transaction() )->run(
			function () use ( $public_id, $revision, $limit, $expires_at, $reason, $actor_id ): bool {
				global $wpdb;
				$table = $wpdb->prefix . 'dreamax_lm_licenses';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Effective policy and shared metadata remain locked through the audit write.
				$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE public_id=%s FOR UPDATE', $table, $public_id ), ARRAY_A );
				if ( ! is_array( $row ) ) {
					throw new InvalidArgumentException( 'The license could not be found.' );
				}
				if ( ! hash_equals( $this->revision( $row ), $revision ) ) {
					throw new RuntimeException( 'The license changed since this page was opened. Reload and review it before saving.' );
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Current active use is required while the license row lock is held.
				$active = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_activations WHERE license_id=%d AND status='active'", (int) $row['id'] ) );
				if ( null !== $limit && $limit > 0 && $limit < $active ) {
					throw new InvalidArgumentException( 'The activation limit cannot be lower than the current active installation count.' );
				}
				$current_limit  = null === $row['activation_limit'] ? null : (int) $row['activation_limit'];
				$current_expiry = null === $row['expires_at'] ? null : (string) $row['expires_at'];
				if ( $current_limit === $limit && $current_expiry === $expires_at ) {
					return false;
				}
				$metadata = json_decode( (string) ( $row['metadata'] ?? '{}' ), true );
				if ( ! is_array( $metadata ) || ( array_is_list( $metadata ) && array() !== $metadata ) ) {
					throw new RuntimeException( 'Existing license metadata is invalid; no changes were made.' );
				}
				$metadata['manual_overrides'] = array(
					'activation_limit' => true,
					'expires_at'       => true,
				);
				$encoded                      = wp_json_encode( $metadata );
				if ( ! is_string( $encoded ) ) {
					throw new RuntimeException( 'The license policy could not be encoded.' );
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic plugin-table policy update paired with required audit evidence.
				$updated = $wpdb->update(
					$table,
					array(
						'activation_limit'  => $limit,
						'valid_for_seconds' => null,
						'expires_at'        => $expires_at,
						'updated_at'        => gmdate( 'Y-m-d H:i:s' ),
						'metadata'          => $encoded,
					),
					array( 'id' => (int) $row['id'] ),
					array( '%d', '%d', '%s', '%s', '%s' ),
					array( '%d' )
				);
				if ( 1 !== $updated ) {
					throw new RuntimeException( 'The license policy could not be saved.' );
				}
				( new EventRepository() )->append(
					AuditEventCatalog::LICENSE_UPDATED,
					(int) $row['id'],
					'administrator',
					$actor_id,
					null,
					array(
						'changed_fields' => array( 'activation_limit', 'expires_at' ),
						'reason'         => $reason,
					),
					AuditEventCatalog::SCHEMA_V1
				);
				return true;
			}
		);
	}
}
