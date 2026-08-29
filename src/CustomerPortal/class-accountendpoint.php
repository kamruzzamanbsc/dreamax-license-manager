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
		add_filter( 'woocommerce_account_menu_items', array( $this, 'menu' ) );
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
	 * Handles the render operation.
	 */
	public function render(): void {
		$this->send_private_cache_headers();
		$rows = $this->licenses->for_customer( get_current_user_id() );
		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'You do not have any licenses yet.', 'dreamax-license-manager' ) . '</p>';
		}
		$nonce = wp_create_nonce( 'dreamax_lm_reveal' );
		echo '<table class="shop_table shop_table_responsive"><thead><tr><th>' . esc_html__( 'License', 'dreamax-license-manager' ) . '</th><th>' . esc_html__( 'Status', 'dreamax-license-manager' ) . '</th><th>' . esc_html__( 'Expiry', 'dreamax-license-manager' ) . '</th><th>' . esc_html__( 'Actions', 'dreamax-license-manager' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$key    = $this->licenses->decrypt_key( $row );
			$masked = strlen( $key ) > 8 ? substr( $key, 0, 4 ) . str_repeat( '•', min( 12, strlen( $key ) - 8 ) ) . substr( $key, -4 ) : str_repeat( '•', strlen( $key ) );
			echo '<tr><td data-title="' . esc_attr__( 'License', 'dreamax-license-manager' ) . '"><code class="dreamax-lm-key" id="dreamax-key-' . esc_attr( (string) $row['public_id'] ) . '">' . esc_html( $masked ) . '</code></td>';
			echo '<td data-title="' . esc_attr__( 'Status', 'dreamax-license-manager' ) . '">' . esc_html( (string) $row['lifecycle_status'] ) . '</td>';
			echo '<td data-title="' . esc_attr__( 'Expiry', 'dreamax-license-manager' ) . '">' . esc_html( $row['expires_at'] ? wc_format_datetime( new \WC_DateTime( (string) $row['expires_at'], new \DateTimeZone( 'UTC' ) ) ) : __( 'Never', 'dreamax-license-manager' ) ) . '</td>';
			echo '<td><button type="button" class="button dreamax-lm-reveal" data-license="' . esc_attr( (string) $row['public_id'] ) . '">' . esc_html__( 'Reveal and copy', 'dreamax-license-manager' ) . '</button></td></tr>';
		}
		echo '</tbody></table>';
		$ajax   = admin_url( 'admin-ajax.php' );
		$script = "document.querySelectorAll('.dreamax-lm-reveal').forEach(function(b){b.addEventListener('click',async function(){b.disabled=true;try{const p=new URLSearchParams({action:'dreamax_lm_reveal',nonce:'" . esc_js( $nonce ) . "',license:b.dataset.license});const r=await fetch('" . esc_url( $ajax ) . "',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:p});const j=await r.json();if(!j.success){throw new Error(j.data&&j.data.message?j.data.message:'Unable to reveal');}const el=document.getElementById('dreamax-key-'+b.dataset.license);el.textContent=j.data.key;await navigator.clipboard.writeText(j.data.key);b.textContent='" . esc_js( __( 'Copied', 'dreamax-license-manager' ) ) . "';}catch(e){window.alert(e.message);}finally{b.disabled=false;}});});";
		wp_print_inline_script_tag( $script );

		echo '<section class="dreamax-lm-guest-claim"><h2>' . esc_html__( 'Claim a guest order', 'dreamax-license-manager' ) . '</h2><p>' . esc_html__( 'Request a one-time code for a guest order. The code is sent only to the order billing email and expires after 30 minutes by default.', 'dreamax-license-manager' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dreamax_lm_guest_claim_issue">';
		wp_nonce_field( 'dreamax_lm_guest_claim_issue' );
		echo '<p><label>' . esc_html__( 'Order number', 'dreamax-license-manager' ) . ' <input type="number" name="order_id" min="1" required></label></p><p><label>' . esc_html__( 'Billing email', 'dreamax-license-manager' ) . ' <input type="email" name="billing_email" autocomplete="email" required></label></p>';
		echo '<button type="submit" class="button">' . esc_html__( 'Email claim code', 'dreamax-license-manager' ) . '</button></form>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:1em"><input type="hidden" name="action" value="dreamax_lm_guest_claim_verify">';
		wp_nonce_field( 'dreamax_lm_guest_claim_verify' );
		echo '<p><label>' . esc_html__( 'Order number', 'dreamax-license-manager' ) . ' <input type="number" name="order_id" min="1" required></label></p><p><label>' . esc_html__( 'One-time code', 'dreamax-license-manager' ) . ' <input type="password" name="claim_code" minlength="43" maxlength="43" autocomplete="one-time-code" required></label></p>';
		echo '<button type="submit" class="button alt">' . esc_html__( 'Claim order licenses', 'dreamax-license-manager' ) . '</button></form></section>';
	}

	/**
	 * Handles authenticated claim issuance.
	 */
	public function issue_claim(): void {
		check_admin_referer( 'dreamax_lm_guest_claim_issue' );
		( new TransportGuard() )->assert_interactive_request();
		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			wp_die( esc_html__( 'Authentication is required.', 'dreamax-license-manager' ), '', array( 'response' => 403 ) );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$email    = isset( $_POST['billing_email'] ) ? sanitize_email( wp_unslash( (string) $_POST['billing_email'] ) ) : '';
		try {
			( new GuestClaimService() )->issue( $user_id, $order_id, $email );
		} catch ( Throwable $error ) {
			// The response remains identical for invalid, missing, throttled, and unavailable claims.
			unset( $error );
		}
		wc_add_notice( __( 'If the guest order is eligible, a one-time code has been sent to its billing email.', 'dreamax-license-manager' ), 'notice' );
		$this->redirect_to_licenses();
	}

	/**
	 * Handles authenticated claim verification.
	 */
	public function verify_claim(): void {
		check_admin_referer( 'dreamax_lm_guest_claim_verify' );
		( new TransportGuard() )->assert_interactive_request();
		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			wp_die( esc_html__( 'Authentication is required.', 'dreamax-license-manager' ), '', array( 'response' => 403 ) );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$token    = isset( $_POST['claim_code'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['claim_code'] ) ) : '';
		try {
			$result = ( new GuestClaimService() )->verify( $user_id, $order_id, $token );
		} catch ( Throwable $error ) {
			$result = ( new GuestClaimPolicy() )->public_failure();
		}
		$message = $result['success'] ? __( 'The order licenses are now linked to your account.', 'dreamax-license-manager' ) : __( 'The claim could not be completed. Check the details or request a new code.', 'dreamax-license-manager' );
		wc_add_notice( $message, $result['success'] ? 'success' : 'error' );
		$this->redirect_to_licenses();
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
	 */
	private function redirect_to_licenses(): void {
		wp_safe_redirect( wc_get_account_endpoint_url( 'licenses' ) );
		exit;
	}
}
