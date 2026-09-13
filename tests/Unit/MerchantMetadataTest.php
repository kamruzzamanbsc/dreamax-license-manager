<?php
/**
 * Tests the merchant-only license metadata boundary.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use Dreamax\LicenseManager\Licenses\MerchantMetadata;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MerchantMetadataTest extends TestCase {
	public function test_read_preserves_only_merchant_fields_and_revisions_full_document(): void {
		$raw    = '{"woocommerce":{"source":"pool"},"merchant_data":{"note":"Check return policy","fields":{"ticket":"case-12"}}}';
		$result = ( new MerchantMetadata() )->view( array( 'metadata' => $raw ) );
		self::assertSame( 'Check return policy', $result['note'] );
		self::assertSame( array( 'ticket' => 'case-12' ), $result['fields'] );
		self::assertSame( hash( 'sha256', $raw ), $result['revision'] );
		self::assertNotSame( $result['revision'], ( new MerchantMetadata() )->view( array( 'metadata' => '{}' ) )['revision'] );
	}

	public function test_missing_namespace_is_empty_without_replacing_shared_metadata(): void {
		$result = ( new MerchantMetadata() )->view( array( 'metadata' => '{"lifecycle_operations":["marker"]}' ) );
		self::assertSame( '', $result['note'] );
		self::assertSame( array(), $result['fields'] );
	}

	public function test_malformed_merchant_namespace_fails_closed(): void {
		$this->expectException( RuntimeException::class );
		( new MerchantMetadata() )->view( array( 'metadata' => '{"merchant_data":{"fields":"not-an-object"}}' ) );
	}

	public function test_invalid_shared_metadata_fails_closed(): void {
		$this->expectException( RuntimeException::class );
		( new MerchantMetadata() )->view( array( 'metadata' => '{invalid' ) );
	}
}
