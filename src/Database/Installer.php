<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Database;

use Dreamax\LicenseManager\Support\Capabilities;

final class Installer {
	public static function activate( bool $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			$site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::install_site();
				restore_current_blog();
			}
			return;
		}

		self::install_site();
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'dreamax_lm_cleanup' );
		flush_rewrite_rules();
	}

	private static function install_site(): void {
		( new Schema() )->install();
		Capabilities::grant_defaults();
		update_option( 'dreamax_lm_schema_version', Schema::VERSION, false );
		add_rewrite_endpoint( 'licenses', EP_ROOT | EP_PAGES );
		flush_rewrite_rules();

		if ( ! wp_next_scheduled( 'dreamax_lm_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'dreamax_lm_cleanup' );
		}
	}
}
