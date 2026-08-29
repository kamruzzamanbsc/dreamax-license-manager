<?php
/**
 * Verifies the guarded F26 live verifier source contract.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Covers destructive guards, fault modes, recovery, and sanitized output.
 */
final class LiveDegradedMatrixSourceContractTest extends TestCase {
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
		$this->verifier = (string) file_get_contents( dirname( __DIR__, 2 ) . '/scripts/verify-live-degraded-matrix.php' );
	}

	/**
	 * Requires an unmistakable disposable target and exact destructive confirmation.
	 */
	public function test_private_clone_mutations_are_guarded(): void {
		self::assertStringContainsString( 'DisposableEnvironmentGuard::assertSafe', $this->verifier );
		self::assertStringContainsString( 'I_CONFIRM_F26_PRIVATE_CLONE_FAULTS', $this->verifier );
		self::assertStringContainsString( 'no_stale_database_residue', $this->verifier );
		self::assertStringContainsString( 'no_stale_clone_residue', $this->verifier );
		self::assertStringContainsString( 'dreamax_lm_f26_assert_database', $this->verifier );
		self::assertStringContainsString( 'Cleanup refused an ambiguous private fault clone.', $this->verifier );
	}

	/**
	 * Covers every newly injected degraded state and a final healthy baseline.
	 */
	public function test_all_clone_only_fault_modes_and_recovery_are_exercised(): void {
		foreach ( array( 'cron_missing', 'upload_missing', 'storage_missing', 'audit_missing', 'woo_missing', 'recovery_baseline' ) as $contract ) {
			self::assertStringContainsString( $contract, $this->verifier );
		}
		self::assertStringContainsString( 'transaction_rolled_back', $this->verifier );
		self::assertStringContainsString( 'public_read_503', $this->verifier );
		self::assertStringContainsString( 'public_routes_disabled', $this->verifier );
	}

	/**
	 * Requires exact cleanup, source preservation, and sanitized output.
	 */
	public function test_source_and_output_contracts_are_value_free(): void {
		self::assertStringContainsString( 'source_database_unchanged', $this->verifier );
		self::assertStringContainsString( 'source_config_unchanged', $this->verifier );
		self::assertStringContainsString( 'dreamax_lm_f26_drop_database', $this->verifier );
		self::assertStringContainsString( 'dreamax_lm_f26_remove_tree', $this->verifier );
		self::assertStringContainsString( "'sensitive_output' => false", $this->verifier );
	}
}
