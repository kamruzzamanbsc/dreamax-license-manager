<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use Dreamax\LicenseManager\Events\AuditMetadata;
use PHPUnit\Framework\TestCase;

final class AuditMetadataTest extends TestCase {
	public function test_claim_audit_metadata_removes_tokens_and_full_license_keys_recursively(): void {
		$clean = ( new AuditMetadata() )->sanitize(
			array(
				'order_id' => 123,
				'token'    => 'not-persisted',
				'nested'   => array(
					'claim_code' => 'not-persisted',
					'license_key' => 'not-persisted',
					'result'      => 'conflict',
				),
			)
		);
		self::assertSame( array( 'order_id' => 123, 'nested' => array( 'result' => 'conflict' ) ), $clean );
	}
}
