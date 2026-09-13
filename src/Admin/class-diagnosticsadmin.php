<?php
/**
 * Defines the DiagnosticsAdmin class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Admin;

use Dreamax\LicenseManager\Support\Capabilities;
use Dreamax\LicenseManager\Support\Diagnostics;

/**
 * Handles protected diagnostic downloads and delivery tests.
 */
final class DiagnosticsAdmin {
	/** Registers protected administration actions. */
	public function register(): void {
		add_action( 'admin_post_dreamax_lm_download_system_report', array( $this, 'download_report' ) );
		add_action( 'admin_post_dreamax_lm_send_test_email', array( $this, 'send_test_email' ) );
	}

	/** Downloads a secret-free JSON system report. */
	public function download_report(): void {
		$this->authorize();
		check_admin_referer( 'dreamax_lm_download_system_report' );
		$encoded = wp_json_encode( ( new Diagnostics() )->report(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $encoded ) ) {
			wp_die( esc_html__( 'The system report could not be generated.', 'dreamax-license-manager' ), 500 );
		}
		nocache_headers();
		header( 'Cache-Control: no-store, no-cache, must-revalidate, private' );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="dreamax-license-manager-system-report-' . gmdate( 'Ymd-His' ) . '.json"' );
		echo $encoded; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON is encoded from a fixed allowlisted diagnostic structure and sent as an attachment.
		exit;
	}

	/** Sends a harmless delivery test to the signed-in administrator. */
	public function send_test_email(): void {
		$this->authorize();
		check_admin_referer( 'dreamax_lm_send_test_email' );
		$user = wp_get_current_user();
		$sent = '' !== $user->user_email && wp_mail(
			$user->user_email,
			__( 'Dreamax License Manager email test', 'dreamax-license-manager' ),
			__( 'This confirms that WordPress accepted a test message from Dreamax License Manager. No license key or customer data is included.', 'dreamax-license-manager' )
		);
		wp_safe_redirect( add_query_arg( 'email_test', $sent ? 'sent' : 'failed', admin_url( 'admin.php?page=dreamax-license-manager-status' ) ) );
		exit;
	}

	/** Enforces diagnostic access. */
	private function authorize(): void {
		if ( ! current_user_can( Capabilities::DIAGNOSTICS ) ) {
			wp_die( esc_html__( 'You are not allowed to run license diagnostics.', 'dreamax-license-manager' ), 403 );
		}
	}
}
