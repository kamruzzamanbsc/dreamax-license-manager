<?php
/**
 * Defines the CsvController class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\ImportExport;

use Dreamax\LicenseManager\Events\AuditEventCatalog;
use Dreamax\LicenseManager\Events\EventRepository;
use Dreamax\LicenseManager\Licenses\KeyNormalizer;
use Dreamax\LicenseManager\Licenses\LicenseRepository;
use Dreamax\LicenseManager\Licenses\LicenseService;
use Dreamax\LicenseManager\Support\Capabilities;
use RuntimeException;
use Throwable;

/**
 * Handles Csv controller operations.
 */
final class CsvController {
	/**
	 * Handles the register operation.
	 */
	public function register(): void {
		add_action( 'admin_post_dreamax_lm_import_csv', array( $this, 'import' ) );
		add_action( 'admin_post_dreamax_lm_export_csv', array( $this, 'export' ) );
	}

	/**
	 * Handles the import operation.
	 *
	 * @throws RuntimeException When the operation cannot be completed.
	 */
	public function import(): void {
		$this->authorize( Capabilities::MANAGE );
		check_admin_referer( 'dreamax_lm_import_csv' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- PHP supplies this server-side temporary path; changing it would invalidate is_uploaded_file().
		$temporary_file = isset( $_FILES['csv']['tmp_name'] ) && is_string( $_FILES['csv']['tmp_name'] ) ? $_FILES['csv']['tmp_name'] : '';
		if ( '' === $temporary_file || ! isset( $_FILES['csv']['size'] ) || ! is_uploaded_file( $temporary_file ) ) {
			wp_die( esc_html__( 'Choose a CSV file to import.', 'dreamax-license-manager' ) );
		}
		if ( (int) $_FILES['csv']['size'] > 5 * MB_IN_BYTES ) {
			wp_die( esc_html__( 'The import file is larger than 5 MiB.', 'dreamax-license-manager' ) );
		}
		$dry_run = isset( $_POST['dry_run'] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Uploaded CSV streams require native sequential stream reads.
		$handle = fopen( $temporary_file, 'rb' );
		if ( false === $handle ) {
			wp_die( esc_html__( 'The import file could not be opened.', 'dreamax-license-manager' ) );
		}
		$headers = fgetcsv( $handle );
		if ( ! is_array( $headers ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the native CSV stream opened above.
			fclose( $handle );
			wp_die( esc_html__( 'The import file has no header row.', 'dreamax-license-manager' ) );
		}
		$headers[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $headers[0] ) ?? (string) $headers[0];
		$headers    = array_map( static fn( $value ): string => sanitize_key( (string) $value ), $headers );
		if ( ! in_array( 'license_key', $headers, true ) || ! in_array( 'product_public_id', $headers, true ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the native CSV stream opened above.
			fclose( $handle );
			wp_die( esc_html__( 'CSV headers must include license_key and product_public_id.', 'dreamax-license-manager' ) );
		}

		$service = new LicenseService();
		$errors  = array();
		$valid   = 0;
		$row_no  = 1;
		while ( true ) {
			$values = fgetcsv( $handle );
			if ( false === $values ) {
				break;
			}
			++$row_no;
			if ( $row_no > 10001 ) {
				$errors[] = array( $row_no, __( 'Synchronous imports are limited to 10,000 rows.', 'dreamax-license-manager' ) );
				break;
			}
			$values = array_pad( $values, count( $headers ), '' );
			$row    = array_combine( $headers, array_slice( $values, 0, count( $headers ) ) );
			try {
				$key     = (string) $row['license_key'];
				$product = sanitize_text_field( (string) $row['product_public_id'] );
				if ( '' === $key || ! preg_match( '/^prd_[A-Za-z0-9_-]{22}$/D', $product ) ) {
					throw new RuntimeException( 'The key or product public ID is invalid.' );
				}
				$profile   = (string) ( $row['normalization_profile'] ?? KeyNormalizer::IMPORTED );
				$separator = '' === (string) ( $row['separator'] ?? '' ) ? null : substr( (string) $row['separator'], 0, 1 );
				$limit_raw = (string) ( $row['activation_limit'] ?? '' );
				$expires   = sanitize_text_field( (string) ( $row['expires_at'] ?? '' ) );
				$attrs     = array(
					'product_public_id' => $product,
					'lifecycle_status'  => 'available',
					'activation_limit'  => '' === $limit_raw ? null : max( 0, (int) $limit_raw ),
					'expires_at'        => '' === $expires ? null : gmdate( 'Y-m-d H:i:s', strtotime( $expires . ' UTC' ) ),
					'actor_type'        => 'administrator',
					'actor_id'          => get_current_user_id(),
					'source'            => 'csv_import',
				);
				if ( ! $dry_run ) {
					$service->import( $key, $profile, $separator, $attrs );
				}
				++$valid;
			} catch ( Throwable $error ) {
				$errors[] = array( $row_no, $error->getMessage() );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the native CSV stream opened above.
		fclose( $handle );
		nocache_headers();
		/* translators: %d: Number of valid CSV rows. */
		echo '<!doctype html><meta charset="utf-8"><title>' . esc_html__( 'Import result', 'dreamax-license-manager' ) . '</title><h1>' . esc_html( $dry_run ? __( 'Import preview', 'dreamax-license-manager' ) : __( 'Import complete', 'dreamax-license-manager' ) ) . '</h1><p>' . esc_html( sprintf( __( '%d valid rows.', 'dreamax-license-manager' ), $valid ) ) . '</p>';
		if ( $errors ) {
			echo '<h2>' . esc_html__( 'Rows needing attention', 'dreamax-license-manager' ) . '</h2><table><tr><th>' . esc_html__( 'Row', 'dreamax-license-manager' ) . '</th><th>' . esc_html__( 'Problem', 'dreamax-license-manager' ) . '</th></tr>';
			foreach ( $errors as $error ) {
				echo '<tr><td>' . esc_html( (string) $error[0] ) . '</td><td>' . esc_html( (string) $error[1] ) . '</td></tr>';
			}
			echo '</table>';
		}
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=dreamax-license-manager-transfer' ) ) . '">' . esc_html__( 'Back to Import / Export', 'dreamax-license-manager' ) . '</a></p>';
		exit;
	}

	/**
	 * Handles the export operation.
	 */
	public function export(): void {
		global $wpdb;
		$this->authorize( Capabilities::MANAGE );
		check_admin_referer( 'dreamax_lm_export_csv' );
		$full = isset( $_POST['full_keys'] ) && current_user_can( Capabilities::EXPORT );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional tables require direct, fresh database reads and writes; object caching would break locking and replay guarantees.
		$row_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_licenses" );
		( new EventRepository() )->append(
			AuditEventCatalog::LICENSE_EXPORTED,
			null,
			'administrator',
			get_current_user_id(),
			null,
			array(
				'full_keys' => $full,
				'row_count' => $row_count,
			),
			AuditEventCatalog::SCHEMA_V1
		);
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="dreamax-licenses-' . gmdate( 'Ymd-His' ) . '.csv"' );
		$output = fopen( 'php://output', 'wb' );
		if ( false === $output ) {
			wp_die( esc_html__( 'The export stream could not be opened.', 'dreamax-license-manager' ) );
		}
		fputcsv( $output, array( 'public_id', 'license_key', 'product_public_id', 'lifecycle_status', 'activation_limit', 'expires_at', 'order_id', 'customer_id' ) );
		$repository = new LicenseRepository();
		$last_id    = 0;
		$batch_size = 250;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Keyset pagination keeps the authorized export bounded while reading fresh plugin-owned data.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}dreamax_lm_licenses WHERE id>%d ORDER BY id LIMIT %d", $last_id, $batch_size ), ARRAY_A );
			$rows = is_array( $rows ) ? $rows : array();
			foreach ( $rows as $row ) {
				$key = $repository->decrypt_key( $row );
				if ( ! $full ) {
					$key = strlen( $key ) > 8 ? substr( $key, 0, 4 ) . '********' . substr( $key, -4 ) : '********';
				}
				fputcsv( $output, array_map( array( $this, 'csv_safe' ), array( $row['public_id'], $key, $row['product_public_id'], $row['lifecycle_status'], $row['activation_limit'], $row['expires_at'], $row['order_id'], $row['customer_id'] ) ) );
				$last_id = (int) $row['id'];
			}
			$batch_count = count( $rows );
		} while ( $batch_size === $batch_count );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the php://output CSV stream.
		fclose( $output );
		exit;
	}

	/**
	 * Handles the csv safe operation.
	 *
	 * @param mixed $value Value value.
	 */
	public function csv_safe( $value ): string {
		$value = (string) $value;
		return preg_match( '/^[=+\-@]/', $value ) ? "'" . $value : $value;
	}

	/**
	 * Handles the authorize operation.
	 *
	 * @param string $capability Capability value.
	 */
	private function authorize( string $capability ): void {
		if ( ! current_user_can( $capability ) ) {
			wp_die( esc_html__( 'You are not allowed to perform this action.', 'dreamax-license-manager' ) );
		}
	}
}
