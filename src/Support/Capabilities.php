<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Support;

final class Capabilities {
	public const MANAGE       = 'dreamax_lm_manage_licenses';
	public const REVEAL       = 'dreamax_lm_reveal_license_keys';
	public const EXPORT       = 'dreamax_lm_export_license_keys';
	public const CREDENTIALS  = 'dreamax_lm_manage_api_credentials';
	public const DELETE       = 'dreamax_lm_delete_license_records';
	public const SECURITY     = 'dreamax_lm_manage_security';
	public const DIAGNOSTICS  = 'dreamax_lm_view_diagnostics';

	public static function grant_defaults(): void {
		$administrator = get_role( 'administrator' );
		$shop_manager  = get_role( 'shop_manager' );

		if ( $administrator ) {
			foreach ( self::all() as $capability ) {
				$administrator->add_cap( $capability );
			}
		}

		if ( $shop_manager ) {
			$shop_manager->add_cap( self::MANAGE );
			$shop_manager->add_cap( self::DIAGNOSTICS );
		}
	}

	/** @return list<string> */
	public static function all(): array {
		return array(
			self::MANAGE,
			self::REVEAL,
			self::EXPORT,
			self::CREDENTIALS,
			self::DELETE,
			self::SECURITY,
			self::DIAGNOSTICS,
		);
	}
}
