<?php
/**
 * Defines the Transaction class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Database;

use RuntimeException;
use Throwable;

/**
 * Handles Transaction operations.
 */
final class Transaction {
	/**
	 * Handles the run operation.
	 *
	 * @param callable $callback Callback value.
	 * @phpstan-param callable():T $callback Callback value.
	 * @param int      $attempts Attempts value.
	 * @template T
	 * @throws RuntimeException When the operation cannot be completed.
	 * @throws Throwable When the operation cannot be completed.
	 * @return T
	 */
	public function run( callable $callback, int $attempts = 3 ) {
		global $wpdb;
		$attempt = 0;

		while ( $attempt < $attempts ) {
			++$attempt;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional tables require direct, fresh database reads and writes; object caching would break locking and replay guarantees.
			$wpdb->query( 'START TRANSACTION' );

			try {
				$result = $callback();
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional tables require direct, fresh database reads and writes; object caching would break locking and replay guarantees.
				if ( false === $wpdb->query( 'COMMIT' ) ) {
					throw new RuntimeException( 'The database transaction could not be committed.' );
				}
				return $result;
			} catch ( Throwable $error ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional tables require direct, fresh database reads and writes; object caching would break locking and replay guarantees.
				$wpdb->query( 'ROLLBACK' );
				$deadlock = false !== stripos( (string) $wpdb->last_error, 'deadlock' )
					|| false !== stripos( (string) $wpdb->last_error, 'serialization' );
				if ( ! $deadlock || $attempt >= $attempts ) {
					throw $error;
				}
				usleep( random_int( 20000, 60000 ) );
			}
		}

		throw new RuntimeException( 'The database transaction failed.' );
	}
}
