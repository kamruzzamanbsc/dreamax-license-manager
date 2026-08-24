<?php
/**
 * Plugin Name: Dreamax License Manager
 * Plugin URI: https://example.invalid/dreamax-license-manager
 * Description: Self-hosted software licensing, activation, delivery, and migration for WooCommerce.
 * Version: 0.3.0
 * Requires at least: 6.9
 * Requires PHP: 8.0
 * Author: Dreamax Soft
 * Author URI: https://example.invalid/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: dreamax-license-manager
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 * WC requires at least: 10.8
 * WC tested up to: 10.8
 *
 * @package DreamaxLicenseManager
 */

defined( 'ABSPATH' ) || exit;

define( 'DREAMAX_LM_VERSION', '0.3.0' );
define( 'DREAMAX_LM_FILE', __FILE__ );
define( 'DREAMAX_LM_DIR', plugin_dir_path( __FILE__ ) );
define( 'DREAMAX_LM_URL', plugin_dir_url( __FILE__ ) );

require_once DREAMAX_LM_DIR . 'src/Support/Autoloader.php';

Dreamax\LicenseManager\Support\Autoloader::register();

register_activation_hook(
	__FILE__,
	array( Dreamax\LicenseManager\Database\Installer::class, 'activate' )
);

register_deactivation_hook(
	__FILE__,
	array( Dreamax\LicenseManager\Database\Installer::class, 'deactivate' )
);

add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		$requirements = new Dreamax\LicenseManager\Support\Requirements();

		if ( ! $requirements->satisfied() ) {
			$requirements->register_admin_notice();
			return;
		}

		( new Dreamax\LicenseManager\Plugin() )->register();
	},
	20
);
