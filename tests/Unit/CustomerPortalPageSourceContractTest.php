<?php
/**
 * Verifies the one-click customer portal page setup contract.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CustomerPortalPageSourceContractTest extends TestCase {
	private string $page;
	private string $plugin;

	protected function setUp(): void {
		$root         = dirname( __DIR__, 2 );
		$this->page   = (string) file_get_contents( $root . '/src/Admin/class-customerportalpage.php' );
		$this->plugin = (string) file_get_contents( $root . '/src/class-plugin.php' );
	}

	public function test_customer_portal_setup_is_registered_under_the_existing_admin_menu(): void {
		self::assertStringContainsString( "private const MENU_SLUG      = 'dreamax-license-manager-customer-portal'", $this->page );
		self::assertStringContainsString( "add_submenu_page(\n\t\t\t'dreamax-license-manager'", $this->page );
		self::assertStringContainsString( 'Capabilities::MANAGE', $this->page );
		self::assertStringContainsString( '( new CustomerPortalPage() )->register();', $this->plugin );
	}

	public function test_page_creation_is_protected_published_and_shortcode_connected(): void {
		self::assertStringContainsString( "check_admin_referer( 'dreamax_lm_create_customer_portal' )", $this->page );
		self::assertStringContainsString( 'current_user_can( Capabilities::MANAGE )', $this->page );
		self::assertStringContainsString( "'post_status'  => 'publish'", $this->page );
		self::assertStringContainsString( "private const SHORTCODE      = 'dreamax_license_dashboard'", $this->page );
		self::assertStringContainsString( 'wp_insert_post(', $this->page );
		self::assertStringContainsString( 'wp_safe_redirect(', $this->page );
	}

	public function test_repeat_submission_reuses_the_configured_page(): void {
		self::assertStringContainsString( 'if ( $current instanceof WP_Post )', $this->page );
		self::assertStringContainsString( 'update_option( self::OPTION_PAGE_ID, $page_id, false )', $this->page );
		self::assertStringContainsString( 'has_shortcode( $page->post_content, self::SHORTCODE )', $this->page );
		self::assertStringContainsString( "'ready'", $this->page );
	}
}
