<?php
/**
 * Defines the AccountEndpoint class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\CustomerPortal;

use Dreamax\LicenseManager\Events\EventRepository;
use Dreamax\LicenseManager\Licenses\LicenseRepository;

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
		nocache_headers();
		$rows = $this->licenses->for_customer( get_current_user_id() );
		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'You do not have any licenses yet.', 'dreamax-license-manager' ) . '</p>';
			return;
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
	}

	/**
	 * Handles the reveal operation.
	 */
	public function reveal(): void {
		nocache_headers();
		check_ajax_referer( 'dreamax_lm_reveal', 'nonce' );
		$public_id = isset( $_POST['license'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['license'] ) ) : '';
		$license   = $this->licenses->by_public_id( $public_id );
		if ( ! is_array( $license ) || get_current_user_id() !== (int) $license['customer_id'] ) {
			wp_send_json_error( array( 'message' => __( 'You cannot access that license.', 'dreamax-license-manager' ) ), 403 );
		}
		( new EventRepository() )->append( 'license_revealed', (int) $license['id'], 'customer', get_current_user_id(), null, array( 'channel' => 'my_account' ) );
		wp_send_json_success( array( 'key' => $this->licenses->decrypt_key( $license ) ) );
	}
}
