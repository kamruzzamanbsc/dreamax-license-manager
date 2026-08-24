<?php
/**
 * Defines the Requirements class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Support;

/**
 * Handles Requirements operations.
 */
final class Requirements {
	/**
	 * Handles the satisfied operation.
	 */
	public function satisfied(): bool {
		return version_compare( PHP_VERSION, '8.0', '>=' )
			&& class_exists( 'WooCommerce' )
			&& extension_loaded( 'sodium' );
	}

	/**
	 * Handles the register admin notice operation.
	 */
	public function register_admin_notice(): void {
		add_action(
			'admin_notices',
			static function (): void {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}

				echo '<div class="notice notice-error"><p>';
				echo esc_html__( 'Dreamax License Manager needs PHP 8.0+, WooCommerce, and the Sodium PHP extension. Licensing features are paused; stored data has not been changed.', 'dreamax-license-manager' );
				echo '</p></div>';
			}
		);
	}
}
