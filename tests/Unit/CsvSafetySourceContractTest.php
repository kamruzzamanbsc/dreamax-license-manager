<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CsvSafetySourceContractTest extends TestCase {
	private string $controller;
	private string $processor;
	private string $job;
	private string $admin;
	private string $verifier;
	private string $router;

	protected function setUp(): void {
		$root             = dirname( __DIR__, 2 );
		$this->controller = (string) file_get_contents( $root . '/src/ImportExport/class-csvcontroller.php' );
		$this->processor  = (string) file_get_contents( $root . '/src/ImportExport/class-csvimportprocessor.php' );
		$this->job        = (string) file_get_contents( $root . '/src/ImportExport/class-csvimportjob.php' );
		$this->admin      = (string) file_get_contents( $root . '/src/Admin/class-admin.php' );
		$this->verifier   = (string) file_get_contents( $root . '/scripts/verify-live-csv-safety.php' );
		$this->router     = (string) file_get_contents( $root . '/scripts/lib/csv-http-router.php' );
	}

	public function test_production_import_retains_upload_and_bounded_row_guards(): void {
		self::assertStringContainsString( 'is_uploaded_file( $temporary_file )', $this->controller );
		self::assertStringContainsString( '5 * MB_IN_BYTES', $this->controller );
		self::assertStringContainsString( 'MAX_ROWS   = 10000', $this->processor );
		self::assertStringContainsString( 'if ( ! $dry_run )', $this->processor );
		self::assertStringContainsString( 'CsvImportProcessor::MAX_ROWS', $this->job );
		self::assertStringContainsString( 'as_enqueue_async_action', $this->job );
		self::assertStringContainsString( 'acquire_lock( $token )', $this->job );
		self::assertStringContainsString( "LOCK_SUFFIX   = '_lock'", $this->job );
		self::assertStringContainsString( 'release_lock( $token, $lock_owner )', $this->job );
		self::assertStringContainsString( "'option_value' => \$lock_owner", $this->job );
		self::assertStringContainsString( 'finally', $this->job );
	}

	public function test_export_retains_capability_formula_and_keyset_guards(): void {
		self::assertStringContainsString( 'current_user_can( Capabilities::EXPORT )', $this->controller );
		self::assertStringContainsString( "preg_match( '/^[=+\\-@]/'", $this->controller );
		self::assertStringContainsString( 'WHERE l.id>%d', $this->controller );
		self::assertStringContainsString( '$batch_size = 250;', $this->controller );
	}

	public function test_portability_surface_includes_mapping_templates_filters_and_reports(): void {
		foreach ( array( 'dreamax_lm_csv_template', 'dreamax_lm_csv_error_report', 'export_type', 'filter_product' ) as $contract ) {
			self::assertStringContainsString( $contract, $this->controller );
		}
		foreach ( array( 'name="map_', 'duplicate_strategy', 'Windows-1252', 'Download template' ) as $contract ) {
			self::assertStringContainsString( $contract, $this->admin );
		}
		self::assertStringContainsString( 'report_path', $this->job );
		self::assertStringContainsString( "'seen'", $this->processor );
	}

	public function test_live_verifier_uses_real_multipart_and_exact_cleanup(): void {
		foreach ( array( 'new CURLFile', '10001', '\\u{00C5}', '128 * MB_IN_BYTES', 'duplicate_and_normalization', 'export_audit_exact', 'owned_fixture_rows_remaining', 'sensitive_output' ) as $contract ) {
			self::assertStringContainsString( $contract, $this->verifier );
		}
		self::assertStringContainsString( "require ABSPATH . 'wp-admin/admin-post.php'", $this->router );
		self::assertStringContainsString( 'memory_reset_peak_usage', $this->router );
	}
}
