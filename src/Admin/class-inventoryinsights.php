<?php
/**
 * Defines the InventoryInsights class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Admin;

use Dreamax\LicenseManager\Integrations\WooCommerce\ProductPolicy;
use Throwable;

/**
 * Builds bounded, non-sensitive license inventory warnings.
 */
final class InventoryInsights {
	/**
	 * Returns upcoming-expiry and imported-pool facts.
	 *
	 * @return array{expiry_days:int,expiring:int,pool_threshold:int,low_pools:list<array{public_id:string,name:string,product_id:int,available:int}>}
	 */
	public function summary(): array {
		$expiry_days = 30;
		$threshold   = min( 1000, max( 0, (int) apply_filters( 'dreamax_lm_low_pool_threshold', 5 ) ) );

		return array(
			'expiry_days'    => $expiry_days,
			'expiring'       => $this->expiring_count( $expiry_days ),
			'pool_threshold' => $threshold,
			'low_pools'      => $this->low_pools( $threshold ),
		);
	}

	/**
	 * Counts non-revoked licenses that expire inside the configured UTC window.
	 *
	 * @param int $days Upcoming-expiry window.
	 */
	private function expiring_count( int $days ): int {
		global $wpdb;
		$now  = gmdate( 'Y-m-d H:i:s' );
		$soon = gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Current inventory warnings must not be served from stale object cache data.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_licenses WHERE lifecycle_status<>'revoked' AND expires_at>%s AND expires_at<=%s",
				$now,
				$soon
			)
		);
	}

	/**
	 * Returns a bounded list of enabled imported-key products at or below threshold.
	 *
	 * @param int $threshold Available-key warning threshold.
	 * @return list<array{public_id:string,name:string,product_id:int,available:int}>
	 */
	private function low_pools( int $threshold ): array {
		$product_limit = min( 500, max( 1, (int) apply_filters( 'dreamax_lm_pool_insight_product_limit', 200 ) ) );
		$post_ids      = get_posts(
			array(
				'post_type'      => array( 'product', 'product_variation' ),
				'post_status'    => array( 'publish', 'private', 'inherit' ),
				'posts_per_page' => $product_limit,
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- A bounded list of source-configured products is needed to detect empty pools.
				'meta_key'       => '_dreamax_lm_source',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- The query is bounded and reads only product IDs.
				'meta_value'     => 'pool',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'cache_results'  => false,
			)
		);
		$products = array();
		foreach ( $post_ids as $post_id ) {
			$product = wc_get_product( (int) $post_id );
			if ( ! $product ) {
				continue;
			}
			try {
				$policy = ( new ProductPolicy() )->resolve( $product );
			} catch ( Throwable $error ) {
				continue;
			}
			$public_id = (string) $policy['public_id'];
			if ( ! $policy['enabled'] || 'pool' !== $policy['source'] || '' === $public_id || isset( $products[ $public_id ] ) ) {
				continue;
			}
			$products[ $public_id ] = array(
				'public_id'  => $public_id,
				'name'       => $product->get_name(),
				'product_id' => (int) $post_id,
				'available'  => 0,
			);
		}

		if ( array() === $products ) {
			return array();
		}

		global $wpdb;
		$public_ids   = array_keys( $products );
		$placeholders = implode( ',', array_fill( 0, count( $public_ids ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The placeholder list contains only generated %s tokens; values are passed separately to prepare().
		$query = "SELECT product_public_id,COUNT(*) AS available FROM {$wpdb->prefix}dreamax_lm_licenses WHERE lifecycle_status='available' AND product_public_id IN ({$placeholders}) GROUP BY product_public_id";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The query has one generated placeholder per separately supplied public ID.
		$prepared = $wpdb->prepare( $query, ...$public_ids );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- The query was prepared immediately above from generated placeholders and current inventory must remain fresh.
		$counts = $wpdb->get_results( $prepared, ARRAY_A );
		foreach ( is_array( $counts ) ? $counts : array() as $count ) {
			$public_id = (string) ( $count['product_public_id'] ?? '' );
			if ( isset( $products[ $public_id ] ) ) {
				$products[ $public_id ]['available'] = max( 0, (int) ( $count['available'] ?? 0 ) );
			}
		}

		$low = array_values(
			array_filter(
				$products,
				static fn( array $product ): bool => $product['available'] <= $threshold
			)
		);
		usort(
			$low,
			static function ( array $left, array $right ): int {
				$by_stock = $left['available'] <=> $right['available'];
				return 0 !== $by_stock ? $by_stock : strcasecmp( $left['name'], $right['name'] );
			}
		);
		return array_slice( $low, 0, 20 );
	}
}
