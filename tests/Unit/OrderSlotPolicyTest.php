<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use Dreamax\LicenseManager\Integrations\WooCommerce\OrderSlotPolicy;
use PHPUnit\Framework\TestCase;

final class OrderSlotPolicyTest extends TestCase {
	public function test_partial_refunds_map_to_highest_slots_without_overlap(): void {
		$policy = new OrderSlotPolicy();
		self::assertSame( array( 4, 5 ), $policy->newly_refunded_slots( 5, 2, 2 ) );
		self::assertSame( array( 3 ), $policy->newly_refunded_slots( 5, 3, 1 ) );
	}

	public function test_refunded_slots_are_excluded_from_backfill(): void {
		$policy = new OrderSlotPolicy();
		self::assertSame( array( 1, 2, 3 ), $policy->eligible_slots( 5, 5, 2, 'per_quantity' ) );
	}

	public function test_post_delivery_increase_preserves_refund_mapping(): void {
		$policy = new OrderSlotPolicy();
		self::assertSame( array( 1, 2, 3, 6 ), $policy->eligible_slots( 6, 5, 2, 'per_quantity' ) );
	}

	public function test_per_item_refund_applies_only_when_fully_refunded(): void {
		$policy = new OrderSlotPolicy();
		self::assertFalse( $policy->per_item_refund_applies( 3, 2, 2 ) );
		self::assertTrue( $policy->per_item_refund_applies( 3, 3, 1 ) );
		self::assertSame( array( 1 ), $policy->eligible_slots( 3, 3, 2, 'per_item' ) );
		self::assertSame( array(), $policy->eligible_slots( 3, 3, 3, 'per_item' ) );
	}
}
