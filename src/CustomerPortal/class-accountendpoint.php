<?php
/**
 * Defines the AccountEndpoint class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\CustomerPortal;

use Dreamax\LicenseManager\Api\TransportGuard;
use Dreamax\LicenseManager\Events\AuditEventCatalog;
use Dreamax\LicenseManager\Events\EventRepository;
use Dreamax\LicenseManager\Licenses\LicenseRepository;
use Throwable;

/**
 * Handles Account endpoint operations.
 */
final class AccountEndpoint {
	private const PORTAL_PAGE_OPTION = 'dreamax_lm_customer_portal_page_id';
	private const PORTAL_SHORTCODE   = 'dreamax_license_dashboard';

	/**
	 * Licenses value.
	 *
	 * @var LicenseRepository
	 */
	private LicenseRepository $licenses;

	/**
	 * Initializes the service.
	 */
	public function __construct() {
		$this->licenses = new LicenseRepository();
	}

	/**
	 * Handles the register operation.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'endpoint' ) );
		add_action( 'template_redirect', array( $this, 'redirect_to_standalone_portal' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'menu' ) );
		add_filter( 'woocommerce_get_endpoint_url', array( $this, 'standalone_portal_endpoint_url' ), 10, 4 );
		add_filter( 'body_class', array( $this, 'body_classes' ) );
		add_action( 'woocommerce_account_licenses_endpoint', array( $this, 'render' ) );
		add_action( 'wp_ajax_dreamax_lm_reveal', array( $this, 'reveal' ) );
		add_action( 'admin_post_dreamax_lm_guest_claim_issue', array( $this, 'issue_claim' ) );
		add_action( 'admin_post_dreamax_lm_guest_claim_verify', array( $this, 'verify_claim' ) );
	}

	/**
	 * Handles the endpoint operation.
	 */
	public function endpoint(): void {
		add_rewrite_endpoint( 'licenses', EP_ROOT | EP_PAGES );
	}

	/**
	 * Handles the menu operation.
	 *
	 * @param array $items Items value.
	 * @phpstan-param array<string,string> $items Items value.
	 * @return non-empty-array<string,string>
	 */
	public function menu( array $items ): array {
		$logout = $items['customer-logout'] ?? null;
		unset( $items['customer-logout'] );
		$items['licenses'] = __( 'Licenses', 'dreamax-license-manager' );
		if ( null !== $logout ) {
			$items['customer-logout'] = $logout;
		}
		return $items;
	}

	/**
	 * Points the WooCommerce licenses menu item at the standalone dashboard.
	 *
	 * @param mixed $url       Generated endpoint URL.
	 * @param mixed $endpoint  Endpoint name.
	 * @param mixed $value     Endpoint value.
	 * @param mixed $permalink Account permalink.
	 */
	public function standalone_portal_endpoint_url( $url, $endpoint, $value, $permalink ): string {
		unset( $value, $permalink );
		$fallback = is_string( $url ) ? $url : '';
		if ( 'licenses' !== $endpoint ) {
			return $fallback;
		}

		$portal_url = $this->standalone_portal_url();
		return '' !== $portal_url ? $portal_url : $fallback;
	}

	/**
	 * Redirects bookmarked WooCommerce license endpoints to the standalone portal.
	 */
	public function redirect_to_standalone_portal(): void {
		if ( ! $this->is_licenses_endpoint() ) {
			return;
		}

		$portal_url = $this->standalone_portal_url();
		if ( '' === $portal_url ) {
			return;
		}

		wp_safe_redirect( $portal_url );
		exit;
	}

	/**
	 * Loads the account assets before the document head renders.
	 */
	public function enqueue_assets(): void {
		if ( ! $this->is_licenses_endpoint() ) {
			return;
		}

		wp_enqueue_style( 'dreamax-lm-account', DREAMAX_LM_URL . 'assets/css/account.css', array(), DREAMAX_LM_VERSION );
		wp_enqueue_script( 'dreamax-lm-account', DREAMAX_LM_URL . 'assets/js/account.js', array(), DREAMAX_LM_VERSION, true );
		wp_localize_script(
			'dreamax-lm-account',
			'dreamaxLmAccount',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'dreamax_lm_reveal' ),
				'unable'  => __( 'Unable to reveal', 'dreamax-license-manager' ),
				'copied'  => __( 'Copied', 'dreamax-license-manager' ),
			)
		);
	}

	/**
	 * Adds an endpoint-specific body class for safely scoped layout repairs.
	 *
	 * @param array $classes Existing body classes.
	 * @phpstan-param array<int,string> $classes Existing body classes.
	 * @return array<int,string>
	 */
	public function body_classes( array $classes ): array {
		if ( $this->is_licenses_endpoint() ) {
			$classes[] = 'dreamax-lm-account-page';
		}

		return $classes;
	}

	/**
	 * Handles the render operation.
	 */
	public function render(): void {
		$this->send_private_cache_headers();
		$rows  = $this->licenses->for_customer( get_current_user_id() );
		$count = count( $rows );

		echo '<div class="dreamax-lm-account">';
		$this->render_claim_notice();
		echo '<header class="dreamax-lm-account__header"><div><p class="dreamax-lm-account__eyebrow">' . esc_html__( 'Software access', 'dreamax-license-manager' ) . '</p><h1>' . esc_html__( 'Your licenses', 'dreamax-license-manager' ) . '</h1><p>' . esc_html__( 'View the licenses connected to your account and securely copy a key when you need it.', 'dreamax-license-manager' ) . '</p></div>';
		/* translators: %d: number of licenses linked to the customer account. */
		$license_count = sprintf( _n( '%d license', '%d licenses', $count, 'dreamax-license-manager' ), $count );
		echo '<span class="dreamax-lm-account__count">' . esc_html( $license_count ) . '</span></header>';

		echo '<section class="dreamax-lm-panel dreamax-lm-license-panel" aria-labelledby="dreamax-lm-license-heading"><div class="dreamax-lm-panel__heading"><div><h2 id="dreamax-lm-license-heading">' . esc_html__( 'Available licenses', 'dreamax-license-manager' ) . '</h2><p>' . esc_html__( 'Keys stay hidden until you choose to reveal and copy one.', 'dreamax-license-manager' ) . '</p></div></div>';
		if ( array() === $rows ) {
			echo '<div class="dreamax-lm-empty"><strong>' . esc_html__( 'No licenses yet', 'dreamax-license-manager' ) . '</strong><p>' . esc_html__( 'A license will appear here after an eligible order is connected to this account.', 'dreamax-license-manager' ) . '</p></div>';
		} else {
			echo '<div class="dreamax-lm-table-wrap"><table class="shop_table shop_table_responsive dreamax-lm-license-table"><thead><tr><th>' . esc_html__( 'License', 'dreamax-license-manager' ) . '</th><th>' . esc_html__( 'Status', 'dreamax-license-manager' ) . '</th><th>' . esc_html__( 'Expiry', 'dreamax-license-manager' ) . '</th><th>' . esc_html__( 'Actions', 'dreamax-license-manager' ) . '</th></tr></thead><tbody>';
			foreach ( $rows as $row ) {
				$key          = $this->licenses->decrypt_key( $row );
				$masked       = strlen( $key ) > 8 ? substr( $key, 0, 4 ) . str_repeat( "\xE2\x80\xA2", min( 12, strlen( $key ) - 8 ) ) . substr( $key, -4 ) : str_repeat( "\xE2\x80\xA2", strlen( $key ) );
				$status       = (string) $row['lifecycle_status'];
				$status_label = ucwords( str_replace( array( '-', '_' ), ' ', $status ) );
				echo '<tr><td data-title="' . esc_attr__( 'License', 'dreamax-license-manager' ) . '"><code class="dreamax-lm-key" id="dreamax-key-' . esc_attr( (string) $row['public_id'] ) . '" aria-live="polite">' . esc_html( $masked ) . '</code></td>';
				echo '<td data-title="' . esc_attr__( 'Status', 'dreamax-license-manager' ) . '"><span class="dreamax-lm-status dreamax-lm-status--' . esc_attr( sanitize_html_class( strtolower( $status ) ) ) . '">' . esc_html( $status_label ) . '</span></td>';
				echo '<td data-title="' . esc_attr__( 'Expiry', 'dreamax-license-manager' ) . '">' . esc_html( $row['expires_at'] ? wc_format_datetime( new \WC_DateTime( (string) $row['expires_at'], new \DateTimeZone( 'UTC' ) ) ) : __( 'Never', 'dreamax-license-manager' ) ) . '</td>';
				echo '<td data-title="' . esc_attr__( 'Actions', 'dreamax-license-manager' ) . '"><button type="button" class="button dreamax-lm-reveal" data-license="' . esc_attr( (string) $row['public_id'] ) . '" aria-controls="dreamax-key-' . esc_attr( (string) $row['public_id'] ) . '">' . esc_html__( 'Reveal and copy', 'dreamax-license-manager' ) . '</button></td></tr>';
			}
			echo '</tbody></table></div>';
		}
		echo '</section>';

		echo '<section class="dreamax-lm-panel dreamax-lm-guest-claim" aria-labelledby="dreamax-lm-claim-heading"><div class="dreamax-lm-panel__heading"><div><p class="dreamax-lm-panel__step">' . esc_html__( 'Guest purchase', 'dreamax-license-manager' ) . '</p><h2 id="dreamax-lm-claim-heading">' . esc_html__( 'Claim an order', 'dreamax-license-manager' ) . '</h2><p>' . esc_html__( 'Connect licenses bought as a guest to this account in two secure steps.', 'dreamax-license-manager' ) . '</p></div></div><div class="dreamax-lm-claim-grid">';
		echo '<form class="dreamax-lm-claim-card" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dreamax_lm_guest_claim_issue">';
		wp_nonce_field( 'dreamax_lm_guest_claim_issue' );
		echo '<div class="dreamax-lm-claim-card__heading"><span>1</span><div><h3>' . esc_html__( 'Request a claim code', 'dreamax-license-manager' ) . '</h3><p>' . esc_html__( 'We send a one-time code only to the order billing email. It expires after 30 minutes by default.', 'dreamax-license-manager' ) . '</p></div></div>';
		echo '<div class="dreamax-lm-fields"><p><label for="dreamax-lm-issue-order">' . esc_html__( 'Order number', 'dreamax-license-manager' ) . '</label><input id="dreamax-lm-issue-order" type="number" name="order_id" min="1" inputmode="numeric" required></p><p><label for="dreamax-lm-billing-email">' . esc_html__( 'Billing email', 'dreamax-license-manager' ) . '</label><input id="dreamax-lm-billing-email" type="email" name="billing_email" autocomplete="email" required></p></div>';
		echo '<button type="submit" class="button dreamax-lm-button">' . esc_html__( 'Email claim code', 'dreamax-license-manager' ) . '</button></form>';
		echo '<form class="dreamax-lm-claim-card" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dreamax_lm_guest_claim_verify">';
		wp_nonce_field( 'dreamax_lm_guest_claim_verify' );
		echo '<div class="dreamax-lm-claim-card__heading"><span>2</span><div><h3>' . esc_html__( 'Connect the licenses', 'dreamax-license-manager' ) . '</h3><p>' . esc_html__( 'Enter the order number and the one-time code from your email to finish.', 'dreamax-license-manager' ) . '</p></div></div>';
		echo '<div class="dreamax-lm-fields"><p><label for="dreamax-lm-verify-order">' . esc_html__( 'Order number', 'dreamax-license-manager' ) . '</label><input id="dreamax-lm-verify-order" type="number" name="order_id" min="1" inputmode="numeric" required></p><p><label for="dreamax-lm-claim-code">' . esc_html__( 'One-time code', 'dreamax-license-manager' ) . '</label><input id="dreamax-lm-claim-code" type="password" name="claim_code" minlength="43" maxlength="43" autocomplete="one-time-code" required></p></div>';
		echo '<button type="submit" class="button alt dreamax-lm-button dreamax-lm-button--primary">' . esc_html__( 'Claim order licenses', 'dreamax-license-manager' ) . '</button></form></div></section></div>';
	}

	/**
	 * Reports whether the current request is the private licenses endpoint.
	 */
	private function is_licenses_endpoint(): bool {
		return function_exists( 'is_account_page' ) && function_exists( 'is_wc_endpoint_url' ) && is_account_page() && is_wc_endpoint_url( 'licenses' );
	}

	/**
	 * Returns the configured standalone portal URL when its page is usable.
	 */
	private function standalone_portal_url(): string {
		$page_id = absint( get_option( self::PORTAL_PAGE_OPTION, 0 ) );
		$page    = $page_id > 0 ? get_post( $page_id ) : null;
		if ( ! $page instanceof \WP_Post || 'publish' !== $page->post_status || ! has_shortcode( $page->post_content, self::PORTAL_SHORTCODE ) ) {
			return '';
		}

		$url = get_permalink( $page );
		return is_string( $url ) ? $url : '';
	}

	/**
	 * Handles authenticated claim issuance.
	 */
	public function issue_claim(): void {
		check_admin_referer( 'dreamax_lm_guest_claim_issue' );
		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			wp_die( esc_html__( 'Authentication is required.', 'dreamax-license-manager' ), '', array( 'response' => 403 ) );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$email    = isset( $_POST['billing_email'] ) ? sanitize_email( wp_unslash( (string) $_POST['billing_email'] ) ) : '';
		try {
			( new TransportGuard() )->assert_interactive_request();
			( new GuestClaimService() )->issue( $user_id, $order_id, $email );
		} catch ( Throwable $error ) {
			// The response remains identical for invalid, missing, throttled, and unavailable claims.
			unset( $error );
		}
		$this->redirect_to_licenses( 'requested' );
	}

	/**
	 * Handles authenticated claim verification.
	 */
	public function verify_claim(): void {
		check_admin_referer( 'dreamax_lm_guest_claim_verify' );
		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			wp_die( esc_html__( 'Authentication is required.', 'dreamax-license-manager' ), '', array( 'response' => 403 ) );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$token    = isset( $_POST['claim_code'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['claim_code'] ) ) : '';
		try {
			( new TransportGuard() )->assert_interactive_request();
			$result = ( new GuestClaimService() )->verify( $user_id, $order_id, $token );
		} catch ( Throwable $error ) {
			$result = ( new GuestClaimPolicy() )->public_failure();
		}
		$this->redirect_to_licenses( $result['success'] ? 'claimed' : 'failed' );
	}

	/**
	 * Handles the reveal operation.
	 */
	public function reveal(): void {
		$this->send_private_cache_headers();
		check_ajax_referer( 'dreamax_lm_reveal', 'nonce' );
		$public_id = isset( $_POST['license'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['license'] ) ) : '';
		$license   = $this->licenses->by_public_id( $public_id );
		if ( ! is_array( $license ) || get_current_user_id() !== (int) $license['customer_id'] ) {
			wp_send_json_error( array( 'message' => __( 'You cannot access that license.', 'dreamax-license-manager' ) ), 403 );
		}
		( new EventRepository() )->append( AuditEventCatalog::LICENSE_REVEALED, (int) $license['id'], 'customer', get_current_user_id(), null, array( 'channel' => 'my_account' ), AuditEventCatalog::SCHEMA_V1 );
		wp_send_json_success( array( 'key' => $this->licenses->decrypt_key( $license ) ) );
	}

	/**
	 * Sends the private cache contract for customer license responses.
	 */
	private function send_private_cache_headers(): void {
		nocache_headers();
		header( 'Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0', true );
		header( 'Pragma: no-cache', true );
		header( 'Expires: Wed, 11 Jan 1984 05:00:00 GMT', true );
	}

	/**
	 * Redirects back to the private WooCommerce licenses endpoint.
	 *
	 * @param string $result Allowlisted claim result identifier.
	 */
	private function redirect_to_licenses( string $result ): void {
		$fallback = wc_get_account_endpoint_url( 'licenses' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Both form handlers verify their action-specific nonce before reaching this redirect.
		$return_url  = isset( $_POST['dreamax_lm_return_url'] ) ? esc_url_raw( wp_unslash( (string) $_POST['dreamax_lm_return_url'] ) ) : '';
		$destination = '' !== $return_url ? wp_validate_redirect( $return_url, $fallback ) : $fallback;
		$destination = add_query_arg( 'dreamax_lm_claim_result', $result, $destination );

		wp_safe_redirect( $destination );
		exit;
	}

	/**
	 * Renders a session-independent claim result after the admin-post redirect.
	 */
	private function render_claim_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This allowlisted value selects a read-only customer notice after a nonce-protected POST.
		$result  = isset( $_GET['dreamax_lm_claim_result'] ) ? sanitize_key( wp_unslash( (string) $_GET['dreamax_lm_claim_result'] ) ) : '';
		$notices = array(
			'requested' => array( 'notice', __( 'If the guest order is eligible, a one-time code has been sent to its billing email.', 'dreamax-license-manager' ) ),
			'claimed'   => array( 'success', __( 'The order licenses are now linked to your account.', 'dreamax-license-manager' ) ),
			'failed'    => array( 'error', __( 'The claim could not be completed. Check the details or request a new code.', 'dreamax-license-manager' ) ),
		);
		if ( ! isset( $notices[ $result ] ) ) {
			return;
		}

		echo '<div class="dreamax-lm-claim-notice dreamax-lm-claim-notice--' . esc_attr( $notices[ $result ][0] ) . '" role="status"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><p>' . esc_html( $notices[ $result ][1] ) . '</p></div>';
	}
}
