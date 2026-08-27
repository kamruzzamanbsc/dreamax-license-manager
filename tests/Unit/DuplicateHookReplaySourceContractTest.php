<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DuplicateHookReplaySourceContractTest extends TestCase {
	private string $orderLicensing;
	private string $verifier;

	protected function setUp(): void {
		$root                 = dirname( __DIR__, 2 );
		$this->orderLicensing = (string) file_get_contents( $root . '/src/Integrations/WooCommerce/class-orderlicensing.php' );
		$this->verifier       = (string) file_get_contents( $root . '/scripts/verify-live-duplicate-hook-replay.php' );
	}

	public function test_both_paid_status_hooks_share_the_same_allocator(): void {
		self::assertStringContainsString( "add_action( 'woocommerce_order_status_processing', array( \$this, 'allocate' ) );", $this->orderLicensing );
		self::assertStringContainsString( "add_action( 'woocommerce_order_status_completed', array( \$this, 'allocate' ) );", $this->orderLicensing );
	}

	public function test_existing_slots_are_checked_before_license_creation(): void {
		$existing = strpos( $this->orderLicensing, 'isset( $existing_by_slot[ $slot ] ) || $this->licenses->by_order_slot' );
		$create   = strpos( $this->orderLicensing, '$this->service->create_generated' );

		self::assertNotFalse( $existing );
		self::assertNotFalse( $create );
		self::assertLessThan( $create, $existing );
	}

	public function test_allocation_event_and_note_require_a_new_allocation(): void {
		$gate  = strpos( $this->orderLicensing, "if ( \$result['allocated'] > 0 )" );
		$event = strpos( $this->orderLicensing, 'ORDER_AUTOMATIC_ALLOCATION_COMPLETED', false === $gate ? 0 : $gate );
		$note  = strpos( $this->orderLicensing, 'Dreamax allocated %d license(s).', false === $gate ? 0 : $gate );

		self::assertNotFalse( $gate );
		self::assertNotFalse( $event );
		self::assertNotFalse( $note );
		self::assertLessThan( $event, $gate );
		self::assertLessThan( $note, $gate );
	}

	public function test_live_verifier_replays_both_hooks_and_rolls_back(): void {
		self::assertStringContainsString( "dreamax_lm_f05_replay( 'woocommerce_order_status_processing'", $this->verifier );
		self::assertStringContainsString( "dreamax_lm_f05_replay( 'woocommerce_order_status_completed'", $this->verifier );
		self::assertStringContainsString( "\$wpdb->query( 'START TRANSACTION' )", $this->verifier );
		self::assertStringContainsString( "\$wpdb->query( 'ROLLBACK' )", $this->verifier );
		self::assertStringContainsString( "'sensitive_output'            => false", $this->verifier );
	}
}
