<?php
/**
 * Defines the SettingsAdmin class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Admin;

use Dreamax\LicenseManager\Support\Capabilities;
use Dreamax\LicenseManager\Support\Settings;

/**
 * Provides merchant-facing delivery and customer-management settings.
 */
final class SettingsAdmin {
	/**
	 * Registers the settings screen and protected save action.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 10 );
		add_action( 'admin_post_dreamax_lm_save_settings', array( $this, 'save' ) );
	}

	/**
	 * Registers the settings submenu.
	 */
	public function menu(): void {
		add_submenu_page( 'dreamax-license-manager', __( 'License settings', 'dreamax-license-manager' ), __( 'Settings', 'dreamax-license-manager' ), Capabilities::MANAGE, 'dreamax-license-manager-settings', array( $this, 'page' ) );
	}

	/**
	 * Renders bounded operational settings.
	 */
	public function page(): void {
		$this->authorize();
		$selected = Settings::allocation_statuses();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This value displays a notice after the nonce-protected save action.
		$saved = isset( $_GET['saved'] );

		echo '<div class="wrap dreamax-lm-admin dreamax-lm-settings-page"><header class="dreamax-lm-page-header"><div><p class="dreamax-lm-eyebrow">' . esc_html__( 'Store workflow', 'dreamax-license-manager' ) . '</p><h1>' . esc_html__( 'License settings', 'dreamax-license-manager' ) . '</h1><p class="dreamax-lm-page-intro">' . esc_html__( 'Choose when paid orders receive licenses and what customers may manage.', 'dreamax-license-manager' ) . '</p></div></header>';
		if ( $saved ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'License settings saved.', 'dreamax-license-manager' ) . '</p></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="dreamax-lm-settings-form"><input type="hidden" name="action" value="dreamax_lm_save_settings">';
		wp_nonce_field( 'dreamax_lm_save_settings' );
		echo '<section class="dreamax-lm-panel"><div class="dreamax-lm-panel-heading"><div><h2>' . esc_html__( 'Automatic delivery', 'dreamax-license-manager' ) . '</h2><p>' . esc_html__( 'Allocation remains idempotent if an order enters more than one selected status.', 'dreamax-license-manager' ) . '</p></div></div><fieldset class="dreamax-lm-settings-options"><legend>' . esc_html__( 'Allocate licenses when an order reaches', 'dreamax-license-manager' ) . '</legend>';
		foreach ( Settings::order_statuses() as $status => $label ) {
			echo '<label><input type="checkbox" name="allocation_statuses[]" value="' . esc_attr( $status ) . '" ' . checked( in_array( $status, $selected, true ), true, false ) . '><span>' . esc_html( $label ) . '</span></label>';
		}
		echo '</fieldset></section>';
		echo '<section class="dreamax-lm-panel"><div class="dreamax-lm-panel-heading"><div><h2>' . esc_html__( 'Customer installations', 'dreamax-license-manager' ) . '</h2><p>' . esc_html__( 'Customers can manage only activations belonging to their own licenses.', 'dreamax-license-manager' ) . '</p></div></div><div class="dreamax-lm-settings-options"><label><input type="checkbox" name="customer_activation_management" value="1" ' . checked( Settings::customer_activation_management(), true, false ) . '><span><strong>' . esc_html__( 'Allow activation management in the customer portal', 'dreamax-license-manager' ) . '</strong><small>' . esc_html__( 'Customers may activate a new installation or deactivate an old one when the license policy permits.', 'dreamax-license-manager' ) . '</small></span></label></div></section>';
		echo '<div class="dreamax-lm-settings-actions"><button class="button button-primary" type="submit">' . esc_html__( 'Save settings', 'dreamax-license-manager' ) . '</button></div></form></div>';
	}

	/**
	 * Saves normalized settings after capability and nonce checks.
	 */
	public function save(): void {
		$this->authorize();
		check_admin_referer( 'dreamax_lm_save_settings' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each array member is sanitized and allowlisted immediately below.
		$submitted = isset( $_POST['allocation_statuses'] ) && is_array( $_POST['allocation_statuses'] ) ? wp_unslash( $_POST['allocation_statuses'] ) : array();
		$known     = Settings::order_statuses();
		$statuses  = array();
		foreach ( $submitted as $status ) {
			$status = sanitize_key( (string) $status );
			if ( isset( $known[ $status ] ) && ! in_array( $status, $statuses, true ) ) {
				$statuses[] = $status;
			}
		}
		if ( array() === $statuses ) {
			$statuses = array( 'processing', 'completed' );
		}
		update_option( Settings::OPTION_ALLOCATION_STATUSES, $statuses, false );
		update_option( Settings::OPTION_CUSTOMER_ACTIVATION_MANAGEMENT, isset( $_POST['customer_activation_management'] ) ? 1 : 0, false );
		wp_safe_redirect( admin_url( 'admin.php?page=dreamax-license-manager-settings&saved=1' ) );
		exit;
	}

	/**
	 * Enforces ordinary license-management access.
	 */
	private function authorize(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You are not allowed to configure license delivery.', 'dreamax-license-manager' ), 403 );
		}
	}
}
