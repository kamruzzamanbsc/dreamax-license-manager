<?php
/**
 * Removes WooCommerce from clone-only active-plugin resolution for F26.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

if ( defined( 'DREAMAX_LM_F26_DISABLE_WOO' ) && DREAMAX_LM_F26_DISABLE_WOO ) {
	add_filter(
		'option_active_plugins',
		static fn( array $plugins ): array => array_values(
			array_filter(
				$plugins,
				static fn( string $plugin ): bool => 'woocommerce/woocommerce.php' !== $plugin
			)
		),
		PHP_INT_MIN
	);
}
