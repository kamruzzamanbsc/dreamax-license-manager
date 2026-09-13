<?php
/**
 * Defines the CsvController class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\ImportExport;

use Dreamax\LicenseManager\Encryption\Crypto;
use Dreamax\LicenseManager\Events\AuditEventCatalog;
use Dreamax\LicenseManager\Events\EventRepository;
use Dreamax\LicenseManager\Licenses\LicenseRepository;
use Dreamax\LicenseManager\Support\Capabilities;
use Throwable;

/**
 * Handles protected CSV import, export, templates, and reports.
 */
final class CsvController {
	private const ASYNC_FILE_BYTES = 262144;

	/** Registers administration actions. */
	public function register(): void {
		add_action( 'admin_post_dreamax_lm_import_csv', array( $this, 'import' ) );
		add_action( 'admin_post_dreamax_lm_export_csv', array( $this, 'export' ) );
		add_action( 'admin_post_dreamax_lm_csv_template', array( $this, 'template' ) );
		add_action( 'admin_post_dreamax_lm_csv_error_report', array( $this, 'error_report' ) );
		( new CsvImportJob() )->register();
	}

	/** Validates and imports an uploaded CSV, queuing larger files in batches. */
	public function import(): void {
		$this->authorize( Capabilities::MANAGE );
		check_admin_referer( 'dreamax_lm_import_csv' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- PHP supplies the server-side path checked by is_uploaded_file().
		$temporary_file = isset( $_FILES['csv']['tmp_name'] ) && is_string( $_FILES['csv']['tmp_name'] ) ? $_FILES['csv']['tmp_name'] : '';
		$file_size      = isset( $_FILES['csv']['size'] ) ? (int) $_FILES['csv']['size'] : 0;
		if ( '' === $temporary_file || $file_size < 1 || ! is_uploaded_file( $temporary_file ) ) {
			wp_die( esc_html__( 'Choose a CSV file to import.', 'dreamax-license-manager' ) );
		}
		if ( $file_size > 5 * MB_IN_BYTES ) {
			wp_die( esc_html__( 'The import file is larger than 5 MiB.', 'dreamax-license-manager' ) );
		}

		$configuration             = $this->import_configuration();
		$configuration['actor_id'] = get_current_user_id();
		$processor                 = new CsvImportProcessor();
		try {
			$configuration['headers'] = $processor->headers( $temporary_file, (string) $configuration['delimiter'], (string) $configuration['encoding'] );
			if ( $file_size > self::ASYNC_FILE_BYTES && empty( $configuration['dry_run'] ) ) {
				$token = ( new CsvImportJob() )->create( $temporary_file, $configuration );
				wp_safe_redirect( add_query_arg( 'import_job', $token, admin_url( 'admin.php?page=dreamax-license-manager-transfer' ) ) );
				exit;
			}

			$result = $processor->process( $temporary_file, $configuration, 0, 1, CsvImportProcessor::MAX_ROWS + 1 );
			$token  = ( new CsvImportJob() )->create_report_job( $result['errors'], $result['valid'], $result['skipped'], ! empty( $configuration['dry_run'] ) );
			$this->render_import_result( $token, $result, ! empty( $configuration['dry_run'] ) );
		} catch ( Throwable $error ) {
			wp_die( esc_html( $error->getMessage() ), esc_html__( 'CSV import unavailable', 'dreamax-license-manager' ), array( 'response' => 400 ) );
		}
	}

	/** Exports a filtered license inventory or privacy-conscious activation list. */
	public function export(): void {
		$this->authorize( Capabilities::MANAGE );
		check_admin_referer( 'dreamax_lm_export_csv' );
		$type = isset( $_POST['export_type'] ) && 'activations' === sanitize_key( wp_unslash( (string) $_POST['export_type'] ) ) ? 'activations' : 'licenses';
		$full = 'licenses' === $type && isset( $_POST['full_keys'] ) && current_user_can( Capabilities::EXPORT );
		$this->stream_export( $type, $full, $this->export_filters( $type ), array() );
	}

	/**
	 * Downloads only the selected license rows, always with masked keys.
	 *
	 * @param array $ids Public IDs already selected in the inventory.
	 * @phpstan-param list<string> $ids
	 */
	public function export_selected( array $ids ): void {
		$this->authorize( Capabilities::MANAGE );
		check_admin_referer( 'dreamax_lm_bulk_lifecycle' );
		$ids = array_values( array_unique( $ids ) );
		if ( array() === $ids || count( $ids ) > 100 ) {
			wp_die( esc_html__( 'Select between 1 and 100 valid licenses.', 'dreamax-license-manager' ) );
		}
		foreach ( $ids as $id ) {
			if ( ! is_string( $id ) || 1 !== preg_match( '/^lic_[A-Za-z0-9_-]{22}$/D', $id ) ) {
				wp_die( esc_html__( 'A selected license ID is invalid.', 'dreamax-license-manager' ) );
			}
		}
		$this->stream_export(
			'licenses',
			false,
			array(
				'status'   => '',
				'product'  => '',
				'customer' => 0,
				'order'    => 0,
				'expiry'   => '',
			),
			$ids
		);
	}

	/**
	 * Streams a validated and fully assembled export after its audit write.
	 *
	 * @param string              $type Dataset type.
	 * @param bool                $full Whether the dedicated export capability permits unmasked keys.
	 * @param array<string,mixed> $filters Existing read-only export filters.
	 * @param array               $selected Selected public IDs, or empty for a regular filtered export.
	 * @phpstan-param list<string> $selected
	 */
	private function stream_export( string $type, bool $full, array $filters, array $selected ): void {
		global $wpdb;
		if ( 'licenses' === $type && ! ( new Crypto() )->ready() ) {
			wp_die( esc_html__( 'Secure key access is temporarily unavailable. Restore the configured master key before exporting licenses.', 'dreamax-license-manager' ), esc_html__( 'License export unavailable', 'dreamax-license-manager' ), array( 'response' => 503 ) );
		}

		$where = $this->export_where( $filters, $type, $selected );
		try {
			$path = PrivateTempFile::create( 'dreamax-license-export.csv' );
		} catch ( Throwable $error ) {
			unset( $error );
			wp_die( esc_html__( 'A secure temporary export file could not be created.', 'dreamax-license-manager' ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- A bounded private temporary export is required.
		$output = fopen( $path, 'wb' );
		if ( false === $output ) {
			wp_delete_file( $path );
			wp_die( esc_html__( 'The temporary export file could not be opened.', 'dreamax-license-manager' ) );
		}

		$row_count = 0;
		try {
			$repository = new LicenseRepository();
			$last_id    = 0;
			$batch_size = 250;
			$headings   = 'licenses' === $type
				? array( 'public_id', 'license_key', 'product_public_id', 'lifecycle_status', 'activation_limit', 'expires_at', 'order_id', 'customer_id' )
				: array( 'activation_public_id', 'license_public_id', 'product_public_id', 'customer_id', 'instance_label', 'status', 'first_activated_at', 'activated_at', 'deactivated_at', 'last_seen_at' );
			fputcsv( $output, $headings, ',', '"', '' );
			do {
				if ( 'licenses' === $type ) {
					$sql = "SELECT l.* FROM {$wpdb->prefix}dreamax_lm_licenses l WHERE l.id>%d {$where['sql']} ORDER BY l.id LIMIT %d";
				} else {
					$sql = "SELECT a.*,l.public_id AS license_public_id,l.product_public_id,l.customer_id FROM {$wpdb->prefix}dreamax_lm_activations a INNER JOIN {$wpdb->prefix}dreamax_lm_licenses l ON l.id=a.license_id WHERE a.id>%d {$where['sql']} ORDER BY a.id LIMIT %d";
				}
				$args = array_merge( array( $last_id ), $where['args'], array( $batch_size ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- SQL fragments contain only fixed identifiers and placeholders; values are prepared here.
				$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
				$rows = is_array( $rows ) ? $rows : array();
				foreach ( $rows as $row ) {
					if ( 'licenses' === $type ) {
						$key = $repository->decrypt_key( $row );
						if ( ! $full ) {
							$key = strlen( $key ) > 8 ? substr( $key, 0, 4 ) . '********' . substr( $key, -4 ) : '********';
						}
						$values = array( $row['public_id'], $key, $row['product_public_id'], $row['lifecycle_status'], $row['activation_limit'], $row['expires_at'], $row['order_id'], $row['customer_id'] );
					} else {
						$values = array( $row['public_id'], $row['license_public_id'], $row['product_public_id'], $row['customer_id'], $row['instance_label'], $row['status'], $row['first_activated_at'], $row['activated_at'], $row['deactivated_at'], $row['last_seen_at'] );
					}
					fputcsv( $output, array_map( array( $this, 'csv_safe' ), $values ), ',', '"', '' );
					$last_id = (int) $row['id'];
					++$row_count;
				}
				$batch_count = count( $rows );
			} while ( $batch_size === $batch_count );
		} catch ( Throwable $error ) {
			unset( $error );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the private export stream before cleanup.
			fclose( $output );
			wp_delete_file( $path );
			wp_die( esc_html__( 'The export stopped safely and no download was created.', 'dreamax-license-manager' ), esc_html__( 'Export unavailable', 'dreamax-license-manager' ), array( 'response' => 503 ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the completed private export stream.
		fclose( $output );

		try {
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
		} catch ( Throwable $error ) {
			unset( $error );
			wp_delete_file( $path );
			wp_die( esc_html__( 'The export could not be audited; no download was created.', 'dreamax-license-manager' ), esc_html__( 'Export unavailable', 'dreamax-license-manager' ), array( 'response' => 503 ) );
		}
		$this->download_headers( 'dreamax-' . $type . '-' . gmdate( 'Ymd-His' ) . '.csv' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streams a completed private export.
		readfile( $path );
		wp_delete_file( $path );
		exit;
	}

	/** Downloads a ready-to-map UTF-8 template. */
	public function template(): void {
		$this->authorize( Capabilities::MANAGE );
		check_admin_referer( 'dreamax_lm_csv_template' );
		$this->download_headers( 'dreamax-license-import-template.csv' );
		$output = fopen( 'php://output', 'wb' );
		if ( false !== $output ) {
			fputcsv( $output, array( 'license_key', 'product_public_id', 'activation_limit', 'expires_at', 'normalization_profile', 'separator' ), ',', '"', '' );
			fputcsv( $output, array( 'EXAMPLE-KEY-REMOVE-ME', 'prd_REPLACE_WITH_PUBLIC_ID', '1', '2030-12-31 23:59:59', 'import-exact-v1', '' ), ',', '"', '' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the direct response stream.
			fclose( $output );
		}
		exit;
	}

	/** Downloads an owner-scoped, secret-free row error report. */
	public function error_report(): void {
		$this->authorize( Capabilities::MANAGE );
		$token = isset( $_GET['job'] ) ? sanitize_key( wp_unslash( (string) $_GET['job'] ) ) : '';
		check_admin_referer( 'dreamax_lm_csv_error_report_' . $token );
		$job = ( new CsvImportJob() )->for_owner( $token, get_current_user_id() );
		if ( null === $job || empty( $job['report_path'] ) || ! is_readable( (string) $job['report_path'] ) ) {
			wp_die( esc_html__( 'This error report is unavailable or has expired.', 'dreamax-license-manager' ), 404 );
		}
		$this->download_headers( 'dreamax-license-import-errors.csv' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streams an owner-scoped private report.
		readfile( (string) $job['report_path'] );
		exit;
	}

	/**
	 * Neutralizes spreadsheet-formula prefixes.
	 *
	 * @param mixed $value Export value.
	 */
	public function csv_safe( $value ): string {
		$value = (string) $value;
		return preg_match( '/^[=+\-@]/', $value ) ? "'" . $value : $value;
	}

	/**
	 * Reads the nonce-protected import options.
	 *
	 * @return array<string,mixed>
	 */
	private function import_configuration(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- The public handler verifies dreamax_lm_import_csv before calling this helper.
		$mapping = array();
		foreach ( array( 'license_key', 'product_public_id', 'activation_limit', 'expires_at', 'normalization_profile', 'separator' ) as $field ) {
			$value             = isset( $_POST[ 'map_' . $field ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ 'map_' . $field ] ) ) : $field;
			$mapping[ $field ] = CsvFormat::heading( $value );
		}
		$configuration = array(
			'delimiter' => CsvFormat::delimiter( isset( $_POST['delimiter'] ) ? sanitize_key( wp_unslash( (string) $_POST['delimiter'] ) ) : 'comma' ),
			'encoding'  => CsvFormat::encoding( isset( $_POST['encoding'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['encoding'] ) ) : 'UTF-8' ),
			'mapping'   => $mapping,
			'duplicate' => isset( $_POST['duplicate_strategy'] ) && 'skip' === sanitize_key( wp_unslash( (string) $_POST['duplicate_strategy'] ) ) ? 'skip' : 'error',
			'dry_run'   => isset( $_POST['dry_run'] ),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		return $configuration;
	}

	/**
	 * Reads allowlisted export filters after handler nonce verification.
	 *
	 * @param string $type Export dataset type.
	 * @return array<string,mixed>
	 */
	private function export_filters( string $type ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- The public handler verifies dreamax_lm_export_csv before calling this helper.
		$status  = isset( $_POST['filter_status'] ) ? sanitize_key( wp_unslash( (string) $_POST['filter_status'] ) ) : '';
		$valid   = 'activations' === $type ? array( 'active', 'inactive' ) : array( 'available', 'assigned', 'suspended', 'revoked' );
		$filters = array(
			'status'   => in_array( $status, $valid, true ) ? $status : '',
			'product'  => isset( $_POST['filter_product'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['filter_product'] ) ) : '',
			'customer' => isset( $_POST['filter_customer'] ) ? max( 0, (int) $_POST['filter_customer'] ) : 0,
			'order'    => isset( $_POST['filter_order'] ) ? max( 0, (int) $_POST['filter_order'] ) : 0,
			'expiry'   => isset( $_POST['filter_expiry'] ) ? sanitize_key( wp_unslash( (string) $_POST['filter_expiry'] ) ) : '',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		return $filters;
	}

	/**
	 * Builds fixed SQL fragments with separately prepared values.
	 *
	 * @param array<string,mixed> $filters Validated filters.
	 * @param string              $type Export dataset type.
	 * @param array               $selected Selected license IDs (bounded by the caller).
	 * @phpstan-param list<string> $selected
	 * @return array{sql:string,args:list<mixed>}
	 */
	private function export_where( array $filters, string $type, array $selected = array() ): array {
		$status = 'licenses' === $type ? 'l.lifecycle_status' : 'a.status';
		$sql    = '';
		$args   = array();
		if ( '' !== $filters['status'] ) {
			$sql   .= " AND {$status}=%s";
			$args[] = $filters['status'];
		}
		if ( '' !== $filters['product'] ) {
			$sql   .= ' AND l.product_public_id=%s';
			$args[] = $filters['product'];
		}
		foreach ( array( 'customer', 'order' ) as $field ) {
			if ( (int) $filters[ $field ] > 0 ) {
				$column = 'customer' === $field ? 'customer_id' : 'order_id';
				$sql   .= " AND l.{$column}=%d";
				$args[] = (int) $filters[ $field ];
			}
		}
		$expiry = in_array( $filters['expiry'], array( 'expired', 'soon', 'lifetime' ), true ) ? $filters['expiry'] : '';
		if ( 'expired' === $expiry ) {
			$sql   .= ' AND l.expires_at IS NOT NULL AND l.expires_at<=%s';
			$args[] = gmdate( 'Y-m-d H:i:s' );
		} elseif ( 'soon' === $expiry ) {
			$sql   .= ' AND l.expires_at>%s AND l.expires_at<=%s';
			$args[] = gmdate( 'Y-m-d H:i:s' );
			$args[] = gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS );
		} elseif ( 'lifetime' === $expiry ) {
			$sql .= ' AND l.expires_at IS NULL';
		}
		if ( array() !== $selected ) {
			$sql .= ' AND l.public_id IN (' . implode( ',', array_fill( 0, count( $selected ), '%s' ) ) . ')';
			$args = array_merge( $args, $selected );
		}
		return array(
			'sql'  => $sql,
			'args' => $args,
		);
	}

	/**
	 * Renders the immediate import summary.
	 *
	 * @param string                                                          $token Owner-scoped report token.
	 * @param array{valid:int,skipped:int,errors:list<array{0:int,1:string}>} $result Import result.
	 * @param bool                                                            $dry_run Whether this was a preview.
	 */
	private function render_import_result( string $token, array $result, bool $dry_run ): void {
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!doctype html><meta charset="utf-8"><title>' . esc_html__( 'Import result', 'dreamax-license-manager' ) . '</title><h1>' . esc_html( $dry_run ? __( 'Import preview', 'dreamax-license-manager' ) : __( 'Import complete', 'dreamax-license-manager' ) ) . '</h1>';
		/* translators: %d: Number of valid CSV rows. */
		echo '<p>' . esc_html( sprintf( __( '%d valid rows.', 'dreamax-license-manager' ), $result['valid'] ) ) . ' ';
		/* translators: 1: skipped duplicates, 2: errors. */
		echo esc_html( sprintf( __( '%1$d duplicates skipped; %2$d errors.', 'dreamax-license-manager' ), $result['skipped'], count( $result['errors'] ) ) ) . '</p>';
		if ( array() !== $result['errors'] ) {
			echo '<h2>' . esc_html__( 'Rows needing attention', 'dreamax-license-manager' ) . '</h2>';
			echo '<table><thead><tr><th>' . esc_html__( 'Row', 'dreamax-license-manager' ) . '</th><th>' . esc_html__( 'Problem', 'dreamax-license-manager' ) . '</th></tr></thead><tbody>';
			foreach ( array_slice( $result['errors'], 0, 20 ) as $row_error ) {
				echo '<tr><td>' . esc_html( (string) $row_error[0] ) . '</td><td>' . esc_html( $row_error[1] ) . '</td></tr>';
			}
			echo '</tbody></table>';
			$url = wp_nonce_url(
				add_query_arg(
					array(
						'action' => 'dreamax_lm_csv_error_report',
						'job'    => $token,
					),
					admin_url( 'admin-post.php' )
				),
				'dreamax_lm_csv_error_report_' . $token
			);
			echo '<p><a href="' . esc_url( $url ) . '">' . esc_html__( 'Download row error report', 'dreamax-license-manager' ) . '</a></p>';
		}
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=dreamax-license-manager-transfer' ) ) . '">' . esc_html__( 'Back to Import / Export', 'dreamax-license-manager' ) . '</a></p>';
		exit;
	}

	/**
	 * Sends private CSV attachment headers.
	 *
	 * @param string $filename Safe attachment filename.
	 */
	private function download_headers( string $filename ): void {
		nocache_headers();
		header( 'Cache-Control: no-store, no-cache, must-revalidate, private' );
		header( 'Pragma: no-cache' );
		header( 'Expires: Wed, 11 Jan 1984 05:00:00 GMT' );
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
	}

	/**
	 * Enforces an administration capability before request processing.
	 *
	 * @param string $capability Required capability.
	 */
	private function authorize( string $capability ): void {
		if ( ! current_user_can( $capability ) ) {
			wp_die( esc_html__( 'You are not allowed to perform this action.', 'dreamax-license-manager' ) );
		}
	}
}
