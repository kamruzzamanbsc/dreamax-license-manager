<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Integrations\WooCommerce;

final class OrderOperationRepository {
	public function claim( string $operation, int $order_id, string $request_id ): bool {
		global $wpdb;
		$table   = $wpdb->prefix . 'dreamax_lm_idempotency';
		$scope   = $this->scope( $operation, $order_id, $request_id );
		$digest  = hash( 'sha256', $operation . '|' . $order_id, true );
		$now     = gmdate( 'Y-m-d H:i:s' );
		$expires = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE scope_hash=UNHEX(%s) AND expires_at<%s", bin2hex( $scope ), $now ) );
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (scope_hash,payload_digest,api_version,operation,state,created_at,expires_at) VALUES (UNHEX(%s),UNHEX(%s),'internal',%s,'processing',%s,%s)",
				bin2hex( $scope ),
				bin2hex( $digest ),
				$operation,
				$now,
				$expires
			)
		);
		return 1 === $inserted;
	}

	public function complete( string $operation, int $order_id, string $request_id ): void {
		global $wpdb;
		$scope = $this->scope( $operation, $order_id, $request_id );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}dreamax_lm_idempotency SET state='completed',http_status=200,result_code='completed',result_metadata='{}',completed_at=%s WHERE scope_hash=UNHEX(%s) AND state='processing'",
				gmdate( 'Y-m-d H:i:s' ),
				bin2hex( $scope )
			)
		);
	}

	public function release( string $operation, int $order_id, string $request_id ): void {
		global $wpdb;
		$scope = $this->scope( $operation, $order_id, $request_id );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}dreamax_lm_idempotency WHERE scope_hash=UNHEX(%s) AND state='processing'", bin2hex( $scope ) ) );
	}

	private function scope( string $operation, int $order_id, string $request_id ): string {
		return hash( 'sha256', 'woocommerce-order-operation|v1|' . $operation . '|' . $order_id . '|' . $request_id, true );
	}
}
