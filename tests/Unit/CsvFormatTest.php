<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use Dreamax\LicenseManager\ImportExport\CsvFormat;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CsvFormatTest extends TestCase {
	public function test_delimiters_and_encodings_are_allowlisted(): void {
		self::assertSame( ',', CsvFormat::delimiter( 'unknown' ) );
		self::assertSame( ';', CsvFormat::delimiter( 'semicolon' ) );
		self::assertSame( "\t", CsvFormat::delimiter( 'tab' ) );
		self::assertSame( 'UTF-8', CsvFormat::encoding( 'utf-16' ) );
		self::assertSame( 'Windows-1252', CsvFormat::encoding( 'windows-1252' ) );
	}

	public function test_headings_are_bom_safe_and_stable(): void {
		self::assertSame( 'license_key', CsvFormat::heading( "\xEF\xBB\xBFLicense Key" ) );
		self::assertSame( 'product_public_id', CsvFormat::heading( ' Product Public ID ' ) );
	}

	public function test_invalid_utf8_is_rejected(): void {
		$this->expectException( RuntimeException::class );
		CsvFormat::decode( "\xC3\x28", 'UTF-8' );
	}
}
