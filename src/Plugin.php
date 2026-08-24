<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager;

use Dreamax\LicenseManager\Admin\Admin;
use Dreamax\LicenseManager\Api\PublicRoutes;
use Dreamax\LicenseManager\Api\PrivilegedRoutes;
use Dreamax\LicenseManager\CustomerPortal\AccountEndpoint;
use Dreamax\LicenseManager\ImportExport\CsvController;
use Dreamax\LicenseManager\Integrations\WooCommerce\OrderLicensing;
use Dreamax\LicenseManager\Integrations\WooCommerce\OrderWorkflowAdmin;
use Dreamax\LicenseManager\Integrations\WooCommerce\ProductSettings;
use Dreamax\LicenseManager\Privacy\Privacy;
use Dreamax\LicenseManager\Support\Health;

final class Plugin {
	public function register(): void {
		( new PublicRoutes() )->register();
		( new PrivilegedRoutes() )->register();
		( new ProductSettings() )->register();
		( new OrderLicensing() )->register();
		( new OrderWorkflowAdmin() )->register();
		( new AccountEndpoint() )->register();
		( new Admin() )->register();
		( new CsvController() )->register();
		( new Privacy() )->register();
		( new Health() )->register();

		add_action( 'dreamax_lm_cleanup', array( $this, 'cleanup' ) );
	}

	public function cleanup(): void {
		global $wpdb;

		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}dreamax_lm_idempotency WHERE expires_at < %s LIMIT 500",
				$now
			)
		);
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}dreamax_lm_rate_limits WHERE expires_at < %s LIMIT 500",
				$now
			)
		);
	}
}
