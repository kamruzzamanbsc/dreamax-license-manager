<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use Dreamax\LicenseManager\Licenses\KeyNormalizer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class KeyNormalizerTest extends TestCase {
	/** @dataProvider generatedVectors */
	public function test_generated_vectors( string $separator, string $input, string $expected ): void {
		self::assertSame( $expected, ( new KeyNormalizer() )->normalize( $input, KeyNormalizer::GENERATED, $separator ) );
	}

	/** @return list<array{string,string,string}> */
	public static function generatedVectors(): array {
		return array(
			array( '-', ' ab-cd-12 ', 'ABCD12' ),
			array( '-', 'AB CD 12', 'ABCD12' ),
			array( '_', 'ab_cd_12', 'ABCD12' ),
			array( '.', 'Ab.Cd.12', 'ABCD12' ),
		);
	}

	/** @dataProvider rejectedGenerated */
	public function test_rejected_generated( string $input ): void {
		$this->expectException( InvalidArgumentException::class );
		( new KeyNormalizer() )->normalize( $input, KeyNormalizer::GENERATED, '-' );
	}

	/** @return list<array{string}> */
	public static function rejectedGenerated(): array {
		return array( array( 'AB—CD' ), array( "AB\tCD" ), array( 'AB/CD' ), array( '---' ) );
	}

	public function test_import_exact_preserves_value(): void {
		$normalizer = new KeyNormalizer();
		self::assertSame( 'Ab-cd-12', $normalizer->normalize( 'Ab-cd-12', KeyNormalizer::IMPORTED ) );
		self::assertSame( ' ab-cd-12 ', $normalizer->normalize( ' ab-cd-12 ', KeyNormalizer::IMPORTED ) );
		self::assertSame( 'Ab-cd', $normalizer->normalize( "\xEF\xBB\xBFAb-cd\r\n", KeyNormalizer::IMPORTED, null, true ) );
		self::assertNotSame( $normalizer->normalize( "é", KeyNormalizer::IMPORTED ), $normalizer->normalize( "e\u{0301}", KeyNormalizer::IMPORTED ) );
	}
}
