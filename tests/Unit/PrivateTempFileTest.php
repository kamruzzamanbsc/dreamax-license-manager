<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use Dreamax\LicenseManager\ImportExport\PrivateTempFile;
use PHPUnit\Framework\TestCase;

final class PrivateTempFileTest extends TestCase {
	public function test_refuses_files_inside_or_equal_to_a_known_root(): void {
		$root = dirname( __DIR__, 2 );
		self::assertFalse( PrivateTempFile::outside_roots( __FILE__, array( $root ) ) );
		self::assertFalse( PrivateTempFile::outside_roots( $root, array( $root ) ) );
	}

	public function test_accepts_a_resolved_path_outside_the_root_and_fails_closed_on_missing_roots(): void {
		$source_root = dirname( __DIR__, 2 );
		self::assertTrue( PrivateTempFile::outside_roots( __FILE__, array( $source_root . '/src' ) ) );
		self::assertFalse( PrivateTempFile::outside_roots( __FILE__, array( $source_root . '/not-a-real-root' ) ) );
		self::assertFalse( PrivateTempFile::outside_roots( $source_root . '/not-a-real-file', array( $source_root ) ) );
	}
}
