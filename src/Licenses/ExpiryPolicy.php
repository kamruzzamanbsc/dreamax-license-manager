<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Licenses;

use InvalidArgumentException;

final class ExpiryPolicy {
	private const DAY_SECONDS = 86400;

	public function extend( ?string $current_expiry, int $extension_seconds, int $now ): string {
		if ( null === $current_expiry || '' === $current_expiry ) {
			throw new InvalidArgumentException( 'A lifetime license cannot be extended.' );
		}
		if ( $extension_seconds < self::DAY_SECONDS || $extension_seconds > 3650 * self::DAY_SECONDS ) {
			throw new InvalidArgumentException( 'The extension must be between 1 and 3650 days.' );
		}

		$current = strtotime( $current_expiry . ' UTC' );
		if ( false === $current ) {
			throw new InvalidArgumentException( 'The current expiry is invalid.' );
		}

		return gmdate( 'Y-m-d H:i:s', max( $current, $now ) + $extension_seconds );
	}
}
