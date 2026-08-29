<?php
/**
 * Verifies the guarded F29/F30 live verifier source contract.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Covers private-clone guards, multisite contracts, and sanitized cleanup.
 */
final class LiveMultisiteIsolationSourceContractTest extends TestCase {
	/**
	 * Verifier source.
	 *
	 * @var string
	 */
	private string $verifier;

	/**
	 * Loads the verifier.
	 */
	protected function setUp(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local test fixture.
		$this->verifier = (string) file_get_contents( dirname( __DIR__, 2 ) . '/scripts/verify-live-multisite-isolation.php' );
	}

	/**
	 * Requires a disposable marker, exact confirmation, and clean targets.
	 */
	public function test_private_multisite_mutations_are_guarded(): void {
		self::assertStringContainsString( 'DisposableEnvironmentGuard::assertSafe', $this->verifier );
		self::assertStringContainsString( 'I_CONFIRM_F29_F30_PRIVATE_MULTISITE', $this->verifier );
		self::assertStringContainsString( 'no_stale_database_residue', $this->verifier );
		self::assertStringContainsString( 'no_stale_clone_residue', $this->verifier );
		self::assertStringContainsString( 'PrivateCloneHarness::copy_database', $this->verifier );
		self::assertStringContainsString( 'DREAMAX_LM_F2930_DATABASE_PREFIX', $this->verifier );
	}

	/**
	 * Covers the complete site-local lifecycle matrix.
	 */
	public function test_multisite_contract_matrix_is_complete(): void {
		foreach ( array( 'existing_site_initialized', 'future_site_initialized', 'key_separation_passed', 'site_restore_passed', 'data_api_export_isolated', 'jobs_isolated', 'uninstall_isolated' ) as $contract ) {
			self::assertStringContainsString( $contract, $this->verifier );
		}
		self::assertStringContainsString( "add_action( 'wp_initialize_site'", $this->verifier );
		self::assertStringContainsString( 'Installer::deactivate( true )', $this->verifier );
	}

	/**
	 * Requires source preservation, exact cleanup, and value-free output.
	 */
	public function test_source_and_output_contracts_are_value_free(): void {
		self::assertStringContainsString( "'source_unchanged'", $this->verifier );
		self::assertStringContainsString( "'cleanup_committed'", $this->verifier );
		self::assertStringContainsString( 'PrivateCloneHarness::drop_database', $this->verifier );
		self::assertStringContainsString( 'PrivateCloneHarness::remove_tree', $this->verifier );
		self::assertStringContainsString( "'sensitive_output' => false", $this->verifier );
		self::assertStringContainsString( "'outbound_email_sent'", $this->verifier );
	}
}
