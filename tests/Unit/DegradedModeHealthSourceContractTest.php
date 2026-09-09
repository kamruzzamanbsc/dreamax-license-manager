<?php
/**
 * Verifies the published degraded-mode health source contract.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Covers recovery signals and the documented runtime architecture.
 */
final class DegradedModeHealthSourceContractTest extends TestCase {
	/**
	 * Health source.
	 *
	 * @var string
	 */
	private string $health;

	/**
	 * Published degraded-mode matrix.
	 *
	 * @var string
	 */
	private string $matrix;

	/**
	 * Public route source.
	 *
	 * @var string
	 */
	private string $routes;

	/**
	 * Loads the source contracts.
	 */
	protected function setUp(): void {
		$root = dirname( __DIR__, 2 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local test fixture.
		$this->health = (string) file_get_contents( $root . '/src/Support/class-health.php' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local test fixture.
		$this->matrix = (string) file_get_contents( $root . '/docs/DEGRADED-MODES.md' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local test fixture.
		$this->routes = (string) file_get_contents( $root . '/src/Api/class-publicroutes.php' );
	}

	/**
	 * Verifies both additional Site Health checks are registered.
	 */
	public function test_site_health_registers_storage_and_cleanup_signals(): void {
		self::assertStringContainsString( 'dreamax_lm_storage', $this->health );
		self::assertStringContainsString( 'dreamax_lm_cleanup', $this->health );
		self::assertStringContainsString( 'SHOW TABLES LIKE %s', $this->health );
		self::assertStringContainsString( "'generators'", $this->health );
		self::assertStringContainsString( "'order_owners'", $this->health );
		self::assertStringContainsString( "wp_next_scheduled( 'dreamax_lm_cleanup' )", $this->health );
		self::assertStringContainsString( '! Health::storage_ready()', $this->routes );
		self::assertStringContainsString( "return \$this->responses->make( false, 'server_unavailable'", $this->routes );
	}

	/**
	 * Verifies the public recovery signal stays generic.
	 */
	public function test_storage_failure_signal_does_not_disclose_table_names(): void {
		self::assertStringContainsString( 'One or more required licensing tables are unavailable.', $this->health );
		self::assertStringNotContainsString( 'The missing table is', $this->health );
	}

	/**
	 * Verifies fresh installs receive an actionable setup notice.
	 */
	public function test_fresh_install_notice_links_to_encryption_setup(): void {
		self::assertStringContainsString( "get_option( 'dreamax_lm_master_key_id', false )", $this->health );
		self::assertStringContainsString( 'Dreamax License Manager setup required:', $this->health );
		self::assertStringContainsString( 'dreamax-license-manager-status', $this->health );
		self::assertStringContainsString( 'Open System Status', $this->health );
	}

	/**
	 * Verifies the matrix describes the architecture that ships.
	 */
	public function test_published_matrix_matches_the_wordpress_cron_and_streaming_architecture(): void {
		self::assertStringContainsString( 'WordPress cron absent/backlogged', $this->matrix );
		self::assertStringContainsString( 'Upload temporary storage unavailable', $this->matrix );
		self::assertStringNotContainsString( 'Action Scheduler absent/backlogged', $this->matrix );
		self::assertStringNotContainsString( 'Temporary/export storage unavailable', $this->matrix );
	}
}
