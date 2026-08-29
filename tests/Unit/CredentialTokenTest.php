<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use Dreamax\LicenseManager\Credentials\CredentialToken;
use PHPUnit\Framework\TestCase;

final class CredentialTokenTest extends TestCase {
	public function test_generated_identifier_secret_and_wire_format_have_exact_lengths(): void {
		$tokens = new CredentialToken();
		$first  = $tokens->generate();
		$second = $tokens->generate();

		self::assertSame( 22, strlen( $first['public_id'] ) );
		self::assertSame( 43, strlen( $first['secret'] ) );
		self::assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{22}$/D', $first['public_id'] );
		self::assertMatchesRegularExpression( '/^dlm_v1_[A-Za-z0-9_-]{22}\.[A-Za-z0-9_-]{43}$/D', $first['credential'] );
		self::assertNotSame( $first['public_id'], $second['public_id'] );
		self::assertNotSame( $first['secret'], $second['secret'] );
	}

	public function test_modern_verifier_authenticates_only_the_correct_secret(): void {
		$tokens = new CredentialToken();
		$secret = $tokens->generate_secret();
		$hash   = $tokens->hash( $secret );

		self::assertTrue( $tokens->verify( $secret, $hash ) );
		self::assertFalse( $tokens->verify( str_repeat( 'A', 43 ), $hash ) );
		self::assertStringNotContainsString( $secret, $hash );
	}

	public function test_exact_bearer_value_parses_without_returning_the_full_header(): void {
		$tokens    = new CredentialToken();
		$generated = $tokens->generate();
		$parsed    = $tokens->parse( 'Bearer ' . $generated['credential'] );

		self::assertSame( array( 'public_id' => $generated['public_id'], 'secret' => $generated['secret'] ), $parsed );
	}

	/**
	 * @dataProvider invalidAuthorizationProvider
	 */
	public function test_missing_multiple_malformed_control_and_overlong_headers_are_rejected( string $header ): void {
		self::assertNull( ( new CredentialToken() )->parse( $header ) );
	}

	/** @return iterable<string,array{string}> */
	public static function invalidAuthorizationProvider(): iterable {
		$valid = 'Bearer dlm_v1_' . str_repeat( 'A', 22 ) . '.' . str_repeat( 'B', 43 );
		yield 'missing' => array( '' );
		yield 'basic' => array( 'Basic ' . str_repeat( 'A', 20 ) );
		yield 'wrong case' => array( 'bearer ' . substr( $valid, 7 ) );
		yield 'multiple joined' => array( $valid . ',' . $valid );
		yield 'control' => array( $valid . "\r" );
		yield 'overlong' => array( $valid . str_repeat( 'A', 300 ) );
		yield 'short secret' => array( 'Bearer dlm_v1_' . str_repeat( 'A', 22 ) . '.' . str_repeat( 'B', 42 ) );
	}

	public function test_body_scanner_rejects_wire_values_and_credential_fields_only(): void {
		$tokens     = new CredentialToken();
		$credential = $tokens->generate()['credential'];

		self::assertTrue( $tokens->body_contains_credential( (string) json_encode( array( 'nested' => array( 'authorization' => 'anything' ) ) ) ) );
		self::assertTrue( $tokens->body_contains_credential( (string) json_encode( array( 'note' => $credential ) ) ) );
		self::assertFalse( $tokens->body_contains_credential( (string) json_encode( array( 'license_key' => 'DLM-LICENSE-NOT-API-CREDENTIAL' ) ) ) );
	}
}
