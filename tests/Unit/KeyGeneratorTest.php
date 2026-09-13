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

	public function test_normalized_configuration_generates_the_selected_pattern(): void {
		$generator = new KeyGenerator();
		$config    = $generator->normalize_configuration(
			array(
				'alphabet'  => 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789',
				'length'    => 24,
				'group'     => 4,
				'separator' => '-',
				'prefix'    => 'DREAMAX',
				'suffix'    => 'FREE',
			)
		);

		self::assertSame( 24, $config['length'] );
		self::assertMatchesRegularExpression( '/^DREAMAX-(?:[A-Z2-9]{4}-){5}[A-Z2-9]{4}-FREE$/D', $generator->generate( $config ) );
	}

	/**
	 * @dataProvider invalidConfigurationProvider
	 * @param array<string,mixed> $configuration Invalid configuration.
	 */
	public function test_unsafe_or_ambiguous_configuration_is_rejected( array $configuration ): void {
		$this->expectException( InvalidArgumentException::class );
		( new KeyGenerator() )->normalize_configuration( $configuration );
	}

	/**
	 * @return iterable<string,array{0:array<string,mixed>}>
	 */
	public static function invalidConfigurationProvider(): iterable {
		yield 'alphanumeric separator' => array( array( 'separator' => 'A' ) );
		yield 'non ascii alphabet' => array( array( 'alphabet' => "ABCDEFGHJKLMNPQRSTUVWXYZ2345678\xC3\x91" ) );
		yield 'duplicate alphabet' => array( array( 'alphabet' => 'AABCDEFGHIJKLMNOPQRSTUVWXYZ23456789' ) );
		yield 'weak entropy' => array( array( 'alphabet' => 'AB', 'length' => 95 ) );
		yield 'oversized random body' => array( array( 'length' => 129 ) );
		yield 'group larger than body' => array( array( 'group' => 27 ) );
		yield 'punctuated prefix' => array( array( 'prefix' => 'DLM-TEST' ) );
		yield 'oversized suffix' => array( array( 'suffix' => str_repeat( 'X', 33 ) ) );
	}
}
