<?php
/**
 * Defines the ActivationAdmin class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Admin;

use Dreamax\LicenseManager\Activations\ActivationRepository;
use Dreamax\LicenseManager\Support\Capabilities;

/**
 * Provides a global privacy-conscious installation inventory.
 */
final class ActivationAdmin {
	/**
	 * Registers the activations submenu.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 10 );
	}

	/**
	 * Registers the screen below the plugin parent menu.
	 */
	public function menu(): void {
		add_submenu_page( 'dreamax-license-manager', __( 'License activations', 'dreamax-license-manager' ), __( 'Activations', 'dreamax-license-manager' ), Capabilities::MANAGE, 'dreamax-license-manager-activations', array( $this, 'page' ) );
	}

	/**
	 * Renders a bounded filtered inventory.
	 */
	public function page(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You are not allowed to view license activations.', 'dreamax-license-manager' ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only administration filters behind a capability check.
		$status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : '';
		$license = isset( $_GET['license'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['license'] ) ) : '';
		$page    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$rows = ( new ActivationRepository() )->search( $status, $license, $page );

		echo '<div class="wrap dreamax-lm-admin dreamax-lm-activations-page"><header class="dreamax-lm-page-header"><div><p class="dreamax-lm-eyebrow">' . esc_html__( 'Installation inventory', 'dreamax-license-manager' ) . '</p><h1>' . esc_html__( 'Activations', 'dreamax-license-manager' ) . '</h1><p class="dreamax-lm-page-intro">' . esc_html__( 'Review registered installations without exposing license keys or device fingerprints.', 'dreamax-license-manager' ) . '</p></div></header>';
		echo '<section class="dreamax-lm-panel"><form class="dreamax-lm-activation-filters" method="get"><input type="hidden" name="page" value="dreamax-license-manager-activations"><label class="dreamax-lm-field"><span>' . esc_html__( 'License public ID', 'dreamax-license-manager' ) . '</span><input type="text" name="license" value="' . esc_attr( $license ) . '" placeholder="lic_..."></label><label class="dreamax-lm-field"><span>' . esc_html__( 'Installation status', 'dreamax-license-manager' ) . '</span><select name="status"><option value="">' . esc_html__( 'All statuses', 'dreamax-license-manager' ) . '</option><option value="active" ' . selected( $status, 'active', false ) . '>' . esc_html__( 'Active', 'dreamax-license-manager' ) . '</option><option value="inactive" ' . selected( $status, 'inactive', false ) . '>' . esc_html__( 'Inactive', 'dreamax-license-manager' ) . '</option></select></label><button class="button" type="submit">' . esc_html__( 'Apply filters', 'dreamax-license-manager' ) . '</button></form>';
		echo '<div class="dreamax-lm-table-scroll"><table class="widefat striped dreamax-lm-table"><thead><tr><th>' . esc_html__( 'Installation', 'dreamax-license-manager' ) . '</th><th>' . esc_html__( 'License', 'dreamax-license-manager' ) . '</th><th>' . esc_html__( 'Customer', 'dreamax-license-manager' ) . '</th><th>' . esc_html__( 'Product', 'dreamax-license-manager' ) . '</th><th>' . esc_html__( 'Status', 'dreamax-license-manager' ) . '</th><th>' . esc_html__( 'Last seen', 'dreamax-license-manager' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$detail_url   = add_query_arg(
				array(
					'page'    => 'dreamax-license-manager-license',
					'license' => (string) $row['license_public_id'],
				),
				admin_url( 'admin.php' )
			);
			$label        = '' !== (string) ( $row['instance_label'] ?? '' ) ? (string) $row['instance_label'] : __( 'Unnamed installation', 'dreamax-license-manager' );
			$last_seen    = '' !== (string) ( $row['last_seen_at'] ?? '' ) ? (string) $row['last_seen_at'] : (string) $row['activated_at'];
			$customer_id  = (int) $row['customer_id'];
			$customer_url = $customer_id > 0 && get_userdata( $customer_id ) && current_user_can( 'edit_user', $customer_id ) ? get_edit_user_link( $customer_id ) : '';
			$product_id   = (int) ( $row['product_id'] ?? 0 );
			$product_url  = $product_id > 0 && 'product' === get_post_type( $product_id ) && current_user_can( 'edit_post', $product_id ) ? get_edit_post_link( $product_id, '' ) : '';
			echo '<tr><td><strong>' . esc_html( $label ) . '</strong><br><code>' . esc_html( (string) $row['public_id'] ) . '</code></td><td><a href="' . esc_url( $detail_url ) . '"><code>' . esc_html( (string) $row['license_public_id'] ) . '</code></a></td><td>';
			$customer_label = $customer_id > 0 ? '#' . $customer_id : __( 'Not linked', 'dreamax-license-manager' );
			echo is_string( $customer_url ) && '' !== $customer_url ? '<a href="' . esc_url( $customer_url ) . '">' . esc_html( $customer_label ) . '</a>' : esc_html( $customer_label );
			echo '</td><td>';
			echo is_string( $product_url ) && '' !== $product_url ? '<a href="' . esc_url( $product_url ) . '"><code>' . esc_html( (string) $row['product_public_id'] ) . '</code></a>' : '<code>' . esc_html( (string) $row['product_public_id'] ) . '</code>';
			echo '</td><td><span class="dreamax-lm-state dreamax-lm-state--' . esc_attr( sanitize_html_class( (string) $row['status'] ) ) . '">' . esc_html( ucfirst( (string) $row['status'] ) ) . '</span></td><td>' . esc_html( $last_seen ) . ' UTC</td></tr>';
		}
		if ( array() === $rows ) {
			echo '<tr><td colspan="6" class="dreamax-lm-empty dreamax-lm-empty--compact"><strong>' . esc_html__( 'No activations found', 'dreamax-license-manager' ) . '</strong><p>' . esc_html__( 'Try clearing the filters or activate a licensed installation.', 'dreamax-license-manager' ) . '</p></td></tr>';
		}
		echo '</tbody></table></div></section>';
		$base_url = remove_query_arg( 'paged' );
		echo '<nav class="dreamax-lm-pagination" aria-label="' . esc_attr__( 'Activation pages', 'dreamax-license-manager' ) . '">';
		if ( $page > 1 ) {
			echo '<a class="button" href="' . esc_url( add_query_arg( 'paged', $page - 1, $base_url ) ) . '">' . esc_html__( 'Previous', 'dreamax-license-manager' ) . '</a>';
		}
		if ( 50 === count( $rows ) ) {
			echo '<a class="button" href="' . esc_url( add_query_arg( 'paged', $page + 1, $base_url ) ) . '">' . esc_html__( 'Next', 'dreamax-license-manager' ) . '</a>';
		}
		echo '</nav></div>';
	}
}
