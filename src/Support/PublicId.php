<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Support;

final class PublicId {
	public static function generate( string $prefix ): string {
		return $prefix . '_' . Base64Url::encode( random_bytes( 16 ) );
	}
}
