<?php
/**
 * Defines the Plugin class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager;

use Dreamax\LicenseManager\Admin\Admin;
use Dreamax\LicenseManager\Admin\CustomerPortalPage;
use Dreamax\LicenseManager\Admin\GeneratorAdmin;
use Dreamax\LicenseManager\Admin\SettingsAdmin;
use Dreamax\LicenseManager\Api\PublicRoutes;
use Dreamax\LicenseManager\Api\PrivilegedRoutes;
use Dreamax\LicenseManager\CustomerPortal\AccountEndpoint;
use Dreamax\LicenseManager\CustomerPortal\GuestClaimService;
use Dreamax\LicenseManager\CustomerPortal\LicenseDashboard;
use Dreamax\LicenseManager\Database\Installer;
use Dreamax\LicenseManager\ImportExport\CsvController;
use Dreamax\LicenseManager\Integrations\WooCommerce\OrderLicensing;
use Dreamax\LicenseManager\Integrations\WooCommerce\OrderWorkflowAdmin;
use Dreamax\LicenseManager\Integrations\WooCommerce\ProductSettings;
use Dreamax\LicenseManager\Privacy\Privacy;
use Dreamax\LicenseManager\Support\Health;

/**
 * Handles Plugin operations.
 */
final class Plugin {
	/**
	 * Handles the register operation.
	 */
	public function register(): void {
		Installer::maybe_upgrade();
		if ( is_multisite() ) {
			$network_plugins = (array) get_site_option( 'active_sitewide_plugins', array() );
			$plugin_root     = defined( 'DREAMAX_LM_FILE' ) ? constant( 'DREAMAX_LM_FILE' ) : '';
			if ( is_string( $plugin_root ) && '' !== $plugin_root && isset( $network_plugins[ plugin_basename( $plugin_root ) ] ) ) {
				add_action( 'wp_initialize_site', array( Installer::class, 'initialize_site' ), 200, 1 );
			}
		}
		( new PublicRoutes() )->register();
		( new PrivilegedRoutes() )->register();
		( new ProductSettings() )->register();
		( new OrderLicensing() )->register();
		( new OrderWorkflowAdmin() )->register();
		( new AccountEndpoint() )->register();
		( new LicenseDashboard() )->register();
		( new GuestClaimService() )->register();
		( new Admin() )->register();
		( new GeneratorAdmin() )->register();
		( new SettingsAdmin() )->register();
		( new CustomerPortalPage() )->register();
		( new CsvController() )->register();
		( new Privacy() )->register();
		( new Health() )->register();

		add_action( 'dreamax_lm_cleanup', array( $this, 'cleanup' ) );
	}

	/**
	 * Handles the cleanup operation.
	 */
	public function cleanup(): void {
		global $wpdb;

		$now = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional tables require direct, fresh database reads and writes; object caching would break locking and replay guarantees.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}dreamax_lm_idempotency WHERE expires_at < %s LIMIT 500",
				$now
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional tables require direct, fresh database reads and writes; object caching would break locking and replay guarantees.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}dreamax_lm_rate_limits WHERE expires_at < %s LIMIT 500",
				$now
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Claim expiry is bounded cleanup on a plugin-owned table.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}dreamax_lm_guest_claims SET status='expired',active_order_id=NULL,token_hash=NULL,invalidated_at=%s,updated_at=%s WHERE status IN ('pending','issued') AND expires_at < %s LIMIT 500",
				$now,
				$now,
				$now
			)
		);
	}
}
