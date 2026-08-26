<?php
/**
 * Defines the WordPressCredentialAdminOperationRepository class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Credentials;

use Dreamax\LicenseManager\Encryption\Crypto;
use RuntimeException;

/**
 * Stores credential administration operation reservations site-locally.
 */
final class WordPressCredentialAdminOperationRepository implements CredentialAdminOperationRepository {
	private const API_VERSION = 'admin-v1';

	/**
	 * Keyed hashing service.
	 *
	 * @var Crypto
	 */
	private Crypto $crypto;

	/**
	 * Initializes the repository.
	 *
	 * @param Crypto|null $crypto Keyed hashing service.
	 */
	public function __construct( ?Crypto $crypto = null ) {
		$this->crypto = $crypto ?? new Crypto();
	}

	/**
	 * Atomically reserves an operation or reports an existing reservation.
	 *
	 * @param string $operation_id One-time operation identifier.
	 * @param string $operation Bounded operation name.
	 * @param int    $actor_id Authenticated administrator ID.
	 * @param array  $payload Canonical operation payload.
	 * @phpstan-param array<string,mixed> $payload Canonical operation payload.
	 * @return string|null Opaque reservation scope for the winner; null for a replay or conflict.
	 * @throws RuntimeException When authoritative reservation storage is unavailable.
	 */
	public function reserve( string $operation_id, string $operation, int $actor_id, array $payload ): ?string {
		global $wpdb;
		$scope   = $this->crypto->keyed_hash( self::API_VERSION . '|' . $operation . '|' . $actor_id . '|' . $operation_id, 'credential-admin-operation' );
		$digest  = hash( 'sha256', $this->canonical_json( $payload ), true );
		$now     = gmdate( 'Y-m-d H:i:s' );
		$expires = gmdate( 'Y-m-d H:i:s', time() + ( 2 * DAY_IN_SECONDS ) );
		$table   = $wpdb->prefix . 'dreamax_lm_idempotency';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The unique scope hash makes the first site-local reservation the only winner.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is built from the trusted WordPress database prefix.
				"INSERT IGNORE INTO {$table} (scope_hash,payload_digest,api_version,operation,state,created_at,expires_at) VALUES (UNHEX(%s),UNHEX(%s),%s,%s,'processing',%s,%s)",
				bin2hex( $scope ),
				bin2hex( $digest ),
				self::API_VERSION,
				$operation,
				$now,
				$expires
			)
		);
		if ( false === $inserted ) {
			throw new RuntimeException( 'The credential operation could not be reserved.' );
		}
		if ( 1 === $inserted ) {
			return $scope;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A losing request must authoritatively inspect the canonical reservation.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT payload_digest,api_version,operation,state FROM {$table} WHERE scope_hash=UNHEX(%s) LIMIT 1", bin2hex( $scope ) ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			throw new RuntimeException( 'The credential operation reservation is unavailable.' );
		}
		if ( self::API_VERSION !== (string) $row['api_version'] || $operation !== (string) $row['operation'] || ! hash_equals( $digest, (string) $row['payload_digest'] ) ) {
			return null;
		}

		return null;
	}

	/**
	 * Marks a winning operation complete without persisting its result.
	 *
	 * @param string $scope Opaque reservation scope.
	 * @throws RuntimeException When the terminal state cannot be recorded.
	 */
	public function complete( string $scope ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Completion records only a generic terminal state and no result or credential material.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}dreamax_lm_idempotency SET state='completed',http_status=200,result_code='completed',result_metadata='{}',completed_at=%s WHERE scope_hash=UNHEX(%s) AND state='processing'",
				gmdate( 'Y-m-d H:i:s' ),
				bin2hex( $scope )
			)
		);
		if ( 1 !== $updated ) {
			throw new RuntimeException( 'The credential operation could not be completed.' );
		}
	}

	/**
	 * Marks a winning operation failed without making its token reusable.
	 *
	 * @param string $scope Opaque reservation scope.
	 */
	public function fail( string $scope ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Failure records only a generic terminal state and keeps the operation non-reusable.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}dreamax_lm_idempotency SET state='failed',result_code='not_completed',result_metadata='{}',completed_at=%s WHERE scope_hash=UNHEX(%s) AND state='processing'",
				gmdate( 'Y-m-d H:i:s' ),
				bin2hex( $scope )
			)
		);
	}

	/**
	 * Produces deterministic JSON for a bounded administration payload.
	 *
	 * @param array $payload Operation payload.
	 * @phpstan-param array<string,mixed> $payload Operation payload.
	 * @throws RuntimeException When the payload cannot be encoded.
	 */
	private function canonical_json( array $payload ): string {
		ksort( $payload, SORT_STRING );
		$encoded = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $encoded ) {
			throw new RuntimeException( 'The credential operation payload is invalid.' );
		}
		return $encoded;
	}
}
