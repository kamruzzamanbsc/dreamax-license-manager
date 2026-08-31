<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class OrderToolsExperienceSourceContractTest extends TestCase {
	public function test_order_tools_use_the_shared_preview_first_admin_experience(): void {
		$source = $this->read( 'src/Integrations/WooCommerce/class-orderworkflowadmin.php' );

		self::assertStringContainsString( 'dreamax-lm-order-tools-page', $source );
		self::assertStringContainsString( 'data-dlm-order-preview', $source );
		self::assertStringContainsString( "admin_url( 'admin.php?page=dreamax-license-manager-order-tools' )", $source );
		self::assertStringContainsString( 'action="\' . esc_url( $page_url )', $source );
		self::assertStringContainsString( 'Preview is read-only', $source );
		self::assertStringContainsString( 'Order preview summary', $source );
		self::assertStringContainsString( 'dreamax-lm-order-action-grid', $source );
		self::assertStringContainsString( 'data-dlm-confirmed-form', $source );
		self::assertStringContainsString( 'data-dlm-claim-form', $source );
		self::assertStringContainsString( "'assigned_count'", $source );
		self::assertStringContainsString( "'has_billing_email'", $source );
		self::assertStringContainsString( 'aria-disabled=', $source );
		self::assertStringContainsString( 'if ( $available )', $source );
		self::assertStringContainsString( 'no paid, eligible order has a missing license slot', $source );
		self::assertStringNotContainsString( 'style="margin-top:1em"', $source );
	}

	public function test_order_tool_controls_remain_explicitly_bound_and_conditionally_gated(): void {
		$source = $this->read( 'src/Integrations/WooCommerce/class-orderworkflowadmin.php' );
		$orders = $this->read( 'src/Integrations/WooCommerce/class-orderlicensing.php' );
		$script = $this->read( 'assets/js/admin.js' );

		self::assertStringContainsString( "check_admin_referer( 'dreamax_lm_preview_order_tools' )", $source );
		self::assertStringContainsString( "check_admin_referer( 'dreamax_lm_execute_order_tool' )", $source );
		self::assertStringContainsString( "'1' !== \$confirmed", $source );
		self::assertStringContainsString( 'data-dlm-order-preview-submit disabled', $source );
		self::assertStringContainsString( 'data-dlm-confirm', $source );
		self::assertStringContainsString( 'data-dlm-submit disabled', $source );
		self::assertStringContainsString( 'submit.disabled = !orderIds.value.trim()', $script );
		self::assertStringContainsString( "operation.value === 'override'", $script );
		self::assertStringContainsString( 'target.required = overriding', $script );
		self::assertStringContainsString( "'assigned_count'", $orders );
		self::assertStringContainsString( '$assigned_count', $orders );
		self::assertStringContainsString( "'has_billing_email'", $orders );
		self::assertStringContainsString( 'is_email( (string) $order->get_billing_email() )', $orders );
	}

	public function test_order_tools_styles_are_scoped_and_responsive(): void {
		$styles = $this->read( 'assets/css/admin.css' );

		self::assertStringContainsString( '.dreamax-lm-order-tools-page', $styles );
		self::assertStringContainsString( '.dreamax-lm-order-tools-layout', $styles );
		self::assertStringContainsString( '.dreamax-lm-order-preview-table', $styles );
		self::assertStringContainsString( '.dreamax-lm-order-action-grid', $styles );
		self::assertStringContainsString( '.dreamax-lm-claim-fields', $styles );
		self::assertStringContainsString( 'grid-template-columns: 1fr', $styles );
	}

	private function read( string $relative_path ): string {
		$contents = file_get_contents( dirname( __DIR__, 2 ) . '/' . $relative_path );
		self::assertIsString( $contents );

		return $contents;
	}
}
