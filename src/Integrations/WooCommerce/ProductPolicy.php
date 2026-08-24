<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Integrations\WooCommerce;

final class ProductPolicy {
	/** @return array{enabled:bool,public_id:string,source:string,issuance:string,activation_limit:?int,valid_days:int,refund_policy:string,cancellation_policy:string} */
	public function resolve( \WC_Product $product ): array {
		$parent = $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : null;
		$get = static function ( string $key ) use ( $product, $parent ): string {
			$value = (string) $product->get_meta( $key, true );
			return '' !== $value || ! $parent ? $value : (string) $parent->get_meta( $key, true );
		};
		$limit = $get( '_dreamax_lm_activation_limit' );
		$source = $get( '_dreamax_lm_source' );
		$issuance = $get( '_dreamax_lm_issuance' );
		$refund = $this->policy( $get( '_dreamax_lm_refund_policy' ) );
		$cancellation = $this->policy( $get( '_dreamax_lm_cancellation_policy' ) );

		return array(
			'enabled'             => 'yes' === $get( '_dreamax_lm_enabled' ),
			'public_id'           => $get( '_dreamax_lm_product_public_id' ),
			'source'              => in_array( $source, array( 'generated', 'pool' ), true ) ? $source : 'generated',
			'issuance'            => in_array( $issuance, array( 'per_quantity', 'per_item' ), true ) ? $issuance : 'per_quantity',
			'activation_limit'    => '' === $limit ? null : max( 0, (int) $limit ),
			'valid_days'          => max( 0, (int) $get( '_dreamax_lm_valid_days' ) ),
			'refund_policy'       => $refund,
			'cancellation_policy' => $cancellation,
		);
	}

	public function policy( string $policy ): string {
		return in_array( $policy, array( 'retain', 'suspend', 'revoke', 'release_unused' ), true ) ? $policy : 'retain';
	}
}
