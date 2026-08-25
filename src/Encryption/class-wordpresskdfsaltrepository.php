<?php
/**
 * Defines the WordPressKdfSaltRepository class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Encryption;

use RuntimeException;

/**
 * Uses the current site's WordPress option table as authoritative salt storage.
 */
final class WordPressKdfSaltRepository implements KdfSaltRepository {
	private const OPTION = 'dreamax_lm_kdf_salt';

	private const IDENTIFIER_OPTION = 'dreamax_lm_master_key_id';

	private const ERROR = 'The site KDF salt storage is unavailable.';

	/**
	 * Reads the authoritative stored representation.
	 */
	public function read(): ?string {
		return $this->read_option( self::OPTION );
	}

	/**
	 * Atomically initializes a missing value without replacing a concurrent winner.
	 *
	 * @param string $encoded Canonical ASCII-safe representation.
	 * @throws RuntimeException When authoritative storage is unavailable.
	 */
	public function initialize( string $encoded ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic first initialization and authoritative read-back must bypass option caches.
		$result = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- wpdb::options is the trusted current-site option table name.
				"INSERT INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,'no') ON DUPLICATE KEY UPDATE option_name=option_name",
				self::OPTION,
				$encoded
			)
		);
		if ( false === $result ) {
			throw $this->failure();
		}

		$this->invalidate_cache();
		return 1 === $result;
	}

	/**
	 * Replaces a legacy representation with its key-equivalent encoding.
	 *
	 * @param string $encoded Canonical ASCII-safe representation.
	 * @throws RuntimeException When authoritative storage is unavailable.
	 */
	public function replace( string $encoded ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Legacy migration is verified with an authoritative read-back.
		$result = $wpdb->update(
			$wpdb->options,
			array(
				'option_value' => $encoded,
				'autoload'     => 'no',
			),
			array( 'option_name' => self::OPTION ),
			array( '%s', '%s' ),
			array( '%s' )
		);
		if ( false === $result ) {
			throw $this->failure();
		}

		$this->invalidate_cache();
		return $result > 0;
	}

	/**
	 * Checks the non-secret identifier without using cached option state.
	 *
	 * @param string $identifier Non-secret root identifier.
	 */
	public function identifier_matches( string $identifier ): bool {
		$stored = $this->read_option( self::IDENTIFIER_OPTION );
		return null !== $stored && hash_equals( $stored, $identifier );
	}

	/**
	 * Checks all records whose identities or ciphertext depend on the KDF salt.
	 *
	 * @throws RuntimeException When authoritative storage is unavailable.
	 */
	public function has_protected_data(): bool {
		global $wpdb;

		foreach ( array( 'licenses', 'activations', 'idempotency', 'rate_limits', 'guest_claims', 'order_owners' ) as $suffix ) {
			$table = $wpdb->prefix . 'dreamax_lm_' . $suffix;

			$wpdb->last_error = '';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted plugin table names require fresh fail-closed existence checks.
			$exists = $wpdb->get_var( "SELECT 1 FROM {$table} LIMIT 1" );
			if ( '' !== $this->last_database_error() ) {
				throw $this->failure();
			}
			if ( null !== $exists ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reads one option directly from the current site's authoritative table.
	 *
	 * @param string $option Option name.
	 * @throws RuntimeException When authoritative storage is unavailable.
	 */
	private function read_option( string $option ): ?string {
		global $wpdb;

		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Salt and identifier verification must bypass stale option caches.
		$value = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- wpdb::options is the trusted current-site option table name.
				"SELECT option_value FROM {$wpdb->options} WHERE option_name=%s LIMIT 1",
				$option
			)
		);
		if ( '' !== $this->last_database_error() ) {
			throw $this->failure();
		}

		if ( null === $value ) {
			return null;
		}

		$value = maybe_unserialize( $value );
		if ( ! is_string( $value ) ) {
			throw $this->failure();
		}

		return $value;
	}

	/**
	 * Invalidates every WordPress option-cache location that can hide a write.
	 */
	private function invalidate_cache(): void {
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Returns the latest database error without exposing it to callers.
	 */
	private function last_database_error(): string {
		global $wpdb;

		return (string) $wpdb->last_error;
	}

	/**
	 * Creates the fixed non-sensitive storage failure.
	 */
	private function failure(): RuntimeException {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed internal text contains no stored value and is never rendered directly.
		return new RuntimeException( self::ERROR );
	}
}
