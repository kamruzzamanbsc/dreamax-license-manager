<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PrivacyExportErasureSourceContractTest extends TestCase {
	private string $privacy;
	private string $verifier;

	protected function setUp(): void {
		$root           = dirname( __DIR__, 2 );
		$this->privacy  = (string) file_get_contents( $root . '/src/Privacy/class-privacy.php' );
		$this->verifier = (string) file_get_contents( $root . '/scripts/verify-live-privacy-export-erasure.php' );
	}

	public function test_export_is_customer_scoped_bounded_and_secret_free(): void {
		self::assertStringContainsString( 'WHERE customer_id=%d ORDER BY id LIMIT 100 OFFSET %d', $this->privacy );
		self::assertStringContainsString( 'WHERE target_user_id=%d ORDER BY id LIMIT 100 OFFSET %d', $this->privacy );
		self::assertStringNotContainsString( "'License key'", $this->privacy );
		self::assertStringNotContainsString( "'token_hash'", substr( $this->privacy, strpos( $this->privacy, 'public function export' ), strpos( $this->privacy, 'public function erase' ) - strpos( $this->privacy, 'public function export' ) ) );
	}

	public function test_eraser_anonymizes_personal_fields_and_retains_integrity(): void {
		self::assertStringContainsString( 'instance_label=NULL,ip_fingerprint=NULL,metadata=NULL', $this->privacy );
		self::assertStringContainsString( 'customer_id=NULL,customer_email_enc=NULL,metadata=NULL', $this->privacy );
		self::assertStringContainsString( 'target_user_id=NULL', $this->privacy );
		self::assertStringContainsString( 'token_hash=NULL', $this->privacy );
		self::assertStringContainsString( 'AuditEventCatalog::PRIVACY_DATA_ANONYMIZED', $this->privacy );
	}

	public function test_live_verifier_covers_boundary_isolation_repeat_and_cleanup(): void {
		foreach ( array( "'a' => 101", "'b' => 1", '$erase_3', '$cross_isolation', '$privacy_event_ids', "'sensitive_output'" ) as $contract ) {
			self::assertStringContainsString( $contract, $this->verifier );
		}
	}
}
