<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use Dreamax\LicenseManager\CustomerPortal\GuestClaimToken;
use Dreamax\LicenseManager\Support\Base64Url;
use PHPUnit\Framework\TestCase;

final class GuestClaimTokenTest extends TestCase {
	public function test_token_contains_at_least_256_bits_of_randomness(): void {
		$token = ( new GuestClaimToken() )->generate();
		self::assertSame( 32, strlen( Base64Url::decode( $token ) ) );
		self::assertSame( 43, strlen( $token ) );
	}

	public function test_token_format_is_exact_and_url_safe_without_being_put_in_a_url(): void {
		$tokens = new GuestClaimToken();
		self::assertTrue( $tokens->valid_format( $tokens->generate() ) );
		self::assertFalse( $tokens->valid_format( 'short' ) );
		self::assertFalse( $tokens->valid_format( str_repeat( 'A', 42 ) . '=' ) );
	}

	public function test_independent_tokens_do_not_repeat(): void {
		$tokens = new GuestClaimToken();
		self::assertNotSame( $tokens->generate(), $tokens->generate() );
	}
}
