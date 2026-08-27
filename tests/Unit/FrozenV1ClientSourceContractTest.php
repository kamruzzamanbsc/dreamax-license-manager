<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class FrozenV1ClientSourceContractTest extends TestCase {
	public function test_frozen_client_keeps_the_required_v1_flow(): void {
		$client = (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/reference-client/v1/client.php' );
		self::assertStringContainsString( "'/licenses/activate'", $client );
		self::assertStringContainsString( "'idempotency_conflict'", $client );
		self::assertStringContainsString( "'/licenses/validate'", $client );
		self::assertStringContainsString( "'/licenses/deactivate'", $client );
	}

	public function test_live_runner_hashes_and_cleans_the_unchanged_fixture(): void {
		$runner = (string) file_get_contents( dirname( __DIR__, 2 ) . '/scripts/verify-live-frozen-v1-client.php' );
		self::assertStringContainsString( "hash_file( 'sha256', \$client )", $runner );
		self::assertStringContainsString( "'result_public_id' => \$activation_public_id", $runner );
		self::assertStringContainsString( "sodium_memzero( \$license_key )", $runner );
	}
}
