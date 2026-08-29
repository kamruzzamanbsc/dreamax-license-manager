<?php
/**
 * Defines the Installer class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Database;

use Dreamax\LicenseManager\Support\Capabilities;
use RuntimeException;

/**
 * Handles Installer operations.
 */
final class Installer {
	/**
	 * Applies additive schema changes when the stored version is behind.
	 *
	 * @throws RuntimeException When a completed version checkpoint cannot be stored.
	 */
	public static function maybe_upgrade(): void {
		$installed = (string) get_option( 'dreamax_lm_schema_version', '0' );
		if ( version_compare( $installed, Schema::VERSION, '>=' ) ) {
			return;
		}

		$schema = new Schema();
		foreach ( Schema::supported_versions() as $version ) {
			if ( ! version_compare( $version, $installed, '>' ) ) {
				continue;
			}

			$schema->install_version( $version );
			update_option( 'dreamax_lm_schema_version', $version, false );
			if ( (string) get_option( 'dreamax_lm_schema_version', '0' ) !== $version ) {
				throw new RuntimeException( 'The plugin schema version checkpoint could not be saved. Re-running the upgrade is safe.' );
			}
			$installed = $version;
		}
	}

	/**
	 * Handles the activate operation.
	 *
	 * @param bool $network_wide Network wide value.
	 */
	public static function activate( bool $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::install_site();
				restore_current_blog();
			}
			return;
		}

		self::install_site();
	}

	/**
	 * Handles the deactivate operation.
	 *
	 * @param bool $network_wide Whether the plugin was network-active.
	 */
	public static function deactivate( bool $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::deactivate_site();
				restore_current_blog();
			}
			return;
		}

		self::deactivate_site();
	}

	/**
	 * Deactivates the current site without removing retained data.
	 */
	private static function deactivate_site(): void {
		wp_clear_scheduled_hook( 'dreamax_lm_cleanup' );
		flush_rewrite_rules();
	}

	/**
	 * Initializes a site created after network activation.
	 *
	 * @param object $site WordPress site object.
	 */
	public static function initialize_site( object $site ): void {
		$site_id = isset( $site->blog_id ) ? (int) $site->blog_id : 0;
		if ( $site_id < 1 ) {
			return;
		}

		switch_to_blog( $site_id );
		try {
			self::install_site();
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Handles the install site operation.
	 */
	private static function install_site(): void {
		self::maybe_upgrade();
		Capabilities::grant_defaults();
		add_rewrite_endpoint( 'licenses', EP_ROOT | EP_PAGES );
		flush_rewrite_rules();

		if ( ! wp_next_scheduled( 'dreamax_lm_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'dreamax_lm_cleanup' );
		}
	}
}
