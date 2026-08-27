<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AccountEndpointCacheHeaderSourceContractTest extends TestCase {
	private string $endpoint;

	protected function setUp(): void {
		$root           = dirname( __DIR__, 2 );
		$this->endpoint = (string) file_get_contents( $root . '/src/CustomerPortal/class-accountendpoint.php' );
	}

	public function test_account_document_and_reveal_use_the_private_cache_contract(): void {
		self::assertStringContainsString( '$this->send_private_cache_headers();', $this->method( 'render', 'issue_claim' ) );
		self::assertStringContainsString( '$this->send_private_cache_headers();', $this->method( 'reveal', 'send_private_cache_headers' ) );
	}

	public function test_private_cache_contract_sets_all_required_headers_explicitly(): void {
		$headers = $this->method( 'send_private_cache_headers', 'redirect_to_licenses' );

		self::assertStringContainsString( 'nocache_headers();', $headers );
		self::assertStringContainsString( 'Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0', $headers );
		self::assertStringContainsString( 'Pragma: no-cache', $headers );
		self::assertStringContainsString( 'Expires: Wed, 11 Jan 1984 05:00:00 GMT', $headers );
	}

	private function method( string $start, string $next ): string {
		$start_position = strpos( $this->endpoint, 'function ' . $start . '(' );
		$next_position  = strpos( $this->endpoint, 'function ' . $next . '(', false === $start_position ? 0 : $start_position );

		self::assertNotFalse( $start_position );
		self::assertNotFalse( $next_position );
		return substr( $this->endpoint, (int) $start_position, (int) $next_position - (int) $start_position );
	}
}
