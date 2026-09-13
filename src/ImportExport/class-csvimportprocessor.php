<?php
/**
 * Defines the CsvImportProcessor class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\ImportExport;

use Dreamax\LicenseManager\Encryption\Crypto;
use Dreamax\LicenseManager\Licenses\KeyNormalizer;
use Dreamax\LicenseManager\Licenses\LicenseRepository;
use Dreamax\LicenseManager\Licenses\LicenseService;
use RuntimeException;
use Throwable;

/**
 * Processes bounded CSV batches for synchronous and queued imports.
 */
final class CsvImportProcessor {
	public const BATCH_SIZE = 250;
	public const MAX_ROWS   = 10000;

	/**
	 * Processes one batch from a persisted byte offset.
	 *
	 * @param string $path Private temporary CSV path.
	 * @param array  $configuration Validated import configuration.
	 * @phpstan-param array<string,mixed> $configuration
	 * @param int    $offset Byte offset at which processing resumes.
	 * @param int    $row_no Last processed CSV row number.
	 * @param int    $limit Maximum data rows in this batch.
	 * @throws RuntimeException When the file or configured format is invalid.
	 * @return array{offset:int,row:int,valid:int,skipped:int,errors:list<array{0:int,1:string}>,seen:list<string>,complete:bool}
	 */
	public function process( string $path, array $configuration, int $offset, int $row_no, int $limit = self::BATCH_SIZE ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Sequential CSV parsing requires a native seekable stream.
		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			throw new RuntimeException( 'The import file could not be opened.' );
		}

		$delimiter = (string) $configuration['delimiter'];
		$encoding  = (string) $configuration['encoding'];
		$mapping   = is_array( $configuration['mapping'] ?? null ) ? $configuration['mapping'] : array();
		$dry_run   = ! empty( $configuration['dry_run'] );
		$duplicate = (string) ( $configuration['duplicate'] ?? 'error' );
		$actor_id  = (int) ( $configuration['actor_id'] ?? 0 );

		if ( 0 === $offset ) {
			$raw_headers = fgetcsv( $handle, null, $delimiter, '"', '' );
			if ( ! is_array( $raw_headers ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the private CSV stream on failure.
				fclose( $handle );
				throw new RuntimeException( 'The import file has no header row.' );
			}
			$headers = array_map(
				static fn( $value ): string => CsvFormat::heading( CsvFormat::decode( (string) $value, $encoding ) ),
				$raw_headers
			);
			$this->validate_mapping( $headers, $mapping );
			$offset = (int) ftell( $handle );
		} else {
			if ( 0 !== fseek( $handle, $offset ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the private CSV stream on failure.
				fclose( $handle );
				throw new RuntimeException( 'The import position could not be restored.' );
			}
			$headers = is_array( $configuration['headers'] ?? null ) ? array_map( 'strval', $configuration['headers'] ) : array();
			$this->validate_mapping( $headers, $mapping );
		}

		$service    = new LicenseService();
		$repository = new LicenseRepository();
		$normalizer = new KeyNormalizer();
		$crypto     = new Crypto();
		$seen       = is_array( $configuration['seen'] ?? null ) ? array_map( 'strval', $configuration['seen'] ) : array();
		$valid      = 0;
		$skipped    = 0;
		$errors     = array();
		$processed  = 0;
		$complete   = false;
		while ( $processed < max( 1, $limit ) ) {
			$values = fgetcsv( $handle, null, $delimiter, '"', '' );
			if ( false === $values ) {
				$complete = true;
				break;
			}
			++$row_no;
			++$processed;
			if ( $row_no > self::MAX_ROWS + 1 ) {
				$errors[] = array( $row_no, 'Imports are limited to 10,000 rows.' );
				$complete = true;
				break;
			}

			try {
				$values  = array_map( static fn( $value ): string => CsvFormat::decode( (string) $value, $encoding ), $values );
				$values  = array_pad( $values, count( $headers ), '' );
				$source  = array_combine( $headers, array_slice( $values, 0, count( $headers ) ) );
				$row     = $this->mapped_row( $source, $mapping );
				$key     = (string) $row['license_key'];
				$product = sanitize_text_field( (string) $row['product_public_id'] );
				if ( '' === $key || ! preg_match( '/^prd_[A-Za-z0-9_-]{22}$/D', $product ) ) {
					throw new RuntimeException( 'The key or product public ID is invalid.' );
				}

				$profile     = '' !== (string) $row['normalization_profile'] ? (string) $row['normalization_profile'] : KeyNormalizer::IMPORTED;
				$separator   = '' === (string) $row['separator'] ? null : substr( (string) $row['separator'], 0, 1 );
				$canonical   = $normalizer->normalize( $key, $profile, $separator );
				$fingerprint = bin2hex( $crypto->fingerprint( $canonical ) );
				if ( in_array( $fingerprint, $seen, true ) || null !== $repository->find_presented_any_product( $key ) ) {
					if ( 'skip' === $duplicate ) {
						++$skipped;
						continue;
					}
					throw new RuntimeException( 'A license with this key already exists.' );
				}
				$limit_raw = (string) $row['activation_limit'];
				$expires   = $this->expiry( (string) $row['expires_at'] );
				if ( ! $dry_run ) {
					$service->import(
						$key,
						$profile,
						$separator,
						array(
							'product_public_id' => $product,
							'lifecycle_status'  => 'available',
							'activation_limit'  => '' === $limit_raw ? null : max( 0, (int) $limit_raw ),
							'expires_at'        => $expires,
							'actor_type'        => 'administrator',
							'actor_id'          => $actor_id,
							'source'            => 'csv_import',
						)
					);
				}
				$seen[] = $fingerprint;
				++$valid;
			} catch ( Throwable $error ) {
				$errors[] = array( $row_no, $this->safe_error( $error ) );
			}
		}

		$new_offset = (int) ftell( $handle );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the completed private CSV stream.
		fclose( $handle );
		return array(
			'offset'   => $new_offset,
			'row'      => $row_no,
			'valid'    => $valid,
			'skipped'  => $skipped,
			'errors'   => $errors,
			'seen'     => $seen,
			'complete' => $complete,
		);
	}

	/**
	 * Reads and normalizes headings without retaining row data.
	 *
	 * @param string $path Private temporary CSV path.
	 * @param string $delimiter One-character delimiter.
	 * @param string $encoding Allowlisted source encoding.
	 * @throws RuntimeException When the headings cannot be read.
	 * @return list<string>
	 */
	public function headers( string $path, string $delimiter, string $encoding ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Sequential CSV parsing requires a native stream.
		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			throw new RuntimeException( 'The import file could not be opened.' );
		}
		$values = fgetcsv( $handle, null, $delimiter, '"', '' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the private heading stream.
		fclose( $handle );
		if ( ! is_array( $values ) ) {
			throw new RuntimeException( 'The import file has no header row.' );
		}
		return array_map( static fn( $value ): string => CsvFormat::heading( CsvFormat::decode( (string) $value, $encoding ) ), $values );
	}

	/**
	 * Validates that required mapped headings exist.
	 *
	 * @param array<int,string>   $headers Normalized headings.
	 * @param array<string,mixed> $mapping Canonical-to-source mapping.
	 * @throws RuntimeException When required mapping is incomplete.
	 */
	private function validate_mapping( array $headers, array $mapping ): void {
		foreach ( array( 'license_key', 'product_public_id' ) as $required ) {
			$source = (string) ( $mapping[ $required ] ?? '' );
			if ( '' === $source || ! in_array( $source, $headers, true ) ) {
				throw new RuntimeException( 'The required CSV field mapping is incomplete.' );
			}
		}
	}

	/**
	 * Maps source cells onto canonical import fields.
	 *
	 * @param array<string,string> $source Source row.
	 * @param array<string,mixed>  $mapping Canonical-to-source mapping.
	 * @return array<string,string>
	 */
	private function mapped_row( array $source, array $mapping ): array {
		$result = array();
		foreach ( array( 'license_key', 'product_public_id', 'activation_limit', 'expires_at', 'normalization_profile', 'separator' ) as $field ) {
			$heading          = (string) ( $mapping[ $field ] ?? '' );
			$result[ $field ] = '' !== $heading && isset( $source[ $heading ] ) ? (string) $source[ $heading ] : '';
		}
		return $result;
	}

	/**
	 * Converts a supplied date to a UTC database value.
	 *
	 * @param string $value Merchant-supplied expiry value.
	 * @throws RuntimeException When the date is invalid.
	 */
	private function expiry( string $value ): ?string {
		$value = sanitize_text_field( $value );
		if ( '' === $value ) {
			return null;
		}
		$timestamp = strtotime( $value . ( preg_match( '/(?:Z|[+-]\d\d:?\d\d)$/i', $value ) ? '' : ' UTC' ) );
		if ( false === $timestamp ) {
			throw new RuntimeException( 'The expiry value is invalid.' );
		}
		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Keeps row reports useful without exposing database or filesystem details.
	 *
	 * @param Throwable $error Original row error.
	 */
	private function safe_error( Throwable $error ): string {
		$message = $error->getMessage();
		if ( preg_match( '/(?:duplicate|already exists)/i', $message ) ) {
			return 'A license with this key already exists.';
		}
		$allowed = array(
			'The key or product public ID is invalid.',
			'The expiry value is invalid.',
			'The CSV contains invalid UTF-8.',
			'The row does not match the CSV headings.',
		);
		return in_array( $message, $allowed, true ) ? $message : 'The license row could not be imported.';
	}
}
