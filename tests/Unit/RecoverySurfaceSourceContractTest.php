<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class RecoverySurfaceSourceContractTest extends TestCase {
	private string $account;
	private string $orders;
	private string $csv;

	protected function setUp(): void {
		$root          = dirname( __DIR__, 2 );
		$this->account = (string) file_get_contents( $root . '/src/CustomerPortal/class-accountendpoint.php' );
		$this->orders  = (string) file_get_contents( $root . '/src/Integrations/WooCommerce/class-orderlicensing.php' );
		$this->csv     = (string) file_get_contents( $root . '/src/ImportExport/class-csvcontroller.php' );
	}

	public function test_customer_surfaces_degrade_without_exposing_or_mutating_keys(): void {
		$render = $this->method( $this->account, 'render', 'issue_claim' );
		$reveal = $this->method( $this->account, 'reveal', 'prepare_key_access' );

		self::assertStringContainsString( '$this->prepare_key_access( $rows )', $render );
		self::assertStringContainsString( 'Secure key access is temporarily unavailable.', $render );
		self::assertStringContainsString( 'disabled aria-disabled="true"', $render );
		self::assertStringContainsString( 'catch ( Throwable $error )', $reveal );
		self::assertStringContainsString( '), 503 )', $reveal );
		self::assertLessThan(
			strpos( $reveal, 'AuditEventCatalog::LICENSE_REVEALED' ),
			strpos( $reveal, '$this->licenses->decrypt_key( $license )' )
		);
	}

	public function test_order_views_and_resends_fail_safely_before_idempotency_claims(): void {
		$resend = $this->method( $this->orders, 'resend', 'email_licenses' );
		$render = $this->method( $this->orders, 'render', 'key_for_display' );

		self::assertStringContainsString( 'Secure key access is temporarily unavailable.', $resend );
		self::assertLessThan(
			strpos( $resend, '$this->operations->claim(' ),
			strpos( $resend, '$this->licenses->decrypt_key( $row )' )
		);
		self::assertStringNotContainsString( '$this->licenses->decrypt_key( $row )', $render );
		self::assertStringContainsString( '$this->key_for_display( $row )', $render );
	}

	public function test_csv_export_is_validated_before_audit_or_download(): void {
		$export = $this->method( $this->csv, 'export', 'csv_safe' );

		self::assertStringContainsString( '( new Crypto() )->ready()', $export );
		self::assertStringContainsString( "PrivateTempFile::create( 'dreamax-license-export.csv' )", $export );
		self::assertStringContainsString( 'catch ( Throwable $error )', $export );
		self::assertLessThan(
			strpos( $export, 'AuditEventCatalog::LICENSE_EXPORTED' ),
			strpos( $export, '$repository->decrypt_key( $row )' )
		);
		self::assertLessThan(
			strpos( $export, '$this->download_headers(' ),
			strpos( $export, '$repository->decrypt_key( $row )' )
		);
		self::assertStringContainsString( "header( 'Content-Type: text/csv; charset=utf-8' )", $this->csv );
	}

	private function method( string $source, string $start, string $next ): string {
		$start_position = strpos( $source, 'function ' . $start . '(' );
		$next_position  = strpos( $source, 'function ' . $next . '(', false === $start_position ? 0 : $start_position );

		self::assertNotFalse( $start_position );
		self::assertNotFalse( $next_position );
		return substr( $source, (int) $start_position, (int) $next_position - (int) $start_position );
	}
}
