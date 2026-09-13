<?php
/**
 * Defines the ProductPolicy class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Integrations\WooCommerce;

use Dreamax\LicenseManager\Generators\GeneratorRepository;
use RuntimeException;
use WC_Product;

/**
 * Handles Product policy operations.
 */
final class ProductPolicy {
	/**
	 * Handles the resolve operation.
	 *
	 * @param WC_Product $product Product value.
	 * @throws RuntimeException When an enabled product has an invalid generator-dependent policy.
	 * @return array{enabled:bool,public_id:string,source:string,issuance:string,generator_id:?int,generator:array<string,mixed>,activation_limit:?int,valid_days:int,valid_for_seconds:?int,fixed_expires_at:?string,refund_policy:string,cancellation_policy:string}
	 */
	public function resolve( WC_Product $product ): array {
		$parent       = $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : null;
		$get          = static function ( string $key ) use ( $product, $parent ): string {
			$value = (string) $product->get_meta( $key, true );
			return '' !== $value || ! $parent ? $value : (string) $parent->get_meta( $key, true );
		};
		$enabled      = 'yes' === $get( '_dreamax_lm_enabled' );
		$source       = $get( '_dreamax_lm_source' );
		$issuance     = $get( '_dreamax_lm_issuance' );
		$refund       = $this->policy( $get( '_dreamax_lm_refund_policy' ) );
		$cancellation = $this->policy( $get( '_dreamax_lm_cancellation_policy' ) );
		$source       = in_array( $source, array( 'generated', 'pool' ), true ) ? $source : 'generated';
		$generator_id = 'generated' === $source ? $get( '_dreamax_lm_generator_public_id' ) : '';
		$generator    = '' !== $generator_id ? ( new GeneratorRepository() )->by_public_id( $generator_id ) : null;
		if ( $enabled && '' !== $generator_id && ! is_array( $generator ) ) {
			throw new RuntimeException( 'The selected license generator is unavailable.' );
		}

		$activation_mode = $get( '_dreamax_lm_activation_mode' );
		$limit_value     = $get( '_dreamax_lm_activation_limit' );
		$activation      = $this->activation_limit( $activation_mode, $limit_value, $generator, $enabled );
		$validity        = $this->validity( $get( '_dreamax_lm_validity_mode' ), $get( '_dreamax_lm_valid_days' ), $get( '_dreamax_lm_fixed_expiry' ), $generator, $enabled );

		return array(
			'enabled'             => $enabled,
			'public_id'           => $get( '_dreamax_lm_product_public_id' ),
			'source'              => $source,
			'issuance'            => in_array( $issuance, array( 'per_quantity', 'per_item' ), true ) ? $issuance : 'per_quantity',
			'generator_id'        => is_array( $generator ) ? (int) $generator['id'] : null,
			'generator'           => is_array( $generator ) && is_array( $generator['configuration'] ?? null ) ? $generator['configuration'] : array(),
			'activation_limit'    => $activation,
			'valid_days'          => null === $validity['seconds'] ? 0 : (int) round( $validity['seconds'] / DAY_IN_SECONDS ),
			'valid_for_seconds'   => $validity['seconds'],
			'fixed_expires_at'    => $validity['fixed'],
			'refund_policy'       => $refund,
			'cancellation_policy' => $cancellation,
		);
	}

	/**
	 * Resolves activation-limit precedence without treating zero as missing.
	 *
	 * @param string                   $mode Explicit product/variation mode.
	 * @param string                   $value Legacy or limited value.
	 * @param array<string,mixed>|null $generator Selected generator.
	 * @param bool                     $enabled Whether licensing is enabled.
	 * @throws RuntimeException When a generator default or positive limit is required but unavailable.
	 */
	private function activation_limit( string $mode, string $value, ?array $generator, bool $enabled ): ?int {
		if ( '' === $mode ) {
			return '' === $value ? null : max( 0, (int) $value );
		}
		if ( 'unlimited' === $mode ) {
			return null;
		}
		if ( 'disabled' === $mode ) {
			return 0;
		}
		if ( 'limited' === $mode ) {
			if ( $enabled && (int) $value < 1 ) {
				throw new RuntimeException( 'The selected activation policy requires a positive activation limit.' );
			}
			return max( 1, (int) $value );
		}
		if ( 'generator' === $mode ) {
			if ( $enabled && ! is_array( $generator ) ) {
				throw new RuntimeException( 'Select a generator before using its activation default.' );
			}
			return is_array( $generator ) && null !== $generator['default_activation_limit'] ? max( 0, (int) $generator['default_activation_limit'] ) : null;
		}
		return '' === $value ? null : max( 0, (int) $value );
	}

	/**
	 * Resolves never, duration, fixed-date, and generator validity policies.
	 *
	 * @param string                   $mode Explicit product/variation mode.
	 * @param string                   $days Relative validity days.
	 * @param string                   $fixed Fixed UTC date.
	 * @param array<string,mixed>|null $generator Selected generator.
	 * @param bool                     $enabled Whether licensing is enabled.
	 * @throws RuntimeException When the selected validity policy is incomplete.
	 * @return array{seconds:?int,fixed:?string}
	 */
	private function validity( string $mode, string $days, string $fixed, ?array $generator, bool $enabled ): array {
		if ( '' === $mode ) {
			return array(
				'seconds' => (int) $days > 0 ? (int) $days * DAY_IN_SECONDS : null,
				'fixed'   => null,
			);
		}
		if ( 'never' === $mode ) {
			return array(
				'seconds' => null,
				'fixed'   => null,
			);
		}
		if ( 'duration' === $mode ) {
			if ( $enabled && (int) $days < 1 ) {
				throw new RuntimeException( 'The selected validity policy requires at least one day.' );
			}
			return array(
				'seconds' => max( 1, (int) $days ) * DAY_IN_SECONDS,
				'fixed'   => null,
			);
		}
		if ( 'fixed' === $mode ) {
			$parts = array_map( 'intval', explode( '-', $fixed ) );
			if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $fixed ) || 3 !== count( $parts ) || ! checkdate( $parts[1], $parts[2], $parts[0] ) ) {
				if ( $enabled ) {
					throw new RuntimeException( 'The selected validity policy requires a valid fixed expiry date.' );
				}
				return array(
					'seconds' => null,
					'fixed'   => null,
				);
			}
			return array(
				'seconds' => null,
				'fixed'   => $fixed . ' 23:59:59',
			);
		}
		if ( 'generator' === $mode ) {
			if ( $enabled && ! is_array( $generator ) ) {
				throw new RuntimeException( 'Select a generator before using its validity default.' );
			}
			return array(
				'seconds' => is_array( $generator ) && null !== $generator['valid_for_seconds'] ? max( DAY_IN_SECONDS, (int) $generator['valid_for_seconds'] ) : null,
				'fixed'   => null,
			);
		}
		return array(
			'seconds' => (int) $days > 0 ? (int) $days * DAY_IN_SECONDS : null,
			'fixed'   => null,
		);
	}

	/**
	 * Handles the policy operation.
	 *
	 * @param string $policy Policy value.
	 */
	public function policy( string $policy ): string {
		return in_array( $policy, array( 'retain', 'suspend', 'revoke', 'release_unused' ), true ) ? $policy : 'retain';
	}
}
