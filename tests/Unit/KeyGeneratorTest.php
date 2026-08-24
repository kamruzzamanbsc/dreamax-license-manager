<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use Dreamax\LicenseManager\Generators\KeyGenerator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class KeyGeneratorTest extends TestCase {
	public function test_default_entropy_is_at_least_128_bits(): void {
		$generator = new KeyGenerator();
		self::assertGreaterThanOrEqual( 128.0, $generator->entropy_bits( KeyGenerator::DEFAULT_ALPHABET, KeyGenerator::DEFAULT_LENGTH ) );
	}

	public function test_sub_floor_configuration_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		( new KeyGenerator() )->generate( array( 'alphabet' => 'AB', 'length' => 95 ) );
	}

	public function test_default_shape(): void {
		$key = ( new KeyGenerator() )->generate();
		self::assertMatchesRegularExpression( '/^DLM-[A-Z2-9-]+$/D', $key );
	}
}
