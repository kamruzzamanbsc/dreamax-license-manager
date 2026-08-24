<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use Dreamax\LicenseManager\Licenses\ExpiryPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ExpiryPolicyTest extends TestCase {
	public function test_active_license_extends_from_current_expiry(): void {
		$policy = new ExpiryPolicy();
		self::assertSame( '2026-02-10 00:00:00', $policy->extend( '2026-01-11 00:00:00', 30 * 86400, strtotime( '2026-01-01 UTC' ) ) );
	}

	public function test_expired_license_extends_from_authoritative_server_time(): void {
		$policy = new ExpiryPolicy();
		self::assertSame( '2026-01-31 00:00:00', $policy->extend( '2025-12-01 00:00:00', 30 * 86400, strtotime( '2026-01-01 UTC' ) ) );
	}

	public function test_lifetime_license_cannot_be_accidentally_shortened(): void {
		$this->expectException( InvalidArgumentException::class );
		( new ExpiryPolicy() )->extend( null, 30 * 86400, strtotime( '2026-01-01 UTC' ) );
	}

	/** @dataProvider invalidDurations */
	public function test_extension_bounds( int $seconds ): void {
		$this->expectException( InvalidArgumentException::class );
		( new ExpiryPolicy() )->extend( '2026-01-02 00:00:00', $seconds, strtotime( '2026-01-01 UTC' ) );
	}

	/** @return iterable<string,array{int}> */
	public static function invalidDurations(): iterable {
		yield 'zero' => array( 0 );
		yield 'under-one-day' => array( 86399 );
		yield 'over-ten-years' => array( 3651 * 86400 );
	}
}
