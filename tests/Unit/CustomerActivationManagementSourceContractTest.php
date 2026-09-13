<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CustomerActivationManagementSourceContractTest extends TestCase {
	public function test_customer_actions_require_owned_license_and_per_license_nonce(): void {
		$dashboard = $this->read( 'src/CustomerPortal/class-licensedashboard.php' );

		self::assertStringContainsString( 'admin_post_dreamax_lm_customer_activate', $dashboard );
		self::assertStringContainsString( 'admin_post_dreamax_lm_customer_deactivate', $dashboard );
		self::assertStringContainsString( "check_admin_referer( 'dreamax_lm_customer_activation_' . \$public_id )", $dashboard );
		self::assertStringContainsString( "get_current_user_id() !== (int) \$license['customer_id']", $dashboard );
		self::assertStringContainsString( 'Settings::customer_activation_management()', $dashboard );
	}

	public function test_customer_deactivation_binds_opaque_activation_to_owned_license(): void {
		$service = $this->read( 'src/Activations/class-activationservice.php' );

		self::assertStringContainsString( 'public function deactivate_record(', $service );
		self::assertStringContainsString( 'WHERE license_id=%d AND public_id=%s LIMIT 1 FOR UPDATE', $service );
		self::assertStringContainsString( "'status' => 'active'", $service );
		self::assertStringContainsString( 'AuditEventCatalog::LICENSE_DEACTIVATED', $service );
	}

	public function test_portal_lists_escaped_labels_without_exposing_instance_fingerprints(): void {
		$repository = $this->read( 'src/Activations/class-activationrepository.php' );
		$dashboard  = $this->read( 'src/CustomerPortal/class-licensedashboard.php' );

		self::assertStringContainsString( 'instance_label,status', $repository );
		self::assertStringNotContainsString( 'instance_fingerprint', $repository );
		self::assertStringContainsString( "esc_html( \$label )", $dashboard );
		self::assertStringContainsString( 'dreamax-lm-dashboard-activation-form', $dashboard );
	}

	private function read( string $relative_path ): string {
		$contents = file_get_contents( dirname( __DIR__, 2 ) . '/' . $relative_path );
		self::assertIsString( $contents );
		return $contents;
	}
}
