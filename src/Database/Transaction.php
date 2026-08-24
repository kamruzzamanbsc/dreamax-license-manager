<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Database;

use RuntimeException;
use Throwable;

final class Transaction {
	/** @template T @param callable():T $callback @return T */
	public function run( callable $callback, int $attempts = 3 ) {
		global $wpdb;
		$attempt = 0;

		while ( $attempt < $attempts ) {
			++$attempt;
			$wpdb->query( 'START TRANSACTION' );

			try {
				$result = $callback();
				if ( false === $wpdb->query( 'COMMIT' ) ) {
					throw new RuntimeException( 'The database transaction could not be committed.' );
				}
				return $result;
			} catch ( Throwable $error ) {
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
