<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class OrderPolicyAcceptanceSourceContractTest extends TestCase {
	private string $verifier;
	private string $licensing;
	private string $policies;
	private string $admin;

	protected function setUp(): void {
		$root            = dirname( __DIR__, 2 );
		$this->verifier  = (string) file_get_contents( $root . '/scripts/verify-live-order-policy-matrix.php' );
		$this->licensing = (string) file_get_contents( $root . '/src/Integrations/WooCommerce/class-orderlicensing.php' );
		$this->policies  = (string) file_get_contents( $root . '/src/Integrations/WooCommerce/class-orderpolicyservice.php' );
		$this->admin     = (string) file_get_contents( $root . '/src/Integrations/WooCommerce/class-orderworkflowadmin.php' );
	}

	public function test_live_matrix_covers_every_published_order_policy_boundary(): void {
		foreach ( array( 'partial_refund_high_slots', 'partial_refund_no_overlap', 'all_refund_policies', 'all_cancellation_policies', 'increase_requires_confirmation', 'explicit_backfill', 'decrease_retains', 'delete_retains_history', 'before_delivery_edit', 'pool_exhaustion', 'lifecycle_edits', 'resend_replay_recovery' ) as $contract ) {
			self::assertStringContainsString( $contract, $this->verifier );
		}
		self::assertStringContainsString( 'wc_create_refund(', $this->verifier );
	}

	public function test_release_and_quantity_safety_contracts_remain_in_production_source(): void {
		self::assertStringContainsString( "'release_blocked_suspended'", $this->policies );
		self::assertStringContainsString( "'pool' === \$source && 'assigned' === \$before", $this->policies );
		self::assertStringContainsString( "'refund_id'         => null === \$refund_id ? 0 : \$refund_id", $this->policies );
		self::assertStringContainsString( 'A post-delivery quantity increase cannot be allocated after a quantity refund.', $this->licensing );
		self::assertStringContainsString( 'ORDER_ITEM_DELETED_AFTER_DELIVERY', $this->licensing );
	}

	public function test_confirmed_order_tool_retains_capability_nonce_and_preview_binding(): void {
		self::assertStringContainsString( 'Capabilities::MANAGE', $this->admin );
		self::assertStringContainsString( "check_admin_referer( 'dreamax_lm_execute_order_tool' )", $this->admin );
		self::assertStringContainsString( "'1' !== \$confirmed", $this->admin );
		self::assertStringContainsString( 'preview expired or does not match this operation', $this->admin );
	}

	public function test_verifier_is_disposable_owned_and_prevents_outbound_mail(): void {
		self::assertStringContainsString( 'DisposableEnvironmentGuard::assertSafe(', $this->verifier );
		self::assertStringContainsString( "'pre_wp_mail'", $this->verifier );
		self::assertStringContainsString( 'dreamax_lm_f13_order( $policy_product, 1, $owner_id, false )', $this->verifier );
		self::assertStringContainsString( 'dreamax_lm_f13_order( $policy_product, 1, $target_id, false )', $this->verifier );
		self::assertStringContainsString( 'KeyNormalizer::IMPORTED', $this->verifier );
		self::assertStringNotContainsString( 'KeyNormalizer::EXACT', $this->verifier );
		self::assertStringContainsString( 'Recovery cleanup found an ambiguous orphan set.', $this->verifier );
		self::assertStringContainsString( 'owned_fixture_rows_remaining', $this->verifier );
		self::assertStringContainsString( 'aggregates_unchanged', $this->verifier );
	}
}
