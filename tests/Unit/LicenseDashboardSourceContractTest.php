<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class LicenseDashboardSourceContractTest extends TestCase {
	private string $dashboard;
	private string $endpoint;
	private string $plugin;
	private string $script;
	private string $styles;
	private string $verifier;

	protected function setUp(): void {
		$root            = dirname( __DIR__, 2 );
		$this->dashboard = (string) file_get_contents( $root . '/src/CustomerPortal/class-licensedashboard.php' );
		$this->endpoint  = (string) file_get_contents( $root . '/src/CustomerPortal/class-accountendpoint.php' );
		$this->plugin    = (string) file_get_contents( $root . '/src/class-plugin.php' );
		$this->script    = (string) file_get_contents( $root . '/assets/js/account.js' );
		$this->styles    = (string) file_get_contents( $root . '/assets/css/dashboard.css' );
		$this->verifier  = (string) file_get_contents( $root . '/scripts/verify-live-license-dashboard.php' );
	}

	public function test_standalone_dashboard_is_registered_and_scoped_to_its_shortcode_page(): void {
		self::assertMatchesRegularExpression( "/private const SHORTCODE\\s+= 'dreamax_license_dashboard'/", $this->dashboard );
		self::assertStringContainsString( 'add_shortcode( self::SHORTCODE', $this->dashboard );
		self::assertStringContainsString( "wp_enqueue_style( 'dreamax-lm-dashboard'", $this->dashboard );
		self::assertStringContainsString( "'dreamax-lm-dashboard-page'", $this->dashboard );
		self::assertStringContainsString( '( new LicenseDashboard() )->register();', $this->plugin );
		self::assertStringContainsString( 'add_filter( \'the_title\', array( $this, \'hide_theme_page_title\' ), 10, 2 )', $this->dashboard );
	}

	public function test_woocommerce_license_navigation_prefers_the_configured_standalone_dashboard(): void {
		self::assertStringContainsString( "add_filter( 'woocommerce_get_endpoint_url', array( \$this, 'standalone_portal_endpoint_url' ), 10, 4 )", $this->endpoint );
		self::assertStringContainsString( "add_action( 'template_redirect', array( \$this, 'redirect_to_standalone_portal' ) )", $this->endpoint );
		self::assertStringContainsString( "private const PORTAL_PAGE_OPTION = 'dreamax_lm_customer_portal_page_id'", $this->endpoint );
		self::assertStringContainsString( "private const PORTAL_SHORTCODE   = 'dreamax_license_dashboard'", $this->endpoint );
		self::assertStringContainsString( "'publish' !== \$page->post_status", $this->endpoint );
		self::assertStringContainsString( 'has_shortcode( $page->post_content, self::PORTAL_SHORTCODE )', $this->endpoint );
		self::assertStringContainsString( 'wp_safe_redirect( $portal_url )', $this->endpoint );
	}

	public function test_theme_page_title_is_removed_only_from_the_current_main_loop(): void {
		self::assertStringContainsString( 'public function hide_theme_page_title( string $title, int $post_id ): string', $this->dashboard );
		self::assertStringContainsString( 'is_admin() || ! in_the_loop() || ! is_main_query()', $this->dashboard );
		self::assertStringContainsString( 'get_queried_object_id() !== $post_id', $this->dashboard );
		self::assertStringContainsString( "return '';", $this->dashboard );
	}

	public function test_dashboard_requires_authentication_and_reads_only_the_current_customer_rows(): void {
		self::assertStringContainsString( 'if ( ! is_user_logged_in() )', $this->dashboard );
		self::assertStringContainsString( '$this->licenses->for_customer( get_current_user_id() )', $this->dashboard );
		self::assertStringContainsString( 'wp_login_url(', $this->dashboard );
		self::assertStringContainsString( '<h1 id="dreamax-lm-login-title">', $this->dashboard );
		self::assertStringContainsString( 'Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0', $this->dashboard );
	}

	public function test_unavailable_key_material_degrades_without_disclosing_a_fatal_error(): void {
		self::assertStringContainsString( 'private function prepare_key_access( array $rows ): array', $this->dashboard );
		self::assertStringContainsString( 'catch ( Throwable )', $this->dashboard );
		self::assertStringContainsString( 'Secure key access is temporarily unavailable.', $this->dashboard );
		self::assertStringContainsString( 'disabled aria-disabled="true"', $this->dashboard );
		self::assertStringContainsString( 'dreamax-lm-dashboard-notice--warning', $this->styles );
		self::assertStringContainsString( '.dreamax-lm-dashboard .dreamax-lm-reveal:disabled', $this->styles );
		self::assertStringContainsString( 'align-content: start', $this->styles );
	}

	public function test_customer_workflows_remain_nonce_protected_and_return_safely(): void {
		self::assertStringContainsString( "wp_nonce_field( 'dreamax_lm_guest_claim_issue' )", $this->dashboard );
		self::assertStringContainsString( "wp_nonce_field( 'dreamax_lm_guest_claim_verify' )", $this->dashboard );
		self::assertStringContainsString( 'dreamax_lm_return_url', $this->dashboard );
		self::assertStringContainsString( 'wp_validate_redirect( $return_url, $fallback )', $this->endpoint );
		self::assertStringContainsString( 'wp_safe_redirect( $destination )', $this->endpoint );
		self::assertStringNotContainsString( 'wc_add_notice(', $this->endpoint );
		self::assertStringContainsString( "add_query_arg( 'dreamax_lm_claim_result', \$result, \$destination )", $this->endpoint );
		self::assertStringContainsString( '( new TransportGuard() )->assert_interactive_request();', $this->endpoint );
		self::assertStringContainsString( 'dreamax-lm-dashboard-notice', $this->dashboard );
	}

	public function test_dashboard_includes_premium_navigation_responsive_states_and_progressive_enhancement(): void {
		self::assertStringContainsString( 'data-dreamax-dashboard-target="overview"', $this->dashboard );
		self::assertStringContainsString( 'data-dreamax-dashboard-panel="licenses"', $this->dashboard );
		self::assertStringContainsString( 'dreamax-lm-dashboard-stats', $this->dashboard );
		self::assertStringContainsString( 'dreamax-lm-dashboard-license-list', $this->dashboard );
		self::assertStringContainsString( 'dreamax-lm-dashboard-claim-grid', $this->dashboard );
		self::assertStringContainsString( 'initializeDashboards', $this->script );
		self::assertStringContainsString( 'copyText', $this->script );
		self::assertStringNotContainsString( 'activePanel.scrollIntoView', $this->script );
		self::assertStringContainsString( 'function preserveMobileViewport(callback)', $this->script );
		self::assertStringContainsString( 'dashboard.style.minHeight = Math.ceil(dashboard.getBoundingClientRect().height)', $this->script );
		self::assertStringContainsString( "window.scrollTo({left: scrollLeft, top: scrollTop, behavior: 'auto'})", $this->script );
		self::assertStringContainsString( 'scroll-margin-top: 170px', $this->styles );
		self::assertStringContainsString( '@media screen and (max-width: 960px)', $this->styles );
		self::assertStringContainsString( '@media screen and (max-width: 540px)', $this->styles );
		self::assertStringContainsString( 'min-height: clamp(540px, calc(100vh - 175px), 680px)', $this->styles );
		self::assertStringContainsString( 'grid-template-columns: repeat(2, minmax(0, 1fr))', $this->styles );
		self::assertStringContainsString( 'min-height: 48px', $this->styles );
		self::assertStringContainsString( 'width: calc(100vw - 12px)', $this->styles );
		self::assertStringContainsString( 'padding: 18px 14px', $this->styles );
		self::assertStringContainsString( '@media screen and (max-width: 340px)', $this->styles );
	}

	public function test_live_verifier_covers_access_isolation_and_exact_cleanup(): void {
		foreach ( array( 'guest_login_only', 'owner_masked', 'attacker_isolated', 'activation_visible', 'assets_enqueued', 'database_restored' ) as $contract ) {
			self::assertStringContainsString( $contract, $this->verifier );
		}
		self::assertStringContainsString( 'DisposableEnvironmentGuard::assertSafe(', $this->verifier );
		self::assertStringContainsString( "'request_id' => \$request_id", $this->verifier );
		self::assertStringContainsString( 'wp_delete_user( $attacker_id )', $this->verifier );
	}
}
