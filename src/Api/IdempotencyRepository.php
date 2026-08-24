<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Api;

use Dreamax\LicenseManager\Database\Transaction;
use Dreamax\LicenseManager\Encryption\Crypto;
use Dreamax\LicenseManager\Licenses\LicenseException;

final class IdempotencyRepository {
	private Crypto $crypto;
	private Transaction $transaction;

	public function __construct() {
		$this->crypto      = new Crypto();
		$this->transaction = new Transaction();
	}

	/** @param array<string,mixed> $payload @return array{scope:string,digest:string,replay:?array<string,mixed>} */
	public function reserve( string $key, string $operation, array $payload ): array {
		if ( ! preg_match( '/^[\x21-\x7E]{8,128}$/D', $key ) ) {
			throw new LicenseException( 'invalid_request', 'Idempotency-Key must be 8 to 128 visible ASCII characters.', 400 );
		}
		$canonical = $this->canonical_json( $payload );
		$license_scope = $this->crypto->keyed_hash( (string) ( $payload['license_key'] ?? '' ), 'idempotency-license' );
		$scope = $this->crypto->keyed_hash(
			implode( '|', array( 'v1', $operation, bin2hex( $license_scope ), (string) ( $payload['product_public_id'] ?? '' ), (string) ( $payload['instance_id'] ?? '' ), $key, 'public' ) ),
			'idempotency-scope'
		);
		$digest = hash( 'sha256', $canonical, true );

		$replay = $this->transaction->run(
			function () use ( $scope, $digest, $operation ): ?array {
				global $wpdb;
				$table = $wpdb->prefix . 'dreamax_lm_idempotency';
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE scope_hash = UNHEX(%s) FOR UPDATE", bin2hex( $scope ) ), ARRAY_A );
				if ( is_array( $row ) ) {
					if ( ! hash_equals( bin2hex( $digest ), bin2hex( (string) $row['payload_digest'] ) ) ) {
						throw new LicenseException( 'idempotency_conflict', 'This idempotency key was used with different request data.', 409 );
					}
					if ( 'completed' === $row['state'] ) {
						$data = json_decode( (string) $row['result_metadata'], true );
						return array( 'status' => (int) $row['http_status'], 'code' => (string) $row['result_code'], 'data' => is_array( $data ) ? $data : array() );
					}
					throw new LicenseException( 'server_unavailable', 'The original request is still being processed.', 503 );
				}

				$wpdb->query(
					$wpdb->prepare(
						"INSERT INTO {$table} (scope_hash,payload_digest,api_version,operation,state,created_at,expires_at) VALUES (UNHEX(%s),UNHEX(%s),'v1',%s,'processing',%s,%s)",
						bin2hex( $scope ),
						bin2hex( $digest ),
						$operation,
						gmdate( 'Y-m-d H:i:s' ),
						gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS )
					)
				);
				return null;
			}
		);

		return array( 'scope' => $scope, 'digest' => $digest, 'replay' => $replay );
	}

	/** @param array<string,mixed> $data */
	public function complete( string $scope, int $status, string $code, array $data ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}dreamax_lm_idempotency SET state='completed',http_status=%d,result_code=%s,result_public_id=%s,result_metadata=%s,completed_at=%s WHERE scope_hash=UNHEX(%s)",
				$status,
				$code,
				isset( $data['activation_public_id'] ) ? (string) $data['activation_public_id'] : null,
				wp_json_encode( $this->bounded_result( $data ) ),
				gmdate( 'Y-m-d H:i:s' ),
				bin2hex( $scope )
			)
		);
	}

	/** @param array<string,mixed> $value */
	private function canonical_json( array $value ): string {
		ksort( $value, SORT_STRING );
		return (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/** @param array<string,mixed> $data @return array<string,mixed> */
	private function bounded_result( array $data ): array {
		$allowed = array( 'license_public_id', 'product_public_id', 'activation_public_id', 'status', 'expires_at', 'replayed', 'activations' );
		return array_intersect_key( $data, array_flip( $allowed ) );
	}
}
