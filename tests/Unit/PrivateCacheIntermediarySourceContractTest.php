<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PrivateCacheIntermediarySourceContractTest extends TestCase {
	private string $verifier;
	private string $router;

	protected function setUp(): void {
		$root           = dirname( __DIR__, 2 );
		$this->verifier = (string) file_get_contents( $root . '/scripts/verify-live-private-cache-intermediary.php' );
		$this->router   = (string) file_get_contents( $root . '/scripts/lib/private-cache-proxy-router.php' );
	}

	public function test_verifier_checks_all_direct_and_intermediary_private_responses(): void {
		foreach ( array( 'direct_document_private', 'direct_reveal_private', 'intermediary_document_private', 'intermediary_reveal_private' ) as $check ) {
			self::assertStringContainsString( $check, $this->verifier );
		}
		self::assertStringContainsString( "array( 'no-store', 'no-cache', 'must-revalidate', 'private', 'max-age=0' )", $this->verifier );
		self::assertStringContainsString( "'Pragma'", str_replace( 'pragma', 'Pragma', $this->verifier ) );
	}

	public function test_verifier_restores_authentication_and_exact_reveal_events(): void {
		self::assertStringContainsString( "metadata_exists( 'user', \$owner_id, 'session_tokens' )", $this->verifier );
		self::assertStringContainsString( "update_user_meta( \$owner_id, 'session_tokens', \$session_before )", $this->verifier );
		self::assertStringContainsString( "array_diff( array_map( 'intval', \$event_ids_after )", $this->verifier );
		self::assertStringContainsString( 'sodium_memzero( $license_key )', $this->verifier );
	}

	public function test_loopback_router_forwards_only_the_required_response_headers(): void {
		foreach ( array( 'cache-control', 'pragma', 'expires', 'content-type', 'x-content-type-options' ) as $header ) {
			self::assertStringContainsString( "'{$header}'", $this->router );
		}
		self::assertStringNotContainsString( "'set-cookie'", $this->router );
	}
}
