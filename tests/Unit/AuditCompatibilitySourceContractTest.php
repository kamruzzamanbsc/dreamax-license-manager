<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AuditCompatibilitySourceContractTest extends TestCase {
	private string $verifier;

	protected function setUp(): void {
		$root           = dirname( __DIR__, 2 );
		$this->verifier = (string) file_get_contents( $root . '/scripts/verify-live-audit-compatibility.php' );
	}

	public function test_live_verifier_is_guarded_transactional_and_exactly_cleaned(): void {
		self::assertStringContainsString( 'DisposableEnvironmentGuard::assertSafe', $this->verifier );
		self::assertStringContainsString( "'environment-marker:'", $this->verifier );
		self::assertStringContainsString( "'InnoDB'", $this->verifier );
		self::assertStringContainsString( 'START TRANSACTION', $this->verifier );
		self::assertStringContainsString( "'database_aggregates_unchanged'", $this->verifier );
		self::assertStringContainsString( "'owned_fixture_rows_remaining'", $this->verifier );
	}

	public function test_live_verifier_covers_write_and_rollback_contracts(): void {
		self::assertStringContainsString( 'PRIVACY_DATA_ANONYMIZED', $this->verifier );
		self::assertStringContainsString( 'LEGACY_LICENSE_IMPORTED', $this->verifier );
		self::assertStringContainsString( "'catalog_rejection_rolled_back'", $this->verifier );
		self::assertStringContainsString( "'persistence_failure_rolled_back'", $this->verifier );
		self::assertStringContainsString( 'dreamax_lm_f32_intentionally_missing', $this->verifier );
	}

	public function test_live_verifier_covers_every_consumer_compatibility_status(): void {
		foreach ( array( 'supported', 'legacy_catalog_entry', 'legacy_unversioned', 'unsupported_version', 'unknown_type', 'legacy_payload' ) as $status ) {
			self::assertStringContainsString( $status, $this->verifier );
		}
		self::assertStringContainsString( '( new Admin() )->activity_page()', $this->verifier );
		self::assertStringContainsString( "'historical_rows_byte_stable'", $this->verifier );
		self::assertStringContainsString( "'read_side_redaction'", $this->verifier );
	}

	public function test_verifier_output_is_sanitized_and_mail_is_blocked(): void {
		self::assertStringContainsString( "'pre_wp_mail'", $this->verifier );
		self::assertStringContainsString( "'sensitive_output'", $this->verifier );
		self::assertStringContainsString( "'outbound_email_sent'", $this->verifier );
		self::assertStringNotContainsString( 'echo $private_value', $this->verifier );
		self::assertStringNotContainsString( 'fwrite( STDERR, $error', $this->verifier );
	}
}
