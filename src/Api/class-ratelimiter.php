<?php
/**
 * Defines the RateLimiter class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Api;

use Dreamax\LicenseManager\Database\Transaction;
use Dreamax\LicenseManager\Encryption\Crypto;
use Dreamax\LicenseManager\Licenses\LicenseException;
use RuntimeException;

/**
 * Handles Rate limiter operations.
 */
final class RateLimiter {
	/**
	 * Crypto value.
	 *
	 * @var Crypto
	 */
	private Crypto $crypto;
	/**
	 * Transaction value.
	 *
	 * @var Transaction
	 */
	private Transaction $transaction;

	/**
	 * Initializes the service.
	 */
	public function __construct() {
		$this->crypto      = new Crypto();
		$this->transaction = new Transaction();
	}

	/**
	 * Handles the consume operation.
	 *
	 * @param string $scope Scope value.
	 * @param int    $capacity Capacity value.
	 * @param float  $refill_per_second Refill per second value.
	 * @param int    $status Status value.
	 * @throws RuntimeException When the operation cannot be completed.
	 * @throws LicenseException When the operation cannot be completed.
	 */
	public function consume( string $scope, int $capacity, float $refill_per_second, int $status = 429 ): void {
		$hash = $this->crypto->keyed_hash( $scope, 'rate-limit' );
		$this->transaction->run(
			function () use ( $hash, $capacity, $refill_per_second, $status ): void {
				global $wpdb;
				$table = $wpdb->prefix . 'dreamax_lm_rate_limits';
				$now   = microtime( true );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional tables require direct, fresh database reads and writes; object caching would break locking and replay guarantees.
				$row = $wpdb->get_row(
					$wpdb->prepare( 'SELECT * FROM %i WHERE bucket_hash = UNHEX(%s) FOR UPDATE', $table, bin2hex( $hash ) ),
					ARRAY_A
				);

				if ( ! is_array( $row ) ) {
					$tokens = $capacity - 1.0;
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional tables require direct, fresh database reads and writes; object caching would break locking and replay guarantees.
					$inserted = $wpdb->query(
						$wpdb->prepare(
							'INSERT INTO %i (bucket_hash,tokens,updated_microtime,expires_at) VALUES (UNHEX(%s),%f,%f,%s)',
							$table,
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
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The integer HTTP status is exception metadata, not rendered output.
					throw new LicenseException( 503 === $status ? 'server_unavailable' : 'rate_limited', 503 === $status ? 'The licensing service is temporarily busy.' : 'Too many requests. Try again later.', $status );
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional tables require direct, fresh database reads and writes; object caching would break locking and replay guarantees.
				$updated = $wpdb->query(
					$wpdb->prepare(
						'UPDATE %i SET tokens = %f, updated_microtime = %f, expires_at = %s WHERE bucket_hash = UNHEX(%s)',
						$table,
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
