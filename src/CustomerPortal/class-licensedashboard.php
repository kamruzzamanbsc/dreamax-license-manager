<?php
/**
 * Defines the standalone customer license dashboard.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\CustomerPortal;

use Dreamax\LicenseManager\Activations\ActivationRepository;
use Dreamax\LicenseManager\Activations\ActivationService;
use Dreamax\LicenseManager\Licenses\LicenseRepository;
use Dreamax\LicenseManager\Support\Settings;
use Throwable;

/**
 * Renders a theme-independent customer license portal through a shortcode.
 */
final class LicenseDashboard {
	private const SHORTCODE          = 'dreamax_license_dashboard';
	private const PORTAL_PAGE_OPTION = 'dreamax_lm_customer_portal_page_id';

	/**
	 * License repository.
	 *
	 * @var LicenseRepository
	 */
	private LicenseRepository $licenses;

	/**
	 * Whether dashboard assets were already registered for this request.
	 *
	 * @var bool
	 */
	private bool $assets_enqueued = false;

	/**
	 * Initializes the dashboard.
	 */
	public function __construct() {
		$this->licenses = new LicenseRepository();
	}

	/**
	 * Registers the standalone portal hooks.
	 */
	public function register(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'template_redirect', array( $this, 'send_private_headers' ) );
		add_filter( 'body_class', array( $this, 'body_classes' ) );
		add_filter( 'the_title', array( $this, 'hide_theme_page_title' ), 10, 2 );
		add_action( 'admin_post_dreamax_lm_customer_activate', array( $this, 'activate' ) );
		add_action( 'admin_post_dreamax_lm_customer_deactivate', array( $this, 'deactivate' ) );
	}

	/**
	 * Removes only the current portal page title from the theme's main loop.
	 *
	 * The dashboard renders its own accessible heading, so a second theme title
	 * adds noise above the application shell.
	 *
	 * @param string $title   Post title.
	 * @param int    $post_id Post ID.
	 */
	public function hide_theme_page_title( string $title, int $post_id ): string {
		if ( is_admin() || ! in_the_loop() || ! is_main_query() || get_queried_object_id() !== $post_id || ! $this->is_dashboard_page() ) {
			return $title;
		}

		return '';
	}

	/**
	 * Loads assets before the active theme prints its document head.
	 */
	public function enqueue_assets(): void {
		if ( ! $this->is_dashboard_page() ) {
			return;
		}

		$this->enqueue_dashboard_assets();
	}

	/**
	 * Prevents authenticated dashboard documents from being cached publicly.
	 */
	public function send_private_headers(): void {
		if ( ! $this->is_dashboard_page() || ! is_user_logged_in() ) {
			return;
		}

		nocache_headers();
		header( 'Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0', true );
		header( 'Pragma: no-cache', true );
		header( 'Expires: Wed, 11 Jan 1984 05:00:00 GMT', true );
	}

	/**
	 * Adds a page class used to neutralize narrow theme content containers.
	 *
	 * @param array $classes Existing body classes.
	 * @phpstan-param array<int,string> $classes Existing body classes.
	 * @return array<int,string>
	 */
	public function body_classes( array $classes ): array {
		if ( $this->is_dashboard_page() ) {
			$classes[] = 'dreamax-lm-dashboard-page';
		}

		return $classes;
	}

	/**
	 * Renders the dashboard shortcode.
	 *
	 * @param array $attributes Shortcode attributes.
	 * @phpstan-param array<string,mixed> $attributes Shortcode attributes.
	 * @return string
	 */
	public function shortcode( $attributes = array() ): string {
		unset( $attributes );
		$this->enqueue_dashboard_assets();

		if ( ! is_user_logged_in() ) {
			$return_url = get_permalink();
			$login_url  = wp_login_url( is_string( $return_url ) ? $return_url : home_url( '/' ) );

			return '<section class="dreamax-lm-login-card" aria-labelledby="dreamax-lm-login-title"><p class="dreamax-lm-dashboard__eyebrow">' . esc_html__( 'Protected software access', 'dreamax-license-manager' ) . '</p><h1 id="dreamax-lm-login-title">' . esc_html__( 'Sign in to view your licenses', 'dreamax-license-manager' ) . '</h1><p>' . esc_html__( 'Your license keys and purchase details are available only after you sign in to the account that owns them.', 'dreamax-license-manager' ) . '</p><a class="dreamax-lm-dashboard-button dreamax-lm-dashboard-button--primary" href="' . esc_url( $login_url ) . '">' . esc_html__( 'Sign in securely', 'dreamax-license-manager' ) . '</a></section>';
		}

		$rows         = $this->licenses->for_customer( get_current_user_id() );
		$current_user = wp_get_current_user();
		$display_name = $current_user->exists() && '' !== $current_user->display_name ? $current_user->display_name : __( 'Customer', 'dreamax-license-manager' );
		$email        = $current_user->exists() ? $current_user->user_email : '';
		$return_url   = get_permalink();
		$return_url   = is_string( $return_url ) ? $return_url : home_url( '/' );
		$key_access   = $this->prepare_key_access( $rows );
		$metrics      = $this->metrics( $rows, $key_access['unavailable'] );
		$logout_url   = wp_logout_url( $return_url );
		$key_limited  = array() !== $key_access['unavailable'];
		$state_class  = $key_limited ? ' needs-attention' : '';
		$state_label  = $key_limited ? __( 'Key access limited', 'dreamax-license-manager' ) : __( 'Secure account', 'dreamax-license-manager' );

		ob_start();
		?>
		<section class="dreamax-lm-dashboard" data-dreamax-license-dashboard aria-labelledby="dreamax-lm-dashboard-title">
			<nav class="dreamax-lm-dashboard-nav" aria-label="<?php echo esc_attr__( 'License dashboard sections', 'dreamax-license-manager' ); ?>">
				<div class="dreamax-lm-dashboard-nav__brand">
					<span class="dreamax-lm-dashboard-nav__mark" aria-hidden="true">D</span>
					<div><strong><?php esc_html_e( 'License Portal', 'dreamax-license-manager' ); ?></strong><span><?php echo esc_html( $display_name ); ?></span></div>
				</div>
				<ul>
					<li><a href="#license-overview" data-dreamax-dashboard-target="overview" aria-current="page"><span>01</span><?php esc_html_e( 'Overview', 'dreamax-license-manager' ); ?></a></li>
					<li><a href="#license-library" data-dreamax-dashboard-target="licenses"><span>02</span><?php esc_html_e( 'My Licenses', 'dreamax-license-manager' ); ?></a></li>
					<li><a href="#claim-order" data-dreamax-dashboard-target="claim"><span>03</span><?php esc_html_e( 'Claim Order', 'dreamax-license-manager' ); ?></a></li>
					<li><a href="#license-account" data-dreamax-dashboard-target="account"><span>04</span><?php esc_html_e( 'Account', 'dreamax-license-manager' ); ?></a></li>
				</ul>
				<div class="dreamax-lm-dashboard-nav__foot">
					<div><span><?php esc_html_e( 'Signed in as', 'dreamax-license-manager' ); ?></span><strong><?php echo esc_html( $email ); ?></strong></div>
					<a href="<?php echo esc_url( $logout_url ); ?>"><?php esc_html_e( 'Log out', 'dreamax-license-manager' ); ?></a>
				</div>
			</nav>

			<div class="dreamax-lm-dashboard-workspace">
				<?php $this->render_claim_notice(); ?>
				<?php $this->render_key_access_notice( $key_access['unavailable'] ); ?>
				<section class="dreamax-lm-dashboard-panel" id="license-overview" data-dreamax-dashboard-panel="overview">
					<header class="dreamax-lm-dashboard-hero">
						<div><p class="dreamax-lm-dashboard__eyebrow"><?php esc_html_e( 'Software access center', 'dreamax-license-manager' ); ?></p>
						<?php /* translators: %s: signed-in customer's display name. */ ?>
						<h1 id="dreamax-lm-dashboard-title"><?php echo esc_html( sprintf( __( 'Welcome, %s', 'dreamax-license-manager' ), $display_name ) ); ?></h1>
						<p><?php esc_html_e( 'Manage your software licenses, check renewal dates, and securely copy a key when you need it.', 'dreamax-license-manager' ); ?></p></div>
						<span class="dreamax-lm-dashboard-hero__state<?php echo esc_attr( $state_class ); ?>"><?php echo esc_html( $state_label ); ?></span>
					</header>

					<div class="dreamax-lm-dashboard-stats" aria-label="<?php echo esc_attr__( 'License summary', 'dreamax-license-manager' ); ?>">
						<div><span><?php esc_html_e( 'Total licenses', 'dreamax-license-manager' ); ?></span><strong><?php echo esc_html( (string) $metrics['total'] ); ?></strong><small><?php esc_html_e( 'Connected to your account', 'dreamax-license-manager' ); ?></small></div>
						<div><span><?php esc_html_e( 'Ready to use', 'dreamax-license-manager' ); ?></span><strong><?php echo esc_html( (string) $metrics['ready'] ); ?></strong><small><?php esc_html_e( 'Available without action', 'dreamax-license-manager' ); ?></small></div>
						<div><span><?php esc_html_e( 'Expiring soon', 'dreamax-license-manager' ); ?></span><strong><?php echo esc_html( (string) $metrics['expiring'] ); ?></strong><small><?php esc_html_e( 'Within the next 30 days', 'dreamax-license-manager' ); ?></small></div>
						<div class="<?php echo $metrics['attention'] > 0 ? 'needs-attention' : ''; ?>"><span><?php esc_html_e( 'Needs attention', 'dreamax-license-manager' ); ?></span><strong><?php echo esc_html( (string) $metrics['attention'] ); ?></strong><small><?php echo $metrics['attention'] > 0 ? esc_html__( 'Review license status', 'dreamax-license-manager' ) : esc_html__( 'Everything looks good', 'dreamax-license-manager' ); ?></small></div>
					</div>

					<section class="dreamax-lm-dashboard-card dreamax-lm-dashboard-recent" aria-labelledby="dreamax-lm-recent-title">
						<div class="dreamax-lm-dashboard-card__heading"><div><p class="dreamax-lm-dashboard__eyebrow"><?php esc_html_e( 'At a glance', 'dreamax-license-manager' ); ?></p><h2 id="dreamax-lm-recent-title"><?php esc_html_e( 'Recent licenses', 'dreamax-license-manager' ); ?></h2></div><a href="#license-library" data-dreamax-dashboard-target="licenses"><?php esc_html_e( 'View all licenses', 'dreamax-license-manager' ); ?></a></div>
						<?php $this->render_recent( $rows ); ?>
					</section>
				</section>

				<section class="dreamax-lm-dashboard-panel dreamax-lm-dashboard-card" id="license-library" data-dreamax-dashboard-panel="licenses" aria-labelledby="dreamax-lm-library-title">
					<div class="dreamax-lm-dashboard-card__heading"><div><p class="dreamax-lm-dashboard__eyebrow"><?php esc_html_e( 'License library', 'dreamax-license-manager' ); ?></p><h2 id="dreamax-lm-library-title"><?php esc_html_e( 'My licenses', 'dreamax-license-manager' ); ?></h2><p><?php esc_html_e( 'Keys remain masked until you choose to reveal and copy one.', 'dreamax-license-manager' ); ?></p></div><span class="dreamax-lm-dashboard-count"><?php echo esc_html( (string) $metrics['total'] ); ?></span></div>
					<?php $this->render_licenses( $rows, $key_access['keys'] ); ?>
				</section>

				<section class="dreamax-lm-dashboard-panel dreamax-lm-dashboard-card" id="claim-order" data-dreamax-dashboard-panel="claim" aria-labelledby="dreamax-lm-dashboard-claim-title">
					<div class="dreamax-lm-dashboard-card__heading"><div><p class="dreamax-lm-dashboard__eyebrow"><?php esc_html_e( 'Guest purchase', 'dreamax-license-manager' ); ?></p><h2 id="dreamax-lm-dashboard-claim-title"><?php esc_html_e( 'Claim an order', 'dreamax-license-manager' ); ?></h2><p><?php esc_html_e( 'Connect licenses bought as a guest to this account in two secure steps.', 'dreamax-license-manager' ); ?></p></div></div>
					<?php $this->render_claim_forms( $return_url ); ?>
				</section>

				<section class="dreamax-lm-dashboard-panel dreamax-lm-dashboard-card" id="license-account" data-dreamax-dashboard-panel="account" aria-labelledby="dreamax-lm-account-title">
					<div class="dreamax-lm-dashboard-card__heading"><div><p class="dreamax-lm-dashboard__eyebrow"><?php esc_html_e( 'Account security', 'dreamax-license-manager' ); ?></p><h2 id="dreamax-lm-account-title"><?php esc_html_e( 'Your account', 'dreamax-license-manager' ); ?></h2><p><?php esc_html_e( 'License information is private and available only while you are signed in.', 'dreamax-license-manager' ); ?></p></div></div>
					<div class="dreamax-lm-dashboard-account-grid"><div><span><?php esc_html_e( 'Customer', 'dreamax-license-manager' ); ?></span><strong><?php echo esc_html( $display_name ); ?></strong></div><div><span><?php esc_html_e( 'Email address', 'dreamax-license-manager' ); ?></span><strong><?php echo esc_html( $email ); ?></strong></div><div><span><?php esc_html_e( 'License access', 'dreamax-license-manager' ); ?></span><strong><?php esc_html_e( 'Private and encrypted', 'dreamax-license-manager' ); ?></strong></div></div>
					<div class="dreamax-lm-dashboard-account-actions"><a class="dreamax-lm-dashboard-button" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Back to website', 'dreamax-license-manager' ); ?></a><a class="dreamax-lm-dashboard-button dreamax-lm-dashboard-button--danger" href="<?php echo esc_url( $logout_url ); ?>"><?php esc_html_e( 'Log out securely', 'dreamax-license-manager' ); ?></a></div>
				</section>
			</div>
		</section>
		<?php
		$html = ob_get_clean();

		return is_string( $html ) ? $html : '';
	}

	/**
	 * Renders the recent-license overview.
	 *
	 * @param array $rows License rows.
	 * @phpstan-param list<array<string,mixed>> $rows License rows.
	 */
	private function render_recent( array $rows ): void {
		if ( array() === $rows ) {
			echo '<div class="dreamax-lm-dashboard-empty"><strong>' . esc_html__( 'No licenses connected yet', 'dreamax-license-manager' ) . '</strong><p>' . esc_html__( 'Eligible purchases and successfully claimed guest orders will appear here.', 'dreamax-license-manager' ) . '</p><a href="#claim-order" data-dreamax-dashboard-target="claim">' . esc_html__( 'Claim a guest order', 'dreamax-license-manager' ) . '</a></div>';
			return;
		}

		echo '<div class="dreamax-lm-dashboard-recent-list">';
		foreach ( array_slice( $rows, 0, 3 ) as $row ) {
			$status = (string) ( $row['lifecycle_status'] ?? '' );
			echo '<article><div><strong>' . esc_html( $this->product_name( $row ) ) . '</strong><span>' . esc_html( $this->expiry_label( $row ) ) . '</span></div><span class="dreamax-lm-dashboard-status dreamax-lm-dashboard-status--' . esc_attr( sanitize_html_class( strtolower( $status ) ) ) . '">' . esc_html( $this->status_label( $status ) ) . '</span></article>';
		}
		echo '</div>';
	}

	/**
	 * Renders detailed license cards.
	 *
	 * @param array $rows License rows.
	 * @param array $keys Decrypted keys indexed by public license ID.
	 * @phpstan-param list<array<string,mixed>> $rows License rows.
	 * @phpstan-param array<string,string> $keys Decrypted keys indexed by public license ID.
	 */
	private function render_licenses( array $rows, array $keys ): void {
		if ( array() === $rows ) {
			echo '<div class="dreamax-lm-dashboard-empty"><strong>' . esc_html__( 'No licenses connected yet', 'dreamax-license-manager' ) . '</strong><p>' . esc_html__( 'After an eligible purchase or guest-order claim, your license will appear here.', 'dreamax-license-manager' ) . '</p><a href="#claim-order" data-dreamax-dashboard-target="claim">' . esc_html__( 'Claim a guest order', 'dreamax-license-manager' ) . '</a></div>';
			return;
		}

		echo '<div class="dreamax-lm-dashboard-license-list">';
		foreach ( $rows as $row ) {
			$status        = (string) ( $row['lifecycle_status'] ?? '' );
			$public_id     = (string) ( $row['public_id'] ?? '' );
			$key_available = '' !== $public_id && isset( $keys[ $public_id ] );
			$masked        = $key_available ? $this->mask_key( $keys[ $public_id ] ) : __( 'Temporarily unavailable', 'dreamax-license-manager' );
			$activation    = null === ( $row['activation_limit'] ?? null ) ? __( 'Unlimited', 'dreamax-license-manager' ) : (string) max( 0, (int) $row['activation_limit'] );
			$issued        = ! empty( $row['created_at'] ) ? $this->format_date( (string) $row['created_at'] ) : __( 'Not available', 'dreamax-license-manager' );
			$order_summary = ! empty( $row['order_id'] ) ? sprintf( '#%d', (int) $row['order_id'] ) : __( 'Direct license', 'dreamax-license-manager' );
			?>
			<article class="dreamax-lm-dashboard-license">
				<header><div><p><?php esc_html_e( 'Licensed product', 'dreamax-license-manager' ); ?></p><h3><?php echo esc_html( $this->product_name( $row ) ); ?></h3></div><span class="dreamax-lm-dashboard-status dreamax-lm-dashboard-status--<?php echo esc_attr( sanitize_html_class( strtolower( $status ) ) ); ?>"><?php echo esc_html( $this->status_label( $status ) ); ?></span></header>
				<dl><div><dt><?php esc_html_e( 'Order', 'dreamax-license-manager' ); ?></dt><dd><?php echo esc_html( $order_summary ); ?></dd></div><div><dt><?php esc_html_e( 'Activation limit', 'dreamax-license-manager' ); ?></dt><dd><?php echo esc_html( $activation ); ?></dd></div><div><dt><?php esc_html_e( 'Issued', 'dreamax-license-manager' ); ?></dt><dd><?php echo esc_html( $issued ); ?></dd></div><div><dt><?php esc_html_e( 'Expiry', 'dreamax-license-manager' ); ?></dt><dd><?php echo esc_html( $this->expiry_label( $row, false ) ); ?></dd></div></dl>
				<div class="dreamax-lm-dashboard-key-row">
					<div><span><?php esc_html_e( 'License key', 'dreamax-license-manager' ); ?></span><code id="dreamax-key-<?php echo esc_attr( $public_id ); ?>" class="dreamax-lm-key<?php echo $key_available ? '' : ' is-unavailable'; ?>" aria-live="polite"><?php echo esc_html( $masked ); ?></code></div>
					<?php if ( $key_available ) : ?>
						<button type="button" class="dreamax-lm-reveal" data-license="<?php echo esc_attr( $public_id ); ?>" aria-controls="dreamax-key-<?php echo esc_attr( $public_id ); ?>"><?php esc_html_e( 'Reveal and copy', 'dreamax-license-manager' ); ?></button>
					<?php else : ?>
						<button type="button" class="dreamax-lm-reveal" disabled aria-disabled="true"><?php esc_html_e( 'Key unavailable', 'dreamax-license-manager' ); ?></button>
					<?php endif; ?>
				</div>
				<?php $this->render_activations( $row, $key_available ); ?>
			</article>
			<?php
		}
		echo '</div>';
	}

	/**
	 * Renders installations and merchant-enabled customer controls.
	 *
	 * @param array $license Owned license row.
	 * @param bool  $key_available Whether secure key operations are ready.
	 * @phpstan-param array<string,mixed> $license Owned license row.
	 */
	private function render_activations( array $license, bool $key_available ): void {
		$activations = ( new ActivationRepository() )->for_license( (int) $license['id'] );
		$enabled     = Settings::customer_activation_management();
		$public_id   = (string) $license['public_id'];
		$active      = count( array_filter( $activations, static fn( array $row ): bool => 'active' === $row['status'] ) );

		echo '<section class="dreamax-lm-dashboard-installations"><div class="dreamax-lm-dashboard-installations__heading"><div><h4>' . esc_html__( 'Installations', 'dreamax-license-manager' ) . '</h4><p>' . esc_html__( 'Device and site labels are supplied by your software.', 'dreamax-license-manager' ) . '</p></div><span>' . esc_html( (string) $active ) . ' ' . esc_html__( 'active', 'dreamax-license-manager' ) . '</span></div>';
		if ( array() === $activations ) {
			echo '<p class="dreamax-lm-dashboard-installations__empty">' . esc_html__( 'No installations have been registered yet.', 'dreamax-license-manager' ) . '</p>';
		} else {
			echo '<div class="dreamax-lm-dashboard-installation-list">';
			foreach ( $activations as $activation ) {
				$is_active = 'active' === $activation['status'];
				$label     = '' !== (string) ( $activation['instance_label'] ?? '' ) ? (string) $activation['instance_label'] : __( 'Unnamed installation', 'dreamax-license-manager' );
				echo '<div class="dreamax-lm-dashboard-installation"><div><strong>' . esc_html( $label ) . '</strong><span>' . esc_html( $is_active ? __( 'Active', 'dreamax-license-manager' ) : __( 'Inactive', 'dreamax-license-manager' ) ) . ' | ' . esc_html( $this->format_date( (string) $activation['activated_at'] ) ) . '</span></div>';
				if ( $enabled && $is_active ) {
					echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dreamax_lm_customer_deactivate"><input type="hidden" name="license" value="' . esc_attr( $public_id ) . '"><input type="hidden" name="activation" value="' . esc_attr( (string) $activation['public_id'] ) . '">';
					wp_nonce_field( 'dreamax_lm_customer_activation_' . $public_id );
					echo '<button class="dreamax-lm-dashboard-button dreamax-lm-dashboard-button--small" type="submit">' . esc_html__( 'Deactivate', 'dreamax-license-manager' ) . '</button></form>';
				}
				echo '</div>';
			}
			echo '</div>';
		}
		if ( $enabled && $key_available ) {
			echo '<form class="dreamax-lm-dashboard-activation-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dreamax_lm_customer_activate"><input type="hidden" name="license" value="' . esc_attr( $public_id ) . '">';
			wp_nonce_field( 'dreamax_lm_customer_activation_' . $public_id );
			echo '<label><span>' . esc_html__( 'Installation ID', 'dreamax-license-manager' ) . '</span><input type="text" name="instance_id" minlength="16" maxlength="128" pattern="[A-Za-z0-9._:-]{16,128}" required placeholder="' . esc_attr__( 'Paste the ID shown by your software', 'dreamax-license-manager' ) . '"></label><label><span>' . esc_html__( 'Label', 'dreamax-license-manager' ) . '</span><input type="text" name="instance_label" maxlength="255" placeholder="' . esc_attr__( 'Example: Office website', 'dreamax-license-manager' ) . '"></label><button class="dreamax-lm-dashboard-button" type="submit">' . esc_html__( 'Activate installation', 'dreamax-license-manager' ) . '</button></form>';
		}
		echo '</section>';
	}

	/**
	 * Activates an installation for a license owned by the current customer.
	 */
	public function activate(): void {
		$public_id = isset( $_POST['license'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['license'] ) ) : '';
		check_admin_referer( 'dreamax_lm_customer_activation_' . $public_id );
		$license = $this->owned_license( $public_id );
		if ( ! Settings::customer_activation_management() ) {
			$this->redirect_after_activation( 'disabled' );
		}
		$instance_id = isset( $_POST['instance_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['instance_id'] ) ) : '';
		$label       = isset( $_POST['instance_label'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['instance_label'] ) ) : '';
		try {
			$key = $this->licenses->decrypt_key( $license );
			( new ActivationService() )->activate( $key, (string) $license['product_public_id'], $instance_id, '' === $label ? null : $label, 'customer:' . wp_generate_uuid4(), 'customer', get_current_user_id() );
			$this->redirect_after_activation( 'activated' );
		} catch ( Throwable $error ) {
			unset( $error );
			$this->redirect_after_activation( 'failed' );
		}
	}

	/**
	 * Deactivates one activation bound to an owned license.
	 */
	public function deactivate(): void {
		$public_id = isset( $_POST['license'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['license'] ) ) : '';
		check_admin_referer( 'dreamax_lm_customer_activation_' . $public_id );
		$license = $this->owned_license( $public_id );
		if ( ! Settings::customer_activation_management() ) {
			$this->redirect_after_activation( 'disabled' );
		}
		$activation = isset( $_POST['activation'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['activation'] ) ) : '';
		try {
			( new ActivationService() )->deactivate_record( (int) $license['id'], $activation, 'customer:' . wp_generate_uuid4(), 'customer', get_current_user_id() );
			$this->redirect_after_activation( 'deactivated' );
		} catch ( Throwable $error ) {
			unset( $error );
			$this->redirect_after_activation( 'failed' );
		}
	}

	/**
	 * Returns a license only when it belongs to the signed-in customer.
	 *
	 * @param string $public_id Posted public license identifier.
	 * @return array<string,mixed>
	 */
	private function owned_license( string $public_id ): array {
		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}
		$license = $this->licenses->by_public_id( $public_id );
		if ( ! is_array( $license ) || get_current_user_id() !== (int) $license['customer_id'] ) {
			wp_die( esc_html__( 'You cannot manage that license.', 'dreamax-license-manager' ), 403 );
		}
		return $license;
	}

	/**
	 * Redirects to the canonical portal with an allowlisted result code.
	 *
	 * @param string $result Result code.
	 */
	private function redirect_after_activation( string $result ): never {
		$page_id = absint( get_option( self::PORTAL_PAGE_OPTION, 0 ) );
		$url     = $page_id > 0 ? get_permalink( $page_id ) : home_url( '/' );
		$url     = is_string( $url ) ? $url : home_url( '/' );
		wp_safe_redirect( add_query_arg( 'dreamax_lm_activation_result', $result, $url ) . '#license-library' );
		exit;
	}

	/**
	 * Decrypts customer keys behind a bounded degraded-mode boundary.
	 *
	 * A missing, changed, or otherwise unavailable master key must not turn the
	 * customer portal into a fatal-error disclosure. License metadata remains
	 * readable while sensitive key actions pause for administrator recovery.
	 *
	 * @param array $rows License rows.
	 * @phpstan-param list<array<string,mixed>> $rows License rows.
	 * @return array{keys:array<string,string>,unavailable:array<string,true>}
	 */
	private function prepare_key_access( array $rows ): array {
		$keys        = array();
		$unavailable = array();

		foreach ( $rows as $row ) {
			$public_id = (string) ( $row['public_id'] ?? '' );
			if ( '' === $public_id ) {
				continue;
			}

			try {
				$keys[ $public_id ] = $this->licenses->decrypt_key( $row );
			} catch ( Throwable ) {
				$unavailable[ $public_id ] = true;
			}
		}

		return array(
			'keys'        => $keys,
			'unavailable' => $unavailable,
		);
	}

	/**
	 * Shows a customer-safe degraded-mode notice without exposing key details.
	 *
	 * @param array $unavailable Public IDs for licenses whose keys cannot be read.
	 * @phpstan-param array<string,true> $unavailable Public IDs for unavailable keys.
	 */
	private function render_key_access_notice( array $unavailable ): void {
		if ( array() === $unavailable ) {
			return;
		}

		echo '<div class="dreamax-lm-dashboard-notice dreamax-lm-dashboard-notice--warning" role="status"><span aria-hidden="true"></span><p><strong>' . esc_html__( 'Secure key access is temporarily unavailable.', 'dreamax-license-manager' ) . '</strong> ' . esc_html__( 'Your license records remain listed, but revealing and copying affected keys is paused while the site administrator restores secure access.', 'dreamax-license-manager' ) . '</p></div>';
	}

	/**
	 * Renders the guest-order claim workflow.
	 *
	 * @param string $return_url Dashboard return URL.
	 */
	private function render_claim_forms( string $return_url ): void {
		?>
		<div class="dreamax-lm-dashboard-claim-grid">
			<form class="dreamax-lm-dashboard-claim-card" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="dreamax_lm_guest_claim_issue">
				<input type="hidden" name="dreamax_lm_return_url" value="<?php echo esc_url( $return_url ); ?>">
				<?php wp_nonce_field( 'dreamax_lm_guest_claim_issue' ); ?>
				<div class="dreamax-lm-dashboard-step"><span>1</span><div><h3><?php esc_html_e( 'Request a claim code', 'dreamax-license-manager' ); ?></h3><p><?php esc_html_e( 'Use the guest order number and its billing email. The one-time code expires after 30 minutes.', 'dreamax-license-manager' ); ?></p></div></div>
				<p><label for="dreamax-dashboard-issue-order"><?php esc_html_e( 'Order number', 'dreamax-license-manager' ); ?></label><input id="dreamax-dashboard-issue-order" type="number" name="order_id" min="1" inputmode="numeric" required></p>
				<p><label for="dreamax-dashboard-billing-email"><?php esc_html_e( 'Billing email', 'dreamax-license-manager' ); ?></label><input id="dreamax-dashboard-billing-email" type="email" name="billing_email" autocomplete="email" required></p>
				<button type="submit" class="dreamax-lm-dashboard-button"><?php esc_html_e( 'Email claim code', 'dreamax-license-manager' ); ?></button>
			</form>
			<form class="dreamax-lm-dashboard-claim-card" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="dreamax_lm_guest_claim_verify">
				<input type="hidden" name="dreamax_lm_return_url" value="<?php echo esc_url( $return_url ); ?>">
				<?php wp_nonce_field( 'dreamax_lm_guest_claim_verify' ); ?>
				<div class="dreamax-lm-dashboard-step"><span>2</span><div><h3><?php esc_html_e( 'Connect your licenses', 'dreamax-license-manager' ); ?></h3><p><?php esc_html_e( 'Enter the same order number and the one-time code sent to the billing email.', 'dreamax-license-manager' ); ?></p></div></div>
				<p><label for="dreamax-dashboard-verify-order"><?php esc_html_e( 'Order number', 'dreamax-license-manager' ); ?></label><input id="dreamax-dashboard-verify-order" type="number" name="order_id" min="1" inputmode="numeric" required></p>
				<p><label for="dreamax-dashboard-claim-code"><?php esc_html_e( 'One-time code', 'dreamax-license-manager' ); ?></label><input id="dreamax-dashboard-claim-code" type="password" name="claim_code" minlength="43" maxlength="43" autocomplete="one-time-code" required></p>
				<button type="submit" class="dreamax-lm-dashboard-button dreamax-lm-dashboard-button--primary"><?php esc_html_e( 'Claim order licenses', 'dreamax-license-manager' ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * Renders the allowlisted result of a nonce-protected guest-claim POST.
	 */
	private function render_claim_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This allowlisted value displays the result of a nonce-protected activation action.
		$activation_result  = isset( $_GET['dreamax_lm_activation_result'] ) ? sanitize_key( wp_unslash( (string) $_GET['dreamax_lm_activation_result'] ) ) : '';
		$activation_notices = array(
			'activated'   => array( 'success', __( 'The installation is active.', 'dreamax-license-manager' ) ),
			'deactivated' => array( 'success', __( 'The installation was deactivated.', 'dreamax-license-manager' ) ),
			'disabled'    => array( 'warning', __( 'Customer installation management is disabled by the store.', 'dreamax-license-manager' ) ),
			'failed'      => array( 'error', __( 'The installation could not be changed. Check the identifier, license status, and available activation slots.', 'dreamax-license-manager' ) ),
		);
		if ( isset( $activation_notices[ $activation_result ] ) ) {
			echo '<div class="dreamax-lm-dashboard-notice dreamax-lm-dashboard-notice--' . esc_attr( $activation_notices[ $activation_result ][0] ) . '" role="status"><span aria-hidden="true"></span><p>' . esc_html( $activation_notices[ $activation_result ][1] ) . '</p></div>';
		}

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

		echo '<div class="dreamax-lm-dashboard-notice dreamax-lm-dashboard-notice--' . esc_attr( $notices[ $result ][0] ) . '" role="status"><span aria-hidden="true"></span><p>' . esc_html( $notices[ $result ][1] ) . '</p></div>';
	}

	/**
	 * Calculates dashboard summary metrics.
	 *
	 * @param array $rows        License rows.
	 * @param array $unavailable Public IDs for licenses whose keys cannot be read.
	 * @phpstan-param list<array<string,mixed>> $rows License rows.
	 * @phpstan-param array<string,true> $unavailable Public IDs for unavailable keys.
	 * @return array{total:int,ready:int,expiring:int,attention:int}
	 */
	private function metrics( array $rows, array $unavailable = array() ): array {
		$now       = time();
		$soon      = $now + ( 30 * DAY_IN_SECONDS );
		$ready     = 0;
		$expiring  = 0;
		$attention = 0;

		foreach ( $rows as $row ) {
			$status        = strtolower( (string) ( $row['lifecycle_status'] ?? '' ) );
			$expiry        = $this->timestamp( $row['expires_at'] ?? null );
			$public_id     = (string) ( $row['public_id'] ?? '' );
			$key_available = '' === $public_id || ! isset( $unavailable[ $public_id ] );
			if ( $key_available && in_array( $status, array( 'assigned', 'active' ), true ) && ( null === $expiry || $expiry > $now ) ) {
				++$ready;
			}
			if ( null !== $expiry && $expiry > $now && $expiry <= $soon ) {
				++$expiring;
			}
			if ( ! $key_available || in_array( $status, array( 'revoked', 'expired', 'suspended' ), true ) || ( null !== $expiry && $expiry <= $now ) ) {
				++$attention;
			}
		}

		return array(
			'total'     => count( $rows ),
			'ready'     => $ready,
			'expiring'  => $expiring,
			'attention' => $attention,
		);
	}

	/**
	 * Resolves a customer-facing product name.
	 *
	 * @param array $row License row.
	 * @phpstan-param array<string,mixed> $row License row.
	 */
	private function product_name( array $row ): string {
		$product_id = (int) ( $row['product_id'] ?? 0 );
		$product    = $product_id > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;

		return is_object( $product ) && method_exists( $product, 'get_name' ) ? (string) $product->get_name() : __( 'Software license', 'dreamax-license-manager' );
	}

	/**
	 * Formats a license status.
	 *
	 * @param string $status Stored lifecycle status.
	 */
	private function status_label( string $status ): string {
		return ucwords( str_replace( array( '-', '_' ), ' ', $status ) );
	}

	/**
	 * Formats the expiry for a customer.
	 *
	 * @param array $row License row.
	 * @param bool  $with_prefix Whether to include the summary prefix.
	 * @phpstan-param array<string,mixed> $row License row.
	 */
	private function expiry_label( array $row, bool $with_prefix = true ): string {
		if ( empty( $row['expires_at'] ) ) {
			return $with_prefix ? __( 'No expiry date', 'dreamax-license-manager' ) : __( 'Never', 'dreamax-license-manager' );
		}

		$date = $this->format_date( (string) $row['expires_at'] );

		/* translators: %s: formatted license expiry date. */
		return $with_prefix ? sprintf( __( 'Expires %s', 'dreamax-license-manager' ), $date ) : $date;
	}

	/**
	 * Formats a UTC database date in the site timezone.
	 *
	 * @param string $date Database date.
	 */
	private function format_date( string $date ): string {
		return wc_format_datetime( new \WC_DateTime( $date, new \DateTimeZone( 'UTC' ) ) );
	}

	/**
	 * Converts a database expiry to a UTC timestamp.
	 *
	 * @param mixed $value Expiry value.
	 */
	private function timestamp( $value ): ?int {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}

		$timestamp = strtotime( $value . ' UTC' );

		return false === $timestamp ? null : $timestamp;
	}

	/**
	 * Masks a key while retaining enough characters for identification.
	 *
	 * @param string $key Plain license key.
	 */
	private function mask_key( string $key ): string {
		return strlen( $key ) > 8 ? substr( $key, 0, 4 ) . str_repeat( "\xE2\x80\xA2", min( 12, strlen( $key ) - 8 ) ) . substr( $key, -4 ) : str_repeat( "\xE2\x80\xA2", strlen( $key ) );
	}

	/**
	 * Loads the standalone portal assets.
	 */
	private function enqueue_dashboard_assets(): void {
		if ( $this->assets_enqueued ) {
			return;
		}

		$asset_version = DREAMAX_LM_VERSION . '.portal-dashboard-7';
		wp_enqueue_style( 'dreamax-lm-dashboard', DREAMAX_LM_URL . 'assets/css/dashboard.css', array(), $asset_version );
		wp_enqueue_script( 'dreamax-lm-account', DREAMAX_LM_URL . 'assets/js/account.js', array(), $asset_version, true );
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
		$this->assets_enqueued = true;
	}

	/**
	 * Reports whether the current page contains the dashboard shortcode.
	 */
	private function is_dashboard_page(): bool {
		if ( is_admin() || ! is_singular() ) {
			return false;
		}

		$post = get_post();

		return $post instanceof \WP_Post && has_shortcode( $post->post_content, self::SHORTCODE );
	}
}
