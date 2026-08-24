<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use Dreamax\LicenseManager\Integrations\WooCommerce\OrderAccessPolicy;
use PHPUnit\Framework\TestCase;

final class OrderAccessPolicyTest extends TestCase {
	public function test_valid_guest_ownership_display_requires_verified_context_and_order_key(): void {
		$policy = new OrderAccessPolicy();
		self::assertTrue( $policy->allows( 0, 0, true, true ) );
		self::assertFalse( $policy->allows( 0, 0, false, true ) );
		self::assertFalse( $policy->allows( 0, 0, true, false ) );
	}

	public function test_email_knowledge_alone_is_never_an_access_input(): void {
		$policy = new OrderAccessPolicy();
		self::assertFalse( $policy->allows( 0, 55, true, false ) );
	}

	public function test_registered_order_requires_matching_authenticated_account(): void {
		$policy = new OrderAccessPolicy();
		self::assertTrue( $policy->allows( 55, 55, true, false ) );
		self::assertFalse( $policy->allows( 55, 56, true, true ) );
		self::assertFalse( $policy->allows( 55, 0, true, true ) );
	}
}
