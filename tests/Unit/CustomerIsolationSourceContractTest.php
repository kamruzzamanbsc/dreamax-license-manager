<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CustomerIsolationSourceContractTest extends TestCase {
	private string $endpoint;
	private string $orders;
	private string $verifier;

	protected function setUp(): void {
		$root           = dirname( __DIR__, 2 );
		$this->endpoint = (string) file_get_contents( $root . '/src/CustomerPortal/class-accountendpoint.php' );
		$this->orders   = (string) file_get_contents( $root . '/src/Integrations/WooCommerce/class-orderlicensing.php' );
		$this->verifier = (string) file_get_contents( $root . '/scripts/verify-live-customer-isolation.php' );
	}

	public function test_account_list_and_reveal_are_scoped_to_the_current_customer(): void {
		self::assertStringContainsString( '$this->licenses->for_customer( get_current_user_id() )', $this->endpoint );
		self::assertStringContainsString( "get_current_user_id() !== (int) \$license['customer_id']", $this->endpoint );
		self::assertStringContainsString( "wp_send_json_error( array( 'message'", $this->endpoint );
	}

	public function test_registered_order_rendering_uses_the_order_access_policy(): void {
		self::assertStringContainsString( 'if ( ! $this->customer_can_view( $order ) )', $this->orders );
		self::assertStringContainsString( '$this->access->allows( (int) $order->get_customer_id(), get_current_user_id()', $this->orders );
	}

	public function test_live_verifier_is_transaction_guarded_and_checks_both_actors(): void {
		self::assertStringContainsString( 'DisposableEnvironmentGuard::assertSafe(', $this->verifier );
		self::assertStringContainsString( "\$wpdb->query( 'START TRANSACTION' )", $this->verifier );
		self::assertStringContainsString( "\$wpdb->query( 'ROLLBACK' )", $this->verifier );
		self::assertStringContainsString( "\$checks['attacker_reveal_denied']", $this->verifier );
		self::assertStringContainsString( "\$checks['owner_reveal_allowed']", $this->verifier );
		self::assertStringContainsString( "\$checks['attacker_order_denied']", $this->verifier );
		self::assertStringContainsString( "\$checks['owner_order_allowed']", $this->verifier );
	}
}
