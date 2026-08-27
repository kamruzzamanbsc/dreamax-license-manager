<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CsvSafetySourceContractTest extends TestCase {
	private string $controller;
	private string $verifier;
	private string $router;

	protected function setUp(): void {
		$root             = dirname( __DIR__, 2 );
		$this->controller = (string) file_get_contents( $root . '/src/ImportExport/class-csvcontroller.php' );
		$this->verifier   = (string) file_get_contents( $root . '/scripts/verify-live-csv-safety.php' );
		$this->router     = (string) file_get_contents( $root . '/scripts/lib/csv-http-router.php' );
	}

	public function test_production_import_retains_upload_and_bounded_row_guards(): void {
		self::assertStringContainsString( 'is_uploaded_file( $temporary_file )', $this->controller );
		self::assertStringContainsString( '5 * MB_IN_BYTES', $this->controller );
		self::assertStringContainsString( '$row_no > 10001', $this->controller );
		self::assertStringContainsString( 'if ( ! $dry_run )', $this->controller );
	}

	public function test_export_retains_capability_formula_and_keyset_guards(): void {
		self::assertStringContainsString( 'current_user_can( Capabilities::EXPORT )', $this->controller );
		self::assertStringContainsString( "preg_match( '/^[=+\\-@]/'", $this->controller );
		self::assertStringContainsString( 'WHERE id>%d ORDER BY id LIMIT %d', $this->controller );
		self::assertStringContainsString( '$batch_size = 250;', $this->controller );
	}

	public function test_live_verifier_uses_real_multipart_and_exact_cleanup(): void {
		foreach ( array( 'new CURLFile', '10001', '\\u{00C5}', '128 * MB_IN_BYTES', 'duplicate_and_normalization', 'export_audit_exact', 'owned_fixture_rows_remaining', 'sensitive_output' ) as $contract ) {
			self::assertStringContainsString( $contract, $this->verifier );
		}
		self::assertStringContainsString( "require ABSPATH . 'wp-admin/admin-post.php'", $this->router );
		self::assertStringContainsString( 'memory_reset_peak_usage', $this->router );
	}
}
