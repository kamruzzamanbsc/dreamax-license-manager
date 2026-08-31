<?php
/**
 * Defines the OrderOperationRepository class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Integrations\WooCommerce;

/**
 * Handles Order operation repository operations.
 */
final class OrderOperationRepository {
	/**
	 * Handles the claim operation.
	 *
	 * @param string $operation Operation value.
	 * @param int    $order_id Order id value.
	 * @param string $request_id Request id value.
	 */
	public function claim( string $operation, int $order_id, string $request_id ): bool {
		global $wpdb;
		$table   = $wpdb->prefix . 'dreamax_lm_idempotency';
		$scope   = $this->scope( $operation, $order_id, $request_id );
		$digest  = hash( 'sha256', $operation . '|' . $order_id, true );
		$now     = gmdate( 'Y-m-d H:i:s' );
		$expires = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The trusted prefixed table requires a fresh direct cleanup.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE scope_hash=UNHEX(%s) AND expires_at<%s', $table, bin2hex( $scope ), $now ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional tables require direct, fresh database reads and writes; object caching would break locking and replay guarantees.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO %i (scope_hash,payload_digest,api_version,operation,state,created_at,expires_at) VALUES (UNHEX(%s),UNHEX(%s),'internal',%s,'processing',%s,%s)",
				$table,
				bin2hex( $scope ),
				bin2hex( $digest ),
				$operation,
				$now,
				$expires
			)
		);
		return 1 === $inserted;
	}

	/**
	 * Handles the complete operation.
	 *
	 * @param string $operation Operation value.
	 * @param int    $order_id Order id value.
	 * @param string $request_id Request id value.
	 */
	public function complete( string $operation, int $order_id, string $request_id ): void {
		global $wpdb;
		$scope = $this->scope( $operation, $order_id, $request_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional tables require direct, fresh database reads and writes; object caching would break locking and replay guarantees.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}dreamax_lm_idempotency SET state='completed',http_status=200,result_code='completed',result_metadata='{}',completed_at=%s WHERE scope_hash=UNHEX(%s) AND state='processing'",
				gmdate( 'Y-m-d H:i:s' ),
				bin2hex( $scope )
			)
		);
	}

	/**
	 * Handles the release operation.
	 *
	 * @param string $operation Operation value.
	 * @param int    $order_id Order id value.
	 * @param string $request_id Request id value.
	 */
	public function release( string $operation, int $order_id, string $request_id ): void {
		global $wpdb;
		$scope = $this->scope( $operation, $order_id, $request_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional tables require direct, fresh database reads and writes; object caching would break locking and replay guarantees.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}dreamax_lm_idempotency WHERE scope_hash=UNHEX(%s) AND state='processing'", bin2hex( $scope ) ) );
	}

	/**
	 * Handles the scope operation.
	 *
	 * @param string $operation Operation value.
	 * @param int    $order_id Order id value.
	 * @param string $request_id Request id value.
	 */
	private function scope( string $operation, int $order_id, string $request_id ): string {
		return hash( 'sha256', 'woocommerce-order-operation|v1|' . $operation . '|' . $order_id . '|' . $request_id, true );
	}
}
