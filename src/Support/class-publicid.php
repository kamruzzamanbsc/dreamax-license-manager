<?php
/**
 * Defines the PublicId class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Support;

/**
 * Handles Public id operations.
 */
final class PublicId {
	/**
	 * Handles the generate operation.
	 *
	 * @param string $prefix Prefix value.
	 */
	public static function generate( string $prefix ): string {
		return $prefix . '_' . Base64Url::encode( random_bytes( 16 ) );
	}
}
