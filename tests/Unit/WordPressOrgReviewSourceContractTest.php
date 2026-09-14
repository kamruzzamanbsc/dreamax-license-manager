<?php
/**
 * Verifies the WordPress.org review-remediation source contract.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class WordPressOrgReviewSourceContractTest extends TestCase {
	private string $root;

	protected function setUp(): void {
		$this->root = dirname( __DIR__, 2 );
	}

	public function test_privileged_routes_use_real_scope_bound_permission_callbacks(): void {
		$privileged = $this->read( 'src/Api/class-privilegedroutes.php' );
		$public     = $this->read( 'src/Api/class-publicroutes.php' );

		self::assertStringNotContainsString( "'permission_callback' => '__return_true'", $privileged );
		self::assertSame( 2, substr_count( $public, "'permission_callback' => '__return_true'" ) );
		self::assertSame( 2, substr_count( $privileged, 'array( $this, \'can_read_licenses\' )' ) );
		self::assertSame( 3, substr_count( $privileged, 'array( $this, \'can_write_licenses\' )' ) );
		self::assertStringContainsString( 'array( $this, \'can_read_activations\' )', $privileged );
		self::assertStringContainsString( 'array( $this, \'can_read_generators\' )', $privileged );
		self::assertStringContainsString( 'assert_privileged_request( $request, $has_body )', $privileged );
		self::assertStringContainsString( '->assert_scope( $credential, $scope, $request_id, true )', $privileged );
		self::assertStringContainsString( 'normalize_permission_error', $privileged );
		self::assertStringContainsString( 'PERMISSION_CONTEXT', $privileged );
	}

	public function test_rest_protocol_values_come_from_the_request_and_query_input_is_sanitized(): void {
		$transport = $this->read( 'src/Api/class-transportguard.php' );

		self::assertStringNotContainsString( '$_SERVER[\'HTTP_AUTHORIZATION\']', $transport );
		self::assertStringNotContainsString( '$_SERVER[\'HTTP_IDEMPOTENCY_KEY\']', $transport );
		self::assertStringContainsString( '$request->get_header( \'Authorization\' )', $transport );
		self::assertStringContainsString( '$request->get_header( \'Idempotency-Key\' )', $transport );
		self::assertStringContainsString( 'map_deep( wp_unslash( $_GET ),', $transport );
		self::assertStringContainsString( 'sanitize_key( (string) $name )', $transport );
	}

	public function test_plugin_owned_assets_use_wordpress_enqueue_apis(): void {
		$admin   = $this->read( 'src/Admin/class-admin.php' );
		$account = $this->read( 'src/CustomerPortal/class-accountendpoint.php' );
		$script  = $this->read( 'assets/js/account.js' );

		self::assertStringNotContainsString( '<script', $admin );
		self::assertStringNotContainsString( '<link rel="stylesheet"', $admin );
		self::assertStringContainsString( "wp_enqueue_style( 'dreamax-lm-admin'", $admin );
		self::assertStringContainsString( "wp_enqueue_script( 'dreamax-lm-admin'", $admin );
		self::assertStringContainsString( "wp_print_styles( 'dreamax-lm-admin' )", $admin );
		self::assertStringContainsString( "wp_print_scripts( 'dreamax-lm-admin' )", $admin );
		self::assertStringNotContainsString( 'wp_print_inline_script_tag', $account );
		self::assertStringContainsString( "wp_enqueue_style( 'dreamax-lm-account'", $account );
		self::assertStringContainsString( "wp_enqueue_script( 'dreamax-lm-account'", $account );
		self::assertStringContainsString( "wp_localize_script(", $account );
		self::assertStringContainsString( "add_filter( 'body_class'", $account );
		self::assertStringContainsString( 'navigator.clipboard.writeText', $script );
		self::assertStringContainsString( "document.execCommand('copy')", $script );
	}

	public function test_woocommerce_saves_have_explicit_object_capability_checks(): void {
		$product_settings = $this->read( 'src/Integrations/WooCommerce/class-productsettings.php' );

		self::assertSame( 2, substr_count( $product_settings, "current_user_can( 'edit_post'" ) );
		self::assertSame( 2, substr_count( $product_settings, 'WooCommerce verifies the' ) );
	}

	public function test_public_metadata_states_full_local_functionality_without_trialware(): void {
		$plugin = $this->read( 'dreamax-license-manager.php' );
		$readme = $this->read( 'readme.txt' );

		self::assertStringContainsString( 'Version: 0.4.1', $plugin );
		self::assertStringContainsString( "DREAMAX_LM_VERSION', '0.4.1'", $plugin );
		self::assertStringContainsString( 'Stable tag: 0.4.1', $readme );
		self::assertStringContainsString( 'does not require a license key, payment, subscription, trial, quota, or external service', $readme );
		self::assertStringContainsString( 'Every feature included in this plugin is available without an upgrade.', $readme );
	}

	private function read( string $relative_path ): string {
		$contents = file_get_contents( $this->root . '/' . $relative_path );
		self::assertIsString( $contents );

		return $contents;
	}
}
