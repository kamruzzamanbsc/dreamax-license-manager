<?php
/**
 * Defines the customer portal page setup screen.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Admin;

use Dreamax\LicenseManager\Support\Capabilities;
use WP_Post;

/**
 * Creates and reports the standalone customer license dashboard page.
 */
final class CustomerPortalPage {
	private const MENU_SLUG     = 'dreamax-license-manager-customer-portal';
	public const OPTION_PAGE_ID = 'dreamax_lm_customer_portal_page_id';
	private const SHORTCODE     = 'dreamax_license_dashboard';

	/**
	 * Registers the setup screen and its protected action.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 10 );
		add_action( 'admin_post_dreamax_lm_create_customer_portal', array( $this, 'create_page' ) );
	}

	/**
	 * Registers the Customer Portal submenu.
	 */
	public function menu(): void {
		add_submenu_page(
			'dreamax-license-manager',
			__( 'Customer Portal', 'dreamax-license-manager' ),
			__( 'Customer Portal', 'dreamax-license-manager' ),
			Capabilities::MANAGE,
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Renders the page creator or the current page status.
	 */
	public function render(): void {
		$this->authorize();
		$page = $this->configured_page();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This value selects a read-only post-action notice on a capability-protected screen.
		$result = isset( $_GET['portal_result'] ) ? sanitize_key( wp_unslash( (string) $_GET['portal_result'] ) ) : '';

		echo '<div class="wrap dreamax-lm-admin dreamax-lm-portal-setup">';
		echo '<header class="dreamax-lm-page-header dreamax-lm-portal-header"><div><p class="dreamax-lm-eyebrow">' . esc_html__( 'Customer experience', 'dreamax-license-manager' ) . '</p><h1>' . esc_html__( 'Customer License Portal', 'dreamax-license-manager' ) . '</h1><p class="dreamax-lm-page-intro">' . esc_html__( 'Publish a private, responsive dashboard where customers can view licenses, copy keys, and claim guest orders.', 'dreamax-license-manager' ) . '</p></div><span class="dashicons dashicons-lock" aria-hidden="true"></span></header>';

		if ( 'error' === $result ) {
			echo '<div class="notice notice-error dreamax-lm-notice"><p><strong>' . esc_html__( 'The dashboard page could not be created.', 'dreamax-license-manager' ) . '</strong> ' . esc_html__( 'Please try again or review the WordPress error log.', 'dreamax-license-manager' ) . '</p></div>';
		} elseif ( 'created' === $result ) {
			echo '<div class="notice notice-success is-dismissible dreamax-lm-notice"><p><strong>' . esc_html__( 'Customer portal created successfully.', 'dreamax-license-manager' ) . '</strong> ' . esc_html__( 'The page is published and ready to test.', 'dreamax-license-manager' ) . '</p></div>';
		} elseif ( 'ready' === $result ) {
			echo '<div class="notice notice-info is-dismissible dreamax-lm-notice"><p>' . esc_html__( 'The existing customer portal is already ready. No duplicate page was created.', 'dreamax-license-manager' ) . '</p></div>';
		}

		if ( $page instanceof WP_Post ) {
			$this->render_ready( $page );
		} else {
			$this->render_creator();
		}

		echo '</div>';
	}

	/**
	 * Creates one published portal page and safely reuses it on repeat submissions.
	 */
	public function create_page(): void {
		$this->authorize();
		check_admin_referer( 'dreamax_lm_create_customer_portal' );

		$current = $this->configured_page();
		if ( $current instanceof WP_Post ) {
			$this->redirect( 'ready' );
		}

		$title = isset( $_POST['page_title'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['page_title'] ) ) : '';
		$slug  = isset( $_POST['page_slug'] ) ? sanitize_title( wp_unslash( (string) $_POST['page_slug'] ) ) : '';
		$title = '' !== $title ? $title : __( 'License Dashboard', 'dreamax-license-manager' );
		$slug  = '' !== $slug ? $slug : 'license-dashboard';

		$page_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_content' => "<!-- wp:shortcode -->\n[" . self::SHORTCODE . "]\n<!-- /wp:shortcode -->",
				'post_author'  => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $page_id ) || $page_id < 1 ) {
			$this->redirect( 'error' );
		}

		update_post_meta( $page_id, '_dreamax_lm_customer_portal', '1' );
		update_option( self::OPTION_PAGE_ID, $page_id, false );
		$this->redirect( 'created' );
	}

	/**
	 * Renders the ready state.
	 *
	 * @param WP_Post $page Configured portal page.
	 */
	private function render_ready( WP_Post $page ): void {
		$url = get_permalink( $page );
		$url = is_string( $url ) ? $url : home_url( '/' );

		echo '<div class="dreamax-lm-portal-layout"><main class="dreamax-lm-panel dreamax-lm-portal-ready">';
		echo '<div class="dreamax-lm-portal-state"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><div><p class="dreamax-lm-eyebrow">' . esc_html__( 'Published and connected', 'dreamax-license-manager' ) . '</p><h2>' . esc_html__( 'Your customer portal is ready', 'dreamax-license-manager' ) . '</h2><p>' . esc_html__( 'Customers must sign in before license information appears. Private pages receive no-store cache headers.', 'dreamax-license-manager' ) . '</p></div></div>';
		echo '<dl class="dreamax-lm-portal-details"><div><dt>' . esc_html__( 'Page', 'dreamax-license-manager' ) . '</dt><dd>' . esc_html( $page->post_title ) . '</dd></div><div><dt>' . esc_html__( 'Address', 'dreamax-license-manager' ) . '</dt><dd><code>' . esc_html( $url ) . '</code></dd></div><div><dt>' . esc_html__( 'Dashboard shortcode', 'dreamax-license-manager' ) . '</dt><dd><code>[' . esc_html( self::SHORTCODE ) . ']</code></dd></div></dl>';
		echo '<div class="dreamax-lm-portal-actions"><a class="button button-primary dreamax-lm-primary-action" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer"><span class="dashicons dashicons-external" aria-hidden="true"></span>' . esc_html__( 'Open customer portal', 'dreamax-license-manager' ) . '</a><a class="button" href="' . esc_url( get_edit_post_link( $page->ID, '' ) ) . '"><span class="dashicons dashicons-edit" aria-hidden="true"></span>' . esc_html__( 'Edit page', 'dreamax-license-manager' ) . '</a></div>';
		echo '</main>';
		$this->render_guidance();
		echo '</div>';
	}

	/**
	 * Renders the page creation form.
	 */
	private function render_creator(): void {
		echo '<div class="dreamax-lm-portal-layout"><main class="dreamax-lm-panel dreamax-lm-portal-create"><div class="dreamax-lm-panel-heading"><div><h2>' . esc_html__( 'Create the dashboard page', 'dreamax-license-manager' ) . '</h2><p>' . esc_html__( 'The page will be published immediately with the secure license dashboard already connected.', 'dreamax-license-manager' ) . '</p></div><span class="dashicons dashicons-admin-page" aria-hidden="true"></span></div>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dreamax_lm_create_customer_portal">';
		wp_nonce_field( 'dreamax_lm_create_customer_portal' );
		echo '<div class="dreamax-lm-portal-form"><label class="dreamax-lm-field" for="dreamax-lm-portal-title"><span>' . esc_html__( 'Page title', 'dreamax-license-manager' ) . '</span><input id="dreamax-lm-portal-title" type="text" name="page_title" value="' . esc_attr__( 'License Dashboard', 'dreamax-license-manager' ) . '" maxlength="120" required></label><label class="dreamax-lm-field" for="dreamax-lm-portal-slug"><span>' . esc_html__( 'Page address', 'dreamax-license-manager' ) . '</span><div class="dreamax-lm-portal-slug"><span>' . esc_html( home_url( '/' ) ) . '</span><input id="dreamax-lm-portal-slug" type="text" name="page_slug" value="license-dashboard" maxlength="120" required></div></label></div>';
		echo '<footer class="dreamax-lm-form-actions"><p><span class="dashicons dashicons-shield" aria-hidden="true"></span>' . esc_html__( 'License data stays hidden until the customer signs in.', 'dreamax-license-manager' ) . '</p><button class="button button-primary dreamax-lm-create-button" type="submit"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>' . esc_html__( 'Create dashboard page', 'dreamax-license-manager' ) . '</button></footer></form></main>';
		$this->render_guidance();
		echo '</div>';
	}

	/**
	 * Renders setup guidance shared by both states.
	 */
	private function render_guidance(): void {
		echo '<aside class="dreamax-lm-panel dreamax-lm-portal-guidance"><span class="dashicons dashicons-superhero-alt" aria-hidden="true"></span><p class="dreamax-lm-eyebrow">' . esc_html__( 'Built for customers', 'dreamax-license-manager' ) . '</p><h2>' . esc_html__( 'Everything in one place', 'dreamax-license-manager' ) . '</h2><ul><li><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>' . esc_html__( 'Responsive overview and license library', 'dreamax-license-manager' ) . '</li><li><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>' . esc_html__( 'Masked keys with secure reveal and copy', 'dreamax-license-manager' ) . '</li><li><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>' . esc_html__( 'Guest-order claim and account details', 'dreamax-license-manager' ) . '</li></ul><div class="dreamax-lm-portal-note"><strong>' . esc_html__( 'No page editor required', 'dreamax-license-manager' ) . '</strong><span>' . esc_html__( 'This setup screen creates the WordPress page directly, so a blank block editor does not stop launch.', 'dreamax-license-manager' ) . '</span></div></aside>';
	}

	/**
	 * Returns the configured page only while it still contains the dashboard shortcode.
	 */
	private function configured_page(): ?WP_Post {
		$page_id = absint( get_option( self::OPTION_PAGE_ID, 0 ) );
		$page    = $page_id > 0 ? get_post( $page_id ) : null;
		if ( ! $page instanceof WP_Post || 'page' !== $page->post_type || 'trash' === $page->post_status ) {
			return null;
		}

		return has_shortcode( $page->post_content, self::SHORTCODE ) ? $page : null;
	}

	/**
	 * Redirects back to the setup screen with a non-sensitive result flag.
	 *
	 * @param string $result Result key.
	 */
	private function redirect( string $result ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => self::MENU_SLUG,
					'portal_result' => $result,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Restricts the screen and action to license managers.
	 */
	private function authorize(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You are not allowed to perform this action.', 'dreamax-license-manager' ), 403 );
		}
	}
}
