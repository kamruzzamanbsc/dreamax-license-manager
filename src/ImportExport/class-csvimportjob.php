<?php
/**
 * Defines the CsvImportJob class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\ImportExport;

use RuntimeException;
use Throwable;

/**
 * Owns private temporary files and resumable CSV import batches.
 */
final class CsvImportJob {
	private const OPTION_PREFIX = 'dreamax_lm_csv_job_';
	private const LOCK_SUFFIX   = '_lock';
	private const GROUP         = 'dreamax-license-manager';
	private const RETENTION     = HOUR_IN_SECONDS;
	private const LOCK_TTL      = 15 * MINUTE_IN_SECONDS;

	/**
	 * Registers queue callbacks.
	 */
	public function register(): void {
		add_action( 'dreamax_lm_process_csv_import', array( $this, 'process' ), 10, 1 );
		add_action( 'dreamax_lm_cleanup_csv_job', array( $this, 'cleanup' ), 10, 1 );
	}

	/**
	 * Persists a new job and schedules its first batch.
	 *
	 * @param string $uploaded_path Validated PHP upload path.
	 * @param array  $configuration Validated import configuration.
	 * @phpstan-param array<string,mixed> $configuration
	 * @throws RuntimeException When private job persistence fails.
	 */
	public function create( string $uploaded_path, array $configuration ): string {
		$path = PrivateTempFile::create( 'dreamax-lm-import.csv' );
		if ( ! is_uploaded_file( $uploaded_path ) ) {
			wp_delete_file( $path );
			throw new RuntimeException( 'The uploaded import file is invalid.' );
		}
		$filesystem = new \WP_Filesystem_Direct( false );
		if ( ! $filesystem->copy( $uploaded_path, $path, true, 0600 ) ) {
			wp_delete_file( $path );
			throw new RuntimeException( 'A private temporary import file could not be created.' );
		}
		$token = bin2hex( random_bytes( 16 ) );
		$state = array(
			'owner'         => get_current_user_id(),
			'path'          => $path,
			'report_path'   => '',
			'configuration' => $configuration,
			'offset'        => 0,
			'row'           => 1,
			'valid'         => 0,
			'skipped'       => 0,
			'errors'        => array(),
			'status'        => 'queued',
			'created_at'    => time(),
			'updated_at'    => time(),
		);
		if ( ! add_option( self::OPTION_PREFIX . $token, $state, '', false ) ) {
			wp_delete_file( $path );
			throw new RuntimeException( 'The import job could not be created.' );
		}
		$this->schedule( $token );
		wp_schedule_single_event( time() + self::RETENTION, 'dreamax_lm_cleanup_csv_job', array( $token ) );
		return $token;
	}

	/**
	 * Persists a short-lived report for a synchronous import.
	 *
	 * @param list<array{0:int,1:string}> $errors Row errors.
	 * @param int                         $valid Valid row count.
	 * @param int                         $skipped Skipped duplicate count.
	 * @param bool                        $dry_run Whether this was a preview.
	 * @throws RuntimeException When private report persistence fails.
	 */
	public function create_report_job( array $errors, int $valid, int $skipped, bool $dry_run ): string {
		$token = bin2hex( random_bytes( 16 ) );
		$state = array(
			'owner'         => get_current_user_id(),
			'path'          => '',
			'report_path'   => $this->write_report( $errors ),
			'configuration' => array( 'dry_run' => $dry_run ),
			'row'           => $valid + $skipped + count( $errors ) + 1,
			'valid'         => $valid,
			'skipped'       => $skipped,
			'errors'        => $errors,
			'status'        => 'complete',
			'created_at'    => time(),
			'updated_at'    => time(),
		);
		if ( ! add_option( self::OPTION_PREFIX . $token, $state, '', false ) ) {
			if ( '' !== $state['report_path'] ) {
				wp_delete_file( $state['report_path'] );
			}
			throw new RuntimeException( 'The import report could not be created.' );
		}
		wp_schedule_single_event( time() + self::RETENTION, 'dreamax_lm_cleanup_csv_job', array( $token ) );
		return $token;
	}

	/**
	 * Runs one bounded batch and reschedules incomplete work.
	 *
	 * @param string $token Opaque job token.
	 */
	public function process( string $token ): void {
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/D', $token ) ) {
			return;
		}
		$lock_owner = $this->acquire_lock( $token );
		if ( false === $lock_owner ) {
			$this->schedule( $token, 15 );
			return;
		}
		try {
			$this->process_locked( $token );
		} finally {
			$this->release_lock( $token, $lock_owner );
		}
	}

	/**
	 * Runs one batch while the token-specific worker lock is owned.
	 *
	 * @param string $token Opaque job token.
	 */
	private function process_locked( string $token ): void {
		$state = $this->get( $token );
		if ( null === $state || ! in_array( (string) $state['status'], array( 'queued', 'processing' ), true ) ) {
			return;
		}
		$state['status']     = 'processing';
		$state['updated_at'] = time();
		update_option( self::OPTION_PREFIX . $token, $state, false );

		try {
			$result                         = ( new CsvImportProcessor() )->process(
				(string) $state['path'],
				(array) $state['configuration'],
				(int) $state['offset'],
				(int) $state['row']
			);
			$state['offset']                = $result['offset'];
			$state['row']                   = $result['row'];
			$state['valid']                 = (int) $state['valid'] + $result['valid'];
			$state['skipped']               = (int) $state['skipped'] + $result['skipped'];
			$state['errors']                = array_slice( array_merge( (array) $state['errors'], $result['errors'] ), 0, CsvImportProcessor::MAX_ROWS );
			$state['configuration']['seen'] = $result['seen'];
			$state['updated_at']            = time();
			if ( $result['complete'] ) {
				$state['status'] = 'complete';
				wp_delete_file( (string) $state['path'] );
				$state['path']        = '';
				$state['report_path'] = $this->write_report( (array) $state['errors'] );
			} else {
				$state['status'] = 'queued';
			}
			update_option( self::OPTION_PREFIX . $token, $state, false );
			if ( 'queued' === $state['status'] ) {
				$this->schedule( $token );
			}
		} catch ( Throwable $error ) {
			$state['status']     = 'failed';
			$state['message']    = 'The queued import stopped safely. No further rows were processed.';
			$state['updated_at'] = time();
			wp_delete_file( (string) $state['path'] );
			$state['path'] = '';
			update_option( self::OPTION_PREFIX . $token, $state, false );
		}
	}

	/**
	 * Returns an owner-scoped job state.
	 *
	 * @param string $token Opaque job token.
	 * @param int    $user_id Current user ID.
	 * @return array<string,mixed>|null
	 */
	public function for_owner( string $token, int $user_id ): ?array {
		$state = $this->get( $token );
		return null !== $state && hash_equals( (string) (int) $state['owner'], (string) $user_id ) ? $state : null;
	}

	/**
	 * Removes one job and its private files.
	 *
	 * @param string $token Opaque job token.
	 */
	public function cleanup( string $token ): void {
		$state = $this->get( $token );
		if ( null !== $state && in_array( (string) ( $state['status'] ?? '' ), array( 'queued', 'processing' ), true ) && (int) ( $state['updated_at'] ?? 0 ) > time() - self::RETENTION ) {
			wp_schedule_single_event( time() + self::RETENTION, 'dreamax_lm_cleanup_csv_job', array( $token ) );
			return;
		}
		if ( null !== $state ) {
			foreach ( array( 'path', 'report_path' ) as $field ) {
				if ( ! empty( $state[ $field ] ) && is_string( $state[ $field ] ) ) {
					wp_delete_file( $state[ $field ] );
				}
			}
		}
		delete_option( self::OPTION_PREFIX . $token );
		delete_option( self::OPTION_PREFIX . $token . self::LOCK_SUFFIX );
	}

	/**
	 * Creates a secret-free row error report in the system temporary directory.
	 *
	 * @param list<array{0:int,1:string}> $errors Row errors.
	 */
	public function write_report( array $errors ): string {
		if ( array() === $errors ) {
			return '';
		}
		try {
			$path = PrivateTempFile::create( 'dreamax-lm-import-errors.csv' );
		} catch ( RuntimeException $error ) {
			unset( $error );
			return '';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- A bounded private CSV report is assembled for an authorized download.
		$handle = fopen( $path, 'wb' );
		if ( false === $handle ) {
			wp_delete_file( $path );
			return '';
		}
		fputcsv( $handle, array( 'row', 'problem' ), ',', '"', '' );
		foreach ( $errors as $error ) {
			fputcsv( $handle, array( (int) $error[0], (string) $error[1] ), ',', '"', '' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the completed private report stream.
		fclose( $handle );
		return $path;
	}

	/**
	 * Schedules the next batch using Action Scheduler when available.
	 *
	 * @param string $token Opaque job token.
	 * @param int    $delay Delay in seconds for a contended worker retry.
	 */
	private function schedule( string $token, int $delay = 0 ): void {
		if ( $delay > 0 ) {
			wp_schedule_single_event( time() + $delay, 'dreamax_lm_process_csv_import', array( $token ) );
			return;
		}
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'dreamax_lm_process_csv_import', array( $token ), self::GROUP );
			return;
		}
		wp_schedule_single_event( time() + 5, 'dreamax_lm_process_csv_import', array( $token ) );
	}

	/**
	 * Acquires an atomic option-backed worker lock and replaces only expired locks.
	 *
	 * @param string $token Opaque job token.
	 */
	private function acquire_lock( string $token ): string|false {
		global $wpdb;
		$name       = self::OPTION_PREFIX . $token . self::LOCK_SUFFIX;
		$lock_owner = (string) ( time() + self::LOCK_TTL ) . ':' . bin2hex( random_bytes( 16 ) );
		if ( add_option( $name, $lock_owner, '', false ) ) {
			return $lock_owner;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-swap prevents two workers from replacing the same expired lock.
		$claimed = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value=%s WHERE option_name=%s AND CAST(option_value AS UNSIGNED)<%d', $wpdb->options, $lock_owner, $name, time() ) );
		if ( 1 === $claimed ) {
			wp_cache_delete( $name, 'options' );
			return $lock_owner;
		}
		return false;
	}

	/**
	 * Releases only the lease acquired by this worker.
	 *
	 * @param string $token Opaque job token.
	 * @param string $lock_owner Exact lease value.
	 */
	private function release_lock( string $token, string $lock_owner ): void {
		global $wpdb;
		$name = self::OPTION_PREFIX . $token . self::LOCK_SUFFIX;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic ownership check prevents an expired worker from releasing a successor's lease.
		$deleted = $wpdb->delete(
			$wpdb->options,
			array(
				'option_name'  => $name,
				'option_value' => $lock_owner,
			),
			array( '%s', '%s' )
		);
		if ( 1 === $deleted ) {
			wp_cache_delete( $name, 'options' );
		}
	}

	/**
	 * Reads a validated job state.
	 *
	 * @param string $token Opaque job token.
	 * @return array<string,mixed>|null
	 */
	private function get( string $token ): ?array {
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/D', $token ) ) {
			return null;
		}
		$state = get_option( self::OPTION_PREFIX . $token, null );
		return is_array( $state ) ? $state : null;
	}
}
