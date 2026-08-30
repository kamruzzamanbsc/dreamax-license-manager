<?php
/**
 * Defines the EventRepository class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Events;

use Dreamax\LicenseManager\Support\PublicId;
use RuntimeException;

/**
 * Handles Event repository operations.
 */
final class EventRepository {
	/**
	 * Handles the append operation.
	 *
	 * @param string  $event_type Event type value.
	 * @param ?int    $license_id License id value.
	 * @phpstan-param int|null $license_id License id value.
	 * @param string  $actor_type Actor type value.
	 * @param ?int    $actor_id Actor id value.
	 * @phpstan-param int|null $actor_id Actor id value.
	 * @param ?string $request_id Request id value.
	 * @phpstan-param string|null $request_id Request id value.
	 * @param array   $metadata Metadata value.
	 * @phpstan-param array<string,mixed> $metadata Metadata value.
	 * @param int     $schema_version Published payload schema version.
	 * @throws RuntimeException When the operation cannot be completed.
	 */
	public function append(
		string $event_type,
		?int $license_id,
		string $actor_type,
		?int $actor_id,
		?string $request_id,
		array $metadata = array(),
		int $schema_version = AuditEventCatalog::SCHEMA_V1
	): string {
		global $wpdb;
		$metadata = AuditEventCatalog::validate( $event_type, $schema_version, $license_id, $actor_type, $actor_id, $request_id, $metadata );
		$encoded  = wp_json_encode( $metadata );
		if ( ! is_string( $encoded ) ) {
			throw new RuntimeException( 'Required audit evidence could not be stored.' );
		}
		$public_id   = PublicId::generate( 'evt' );
		$occurred_at = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin-owned transactional tables require direct, fresh database reads and writes; object caching would break locking and replay guarantees.
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'dreamax_lm_events',
			array(
				'public_id'      => $public_id,
				'license_id'     => $license_id,
				'event_type'     => $event_type,
				'schema_version' => $schema_version,
				'actor_type'     => $actor_type,
				'actor_id'       => $actor_id,
				'request_id'     => $request_id,
				'occurred_at'    => $occurred_at,
				'metadata'       => $encoded,
			),
			array( '%s', '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			throw new RuntimeException( 'Required audit evidence could not be stored.' );
		}
		return $public_id;
	}

	/**
	 * Handles the for license operation.
	 *
	 * @param int $license_id License id value.
	 * @param int $limit Limit value.
	 * @return list<array<string,mixed>>
	 */
	public function for_license( int $license_id, int $limit = 100 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional tables require direct, fresh database reads and writes; object caching would break locking and replay guarantees.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT public_id,license_id,event_type,schema_version,actor_type,actor_id,request_id,occurred_at,metadata FROM {$wpdb->prefix}dreamax_lm_events WHERE license_id=%d ORDER BY id DESC LIMIT %d",
				$license_id,
				min( 100, max( 1, $limit ) )
			),
			ARRAY_A
		);
		return $this->compatible_rows( is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Handles the recent operation.
	 *
	 * @param int $limit Limit value.
	 * @return list<array<string,mixed>>
	 */
	public function recent( int $limit = 100 ): array {
		global $wpdb;
		$events   = $wpdb->prefix . 'dreamax_lm_events';
		$licenses = $wpdb->prefix . 'dreamax_lm_licenses';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional tables require direct, fresh database reads and writes; object caching would break locking and replay guarantees.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT e.public_id,e.license_id,e.event_type,e.schema_version,e.actor_type,e.actor_id,e.request_id,e.occurred_at,e.metadata,l.public_id AS license_public_id FROM %i e LEFT JOIN %i l ON l.id=e.license_id ORDER BY e.id DESC LIMIT %d',
				$events,
				$licenses,
				min( 200, max( 1, $limit ) )
			),
			ARRAY_A
		);
		return $this->compatible_rows( is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Preserves legacy rows while labeling their consumer compatibility status.
	 *
	 * @param array $rows Persisted event rows.
	 * @phpstan-param list<array<string,mixed>> $rows
	 * @return list<array<string,mixed>>
	 */
	private function compatible_rows( array $rows ): array {
		$result = array();
		foreach ( $rows as $row ) {
			$row['contract_status'] = AuditEventCatalog::compatibility_status( $row );
			$metadata               = json_decode( (string) ( $row['metadata'] ?? '{}' ), true );
			$row['metadata']        = wp_json_encode( ( new AuditMetadata() )->sanitize( is_array( $metadata ) ? $metadata : array() ) );
			$result[]               = $row;
		}
		return $result;
	}
}
