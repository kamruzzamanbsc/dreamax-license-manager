<?php
/**
 * Defines merchant-only license metadata operations.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Licenses;

use Dreamax\LicenseManager\Database\Transaction;
use Dreamax\LicenseManager\Events\AuditEventCatalog;
use Dreamax\LicenseManager\Events\EventRepository;
use InvalidArgumentException;
use RuntimeException;

/**
 * Keeps operator notes separate from licensing policy and public responses.
 */
final class MerchantMetadata {
	/**
	 * Reads only the merchant-owned namespace from a license row.
	 *
	 * @param array<string,mixed> $row License row.
	 * @return array{note:string,fields:array<string,string>,revision:string}
	 * @throws RuntimeException When existing metadata is malformed.
	 */
	public function view( array $row ): array {
		$raw      = (string) ( $row['metadata'] ?? '' );
		$metadata = $this->decode( $raw );
		$merchant = $metadata['merchant_data'] ?? array();
		if ( ! is_array( $merchant ) ) {
			throw new RuntimeException( 'The license notes cannot be read safely.' );
		}
		$note   = $merchant['note'] ?? '';
		$fields = $merchant['fields'] ?? array();
		if ( ! is_string( $note ) || ! is_array( $fields ) ) {
			throw new RuntimeException( 'The license notes cannot be read safely.' );
		}
		$clean_fields = array();
		foreach ( $fields as $key => $value ) {
			if ( ! is_string( $key ) || ! is_string( $value ) ) {
				throw new RuntimeException( 'The license notes cannot be read safely.' );
			}
			$clean_fields[ $key ] = $value;
		}
		return array(
			'note'     => $note,
			'fields'   => $clean_fields,
			'revision' => hash( 'sha256', $raw ),
		);
	}

	/**
	 * Atomically replaces the merchant namespace without overwriting policy state.
	 *
	 * @param string               $public_id License public ID.
	 * @param string               $revision Revision of the row displayed to the operator.
	 * @param string               $note Internal note.
	 * @param array<string,string> $fields Bounded merchant reference fields.
	 * @param int                  $actor_id Administrator ID.
	 * @return bool Whether anything changed.
	 * @throws InvalidArgumentException When the supplied fields are invalid.
	 */
	public function save( string $public_id, string $revision, string $note, array $fields, int $actor_id ): bool {
		if ( 1 !== preg_match( '/^lic_[A-Za-z0-9_-]{22}$/D', $public_id ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $revision ) || $actor_id < 1 ) {
			throw new InvalidArgumentException( 'The license update request is invalid.' );
		}
		$note   = trim( sanitize_textarea_field( $note ) );
		$fields = $this->validate_fields( $fields );
		if ( strlen( $note ) > 2000 ) {
			throw new InvalidArgumentException( 'The internal note is too long.' );
		}
		return ( new Transaction() )->run(
			function () use ( $public_id, $revision, $note, $fields, $actor_id ): bool {
				global $wpdb;
				$table = $wpdb->prefix . 'dreamax_lm_licenses';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The license and its policy metadata must be locked until the audit write commits.
				$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id,metadata FROM %i WHERE public_id=%s FOR UPDATE', $table, $public_id ), ARRAY_A );
				if ( ! is_array( $row ) ) {
					throw new InvalidArgumentException( 'The license could not be found.' );
				}
				$current = $this->view( $row );
				if ( ! hash_equals( $current['revision'], $revision ) ) {
					throw new RuntimeException( 'The license changed since this page was opened. Reload and review it before saving.' );
				}
				if ( $current['note'] === $note && $current['fields'] === $fields ) {
					return false;
				}
				$metadata = $this->decode( (string) ( $row['metadata'] ?? '' ) );
				if ( '' === $note && array() === $fields ) {
					unset( $metadata['merchant_data'] );
				} else {
					$metadata['merchant_data'] = array(
						'note'   => $note,
						'fields' => $fields,
					);
				}
				$encoded = wp_json_encode( $metadata );
				if ( ! is_string( $encoded ) ) {
					throw new RuntimeException( 'The license notes could not be encoded.' );
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Transactional update; the event write must succeed in the same transaction.
				$updated = $wpdb->update(
					$table,
					array(
						'metadata'   => $encoded,
						'updated_at' => gmdate( 'Y-m-d H:i:s' ),
					),
					array( 'id' => (int) $row['id'] ),
					array( '%s', '%s' ),
					array( '%d' )
				);
				if ( 1 !== $updated ) {
					throw new RuntimeException( 'The license notes could not be saved.' );
				}
				( new EventRepository() )->append( AuditEventCatalog::LICENSE_UPDATED, (int) $row['id'], 'administrator', $actor_id, null, array( 'changed_fields' => array( 'merchant_data' ) ), AuditEventCatalog::SCHEMA_V1 );
				return true;
			}
		);
	}

	/**
	 * Validates the names, size, and content of merchant reference fields.
	 *
	 * @param array<string,string> $fields Operator-supplied fields.
	 * @return array<string,string>
	 * @throws InvalidArgumentException When a field is invalid.
	 */
	private function validate_fields( array $fields ): array {
		if ( count( $fields ) > 10 ) {
			throw new InvalidArgumentException( 'A maximum of 10 reference fields is allowed.' );
		}
		$clean = array();
		foreach ( $fields as $key => $value ) {
			if ( ! is_string( $key ) || ! is_string( $value ) ) {
				throw new InvalidArgumentException( 'Reference fields must be text.' );
			}
			$key   = trim( $key );
			$value = trim( sanitize_text_field( $value ) );
			if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{0,31}$/D', $key ) || 1 === preg_match( '/(?:secret|password|token|license_key|credential|auth|cookie|email|address)/i', $key ) || strlen( $value ) > 160 ) {
				throw new InvalidArgumentException( 'A reference field name or value is invalid.' );
			}
			$clean[ $key ] = $value;
		}
		ksort( $clean );
		return $clean;
	}

	/**
	 * Decodes a shared metadata document without silently replacing bad data.
	 *
	 * @param string $raw Stored JSON document.
	 * @return array<string,mixed>
	 * @throws RuntimeException When stored metadata is malformed.
	 */
	private function decode( string $raw ): array {
		if ( '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) || ( array_is_list( $decoded ) && array() !== $decoded ) ) {
			throw new RuntimeException( 'Existing license metadata is invalid; no changes were made.' );
		}
		return $decoded;
	}
}
