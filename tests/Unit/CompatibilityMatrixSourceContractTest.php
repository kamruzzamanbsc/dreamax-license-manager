<?php
/**
 * Tests the guarded F20 compatibility verifier source contract.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the live F20 verifier's mode, isolation, and cleanup boundaries.
 */
final class CompatibilityMatrixSourceContractTest extends TestCase {
	/**
	 * Returns the verifier source.
	 */
	private function source(): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads only the committed local verifier source.
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/scripts/verify-live-compatibility-matrix.php' );
		self::assertIsString( $source );
		return $source;
	}

	/**
	 * Verifies the exact supported-mode matrix and repeat count.
	 */
	public function test_verifier_covers_both_modes_and_repeats_each(): void {
		$source = $this->source();
		self::assertStringContainsString( "'hpos_blocks'", $source );
		self::assertStringContainsString( "'classic_shortcode'", $source );
		self::assertStringContainsString( 'for ( $run = 1; $run <= 2; ++$run )', $source );
		self::assertStringContainsString( 'OrderUtil::custom_orders_table_usage_is_enabled()', $source );
		self::assertStringContainsString( 'get_current_class_name()', $source );
		self::assertStringContainsString( "has_block( 'woocommerce/checkout'", $source );
		self::assertStringContainsString( 'has_shortcode( $checkout->post_content, \'woocommerce_checkout\' )', $source );
	}

	/**
	 * Verifies the licensing parity assertions.
	 */
	public function test_verifier_checks_license_slots_events_and_storage(): void {
		$source = $this->source();
		self::assertStringContainsString( 'AuditEventCatalog::LICENSE_CREATED', $source );
		self::assertStringContainsString( 'AuditEventCatalog::LICENSE_ASSIGNED', $source );
		self::assertStringContainsString( 'AuditEventCatalog::LICENSE_DELIVERED', $source );
		self::assertStringContainsString( "'slots'         => array( 1, 2 )", $source );
		self::assertStringContainsString( "'missing'       => 0", $source );
		self::assertStringContainsString( "'storage_match' => true", $source );
		self::assertStringContainsString( "'equivalent_outcomes'", $source );
	}

	/**
	 * Verifies private clone and destructive cleanup guards.
	 */
	public function test_verifier_guards_clone_targets_and_preserves_source(): void {
		$source = $this->source();
		self::assertStringContainsString( '--diagnose-only', str_replace( "'diagnose-only'", '--diagnose-only', $source ) );
		self::assertStringContainsString( 'I_CONFIRM_F20_PRIVATE_CLONE_MATRIX', $source );
		self::assertStringContainsString( '/^dreamax_lm_f20_(?:hpos|classic)_test_[a-f0-9]{16}$/D', $source );
		self::assertStringContainsString( 'DROP DATABASE IF EXISTS', $source );
		self::assertStringContainsString( '$wpdb->suppress_errors( true )', $source );
		self::assertStringContainsString( "'worker_stages'", $source );
		self::assertStringContainsString( 'dreamax_lm_f20_remove_tree', $source );
		self::assertStringContainsString( 'dreamax_lm_f20_database_digest( $source_database ) !== $source_digest', $source );
		self::assertStringContainsString( "'outbound_email_sent'      => false", $source );
		self::assertStringContainsString( "'sensitive_output'         => false", $source );
	}
}
