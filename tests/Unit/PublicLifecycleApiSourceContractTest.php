<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PublicLifecycleApiSourceContractTest extends TestCase {
	public function test_product_mismatch_uses_keyed_lookup_without_querying_raw_input(): void {
		$root       = dirname( __DIR__, 2 );
		$repository = (string) file_get_contents( $root . '/src/Licenses/class-licenserepository.php' );
		$service    = (string) file_get_contents( $root . '/src/Activations/class-activationservice.php' );

		self::assertStringContainsString( 'find_presented_any_product( string $presented )', $repository );
		self::assertStringContainsString( 'key_fingerprint = UNHEX(%s)', $repository );
		self::assertStringNotContainsString( 'WHERE key_fingerprint = $presented', $repository );
		self::assertStringContainsString( "throw new LicenseException( 'product_mismatch'", $service );
		self::assertStringContainsString( "throw new LicenseException( 'invalid_license'", $service );
	}

	public function test_public_routes_publish_all_lifecycle_domain_codes(): void {
		$routes = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Api/class-publicroutes.php' );
		foreach ( array( 'invalid_license', 'product_mismatch', 'license_expired', 'license_suspended', 'license_revoked', 'activation_limit_reached', 'activation_not_found' ) as $code ) {
			self::assertStringContainsString( "'{$code}'", $routes . (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Activations/class-activationservice.php' ) );
		}
	}

	public function test_optional_idempotency_header_is_normalized_before_repository_use(): void {
		$routes = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Api/class-publicroutes.php' );
		self::assertStringContainsString( "(string) \$request->get_header( 'Idempotency-Key' )", $routes );
	}
}
