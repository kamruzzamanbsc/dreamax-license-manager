<?php
/**
 * Tests strict per-license policy parsing and revisions.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use Dreamax\LicenseManager\Licenses\LicensePolicyEditor;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class LicensePolicyEditorTest extends TestCase {
	public function test_strict_utc_expiry_and_never_mode(): void {
		$editor = new LicensePolicyEditor();
		self::assertSame( '2030-05-04 13:45:00', $editor->expiry( '2030-05-04T13:45' ) );
		self::assertNull( $editor->expiry( '' ) );
	}

	/** @dataProvider invalidDates */
	public function test_invalid_or_normalized_dates_are_rejected( string $value ): void {
		$this->expectException( InvalidArgumentException::class );
		( new LicensePolicyEditor() )->expiry( $value );
	}

	/** @return iterable<string,array{string}> */
	public static function invalidDates(): iterable {
		yield 'impossible day' => array( '2030-02-30T10:00' );
		yield 'timezone suffix' => array( '2030-05-04T13:45Z' );
		yield 'seconds' => array( '2030-05-04T13:45:00' );
		yield 'free text' => array( 'next Friday' );
	}

	public function test_revision_changes_with_policy_or_shared_metadata(): void {
		$editor = new LicensePolicyEditor();
		$base   = array( 'activation_limit' => 1, 'expires_at' => null, 'updated_at' => '2030-01-01 00:00:00', 'metadata' => '{}' );
		self::assertNotSame( $editor->revision( $base ), $editor->revision( array_replace( $base, array( 'activation_limit' => 2 ) ) ) );
		self::assertNotSame( $editor->revision( $base ), $editor->revision( array_replace( $base, array( 'metadata' => '{"other":true}' ) ) ) );
	}
}
