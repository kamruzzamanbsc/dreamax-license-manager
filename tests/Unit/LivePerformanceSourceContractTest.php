<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class LivePerformanceSourceContractTest extends TestCase {
	private string $admin;
	private string $csv;
	private string $fixture;
	private string $verifier;
	private string $router;

	protected function setUp(): void {
		$root           = dirname( __DIR__, 2 );
		$this->admin    = (string) file_get_contents( $root . '/src/Admin/class-admin.php' );
		$this->csv      = (string) file_get_contents( $root . '/src/ImportExport/class-csvcontroller.php' );
		$this->fixture  = (string) file_get_contents( $root . '/scripts/lib/release-tools.php' );
		$this->verifier = (string) file_get_contents( $root . '/scripts/verify-live-performance.php' );
		$this->router   = (string) file_get_contents( $root . '/scripts/lib/performance-export-router.php' );
	}

	public function test_fixed_full_profile_and_production_query_bounds_remain_explicit(): void {
		self::assertStringContainsString( "'full' => array('licenses' => 10000, 'events' => 50000", $this->fixture );
		self::assertStringContainsString( 'LIMIT 50 OFFSET %d', $this->admin );
		self::assertStringContainsString( '$batch_size = 250;', $this->csv );
		self::assertStringContainsString( 'WHERE id>%d ORDER BY id LIMIT %d', $this->csv );
	}

	public function test_live_verifier_uses_bounded_batches_and_exact_ownership_cleanup(): void {
		foreach ( array( "PerformanceFixture::profile( 'full' )", 'license_batch_size = 100', 'event_batch_size   = 500', '256 * MB_IN_BYTES', 'fixture_run_id', 'dlm_f24_', 'cleanup_committed', 'sentinel_unchanged', 'owned_fixture_rows_remaining' ) as $contract ) {
			self::assertStringContainsString( $contract, $this->verifier );
		}
	}

	public function test_export_runs_through_the_real_admin_post_controller(): void {
		$health_position    = strpos( $this->router, "if ( '/health' === \$request_path )" );
		$bootstrap_position = strpos( $this->router, "require_once \$wp_root . '/wp-load.php'" );
		self::assertIsInt( $health_position );
		self::assertIsInt( $bootstrap_position );
		self::assertLessThan( $bootstrap_position, $health_position );
		self::assertStringContainsString( "require ABSPATH . 'wp-admin/admin-post.php'", $this->router );
		self::assertStringContainsString( 'memory_reset_peak_usage', $this->router );
		self::assertStringContainsString( "'action'   => 'dreamax_lm_export_csv'", $this->verifier );
		self::assertStringContainsString( 'X-Dreamax-F24-Peak-Memory', $this->router );
	}
}
