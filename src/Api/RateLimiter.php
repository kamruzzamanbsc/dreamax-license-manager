<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Api;

use Dreamax\LicenseManager\Database\Transaction;
use Dreamax\LicenseManager\Encryption\Crypto;
use Dreamax\LicenseManager\Licenses\LicenseException;

final class RateLimiter {
	private Crypto $crypto;
	private Transaction $transaction;

	public function __construct() {
		$this->crypto      = new Crypto();
		$this->transaction = new Transaction();
	}

	public function consume( string $scope, int $capacity, float $refill_per_second, int $status = 429 ): void {
		$hash = $this->crypto->keyed_hash( $scope, 'rate-limit' );
		$this->transaction->run(
			function () use ( $hash, $capacity, $refill_per_second, $status ): void {
				global $wpdb;
				$table = $wpdb->prefix . 'dreamax_lm_rate_limits';
				$now   = microtime( true );
				$row   = $wpdb->get_row(
					$wpdb->prepare( "SELECT * FROM {$table} WHERE bucket_hash = UNHEX(%s) FOR UPDATE", bin2hex( $hash ) ),
					ARRAY_A
				);

				if ( ! is_array( $row ) ) {
					$tokens = $capacity - 1.0;
					$inserted = $wpdb->query(
						$wpdb->prepare(
							"INSERT INTO {$table} (bucket_hash,tokens,updated_microtime,expires_at) VALUES (UNHEX(%s),%f,%f,%s)",
							bin2hex( $hash ),
							$tokens,
							$now,
							gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS )
						)
					);
					if ( false === $inserted ) {
						throw new \RuntimeException( 'The rate-limit bucket could not be stored.' );
					}
					return;
				}

				$elapsed = max( 0.0, $now - (float) $row['updated_microtime'] );
				$tokens  = min( (float) $capacity, (float) $row['tokens'] + $elapsed * $refill_per_second );
				if ( $tokens < 1.0 ) {
					throw new LicenseException( $status === 503 ? 'server_unavailable' : 'rate_limited', $status === 503 ? 'The licensing service is temporarily busy.' : 'Too many requests. Try again later.', $status );
				}

				$updated = $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$table} SET tokens = %f, updated_microtime = %f, expires_at = %s WHERE bucket_hash = UNHEX(%s)",
						$tokens - 1.0,
						$now,
						gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
						bin2hex( $hash )
					)
				);
				if ( false === $updated ) {
					throw new \RuntimeException( 'The rate-limit bucket could not be updated.' );
				}
			}
		);
	}
}
