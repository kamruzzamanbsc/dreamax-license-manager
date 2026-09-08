<?php
/**
 * Defines the ProductSettings class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Integrations\WooCommerce;

use Dreamax\LicenseManager\Support\PublicId;
use WC_Product;
use WP_Post;

/**
 * Handles Product settings operations.
 */
final class ProductSettings {
	/**
	 * Handles the register operation.
	 */
	public function register(): void {
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'fields' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product' ) );
		add_action( 'woocommerce_variation_options', array( $this, 'variation_fields' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation' ), 10, 2 );
		add_action( 'woocommerce_product_duplicate', array( $this, 'duplicate' ) );
	}

	/**
	 * Handles the fields operation.
	 */
	public function fields(): void {
		echo '<div class="options_group">';
		woocommerce_wp_checkbox(
			array(
				'id'          => '_dreamax_lm_enabled',
				'label'       => __( 'Enable licensing', 'dreamax-license-manager' ),
				'description' => __( 'Create or reserve licenses when this product is paid.', 'dreamax-license-manager' ),
			)
		);
		woocommerce_wp_select(
			array(
				'id'      => '_dreamax_lm_source',
				'label'   => __( 'Key source', 'dreamax-license-manager' ),
				'options' => array(
					'generated' => __( 'Generate securely', 'dreamax-license-manager' ),
					'pool'      => __( 'Imported key pool', 'dreamax-license-manager' ),
				),
			)
		);
		woocommerce_wp_select(
			array(
				'id'      => '_dreamax_lm_issuance',
				'label'   => __( 'Issuance mode', 'dreamax-license-manager' ),
				'options' => array(
					'per_quantity' => __( 'One license per purchased quantity', 'dreamax-license-manager' ),
					'per_item'     => __( 'One license per order item', 'dreamax-license-manager' ),
				),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => '_dreamax_lm_activation_limit',
				'label'             => __( 'Activation limit', 'dreamax-license-manager' ),
				'description'       => __( 'Use 0 to disable activation, a positive number for a limit, or leave blank for unlimited.', 'dreamax-license-manager' ),
				'type'              => 'number',
				'custom_attributes' => array( 'min' => '0' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => '_dreamax_lm_valid_days',
				'label'             => __( 'Validity in days', 'dreamax-license-manager' ),
				'description'       => __( 'Leave blank for a lifetime license.', 'dreamax-license-manager' ),
				'type'              => 'number',
				'custom_attributes' => array( 'min' => '1' ),
			)
		);
		woocommerce_wp_select(
			array(
				'id'          => '_dreamax_lm_refund_policy',
				'label'       => __( 'Refund policy', 'dreamax-license-manager' ),
				'description' => __( 'Delivered keys are never returned to a shared pool. Ineligible releases fall back to suspension.', 'dreamax-license-manager' ),
				'options'     => $this->policy_options(),
			)
		);
		woocommerce_wp_select(
			array(
				'id'          => '_dreamax_lm_cancellation_policy',
				'label'       => __( 'Cancellation policy', 'dreamax-license-manager' ),
				'description' => __( 'Choose how issued licenses respond when an order is cancelled.', 'dreamax-license-manager' ),
				'options'     => $this->policy_options(),
			)
		);
		echo '</div>';
	}

	/**
	 * Handles the variation fields operation.
	 *
	 * @param int     $loop Loop value.
	 * @param array   $variation_data Variation data value.
	 * @phpstan-param array<array-key,mixed> $variation_data Variation data value.
	 * @param WP_Post $variation Variation value.
	 */
	public function variation_fields( int $loop, array $variation_data, WP_Post $variation ): void {
		woocommerce_wp_checkbox(
			array(
				'id'            => "_dreamax_lm_enabled_{$loop}",
				'name'          => "_dreamax_lm_enabled[{$loop}]",
				'value'         => get_post_meta( $variation->ID, '_dreamax_lm_enabled', true ),
				'label'         => __( 'Enable licensing', 'dreamax-license-manager' ),
				'wrapper_class' => 'form-row form-row-full',
			)
		);
		woocommerce_wp_select(
			array(
				'id'            => "_dreamax_lm_source_{$loop}",
				'name'          => "_dreamax_lm_source[{$loop}]",
				'value'         => get_post_meta( $variation->ID, '_dreamax_lm_source', true ),
				'label'         => __( 'Key source', 'dreamax-license-manager' ),
				'options'       => array(
					''          => __( 'Use product setting', 'dreamax-license-manager' ),
					'generated' => __( 'Generate securely', 'dreamax-license-manager' ),
					'pool'      => __( 'Imported key pool', 'dreamax-license-manager' ),
				),
				'wrapper_class' => 'form-row form-row-first',
			)
		);
		woocommerce_wp_select(
			array(
				'id'            => "_dreamax_lm_issuance_{$loop}",
				'name'          => "_dreamax_lm_issuance[{$loop}]",
				'value'         => get_post_meta( $variation->ID, '_dreamax_lm_issuance', true ),
				'label'         => __( 'Issuance mode', 'dreamax-license-manager' ),
				'options'       => array(
					''             => __( 'Use product setting', 'dreamax-license-manager' ),
					'per_quantity' => __( 'One per quantity', 'dreamax-license-manager' ),
					'per_item'     => __( 'One per item', 'dreamax-license-manager' ),
				),
				'wrapper_class' => 'form-row form-row-last',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => "_dreamax_lm_activation_limit_{$loop}",
				'name'              => "_dreamax_lm_activation_limit[{$loop}]",
				'value'             => get_post_meta( $variation->ID, '_dreamax_lm_activation_limit', true ),
				'label'             => __( 'Activation limit', 'dreamax-license-manager' ),
				'type'              => 'number',
				'wrapper_class'     => 'form-row form-row-first',
				'custom_attributes' => array( 'min' => '0' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => "_dreamax_lm_valid_days_{$loop}",
				'name'              => "_dreamax_lm_valid_days[{$loop}]",
				'value'             => get_post_meta( $variation->ID, '_dreamax_lm_valid_days', true ),
				'label'             => __( 'Validity in days', 'dreamax-license-manager' ),
				'type'              => 'number',
				'wrapper_class'     => 'form-row form-row-last',
				'custom_attributes' => array( 'min' => '1' ),
			)
		);
		$variation_options = array( '' => __( 'Use product setting', 'dreamax-license-manager' ) ) + $this->policy_options();
		woocommerce_wp_select(
			array(
				'id'            => "_dreamax_lm_refund_policy_{$loop}",
				'name'          => "_dreamax_lm_refund_policy[{$loop}]",
				'value'         => get_post_meta( $variation->ID, '_dreamax_lm_refund_policy', true ),
				'label'         => __( 'Refund policy', 'dreamax-license-manager' ),
				'options'       => $variation_options,
				'wrapper_class' => 'form-row form-row-first',
			)
		);
		woocommerce_wp_select(
			array(
				'id'            => "_dreamax_lm_cancellation_policy_{$loop}",
				'name'          => "_dreamax_lm_cancellation_policy[{$loop}]",
				'value'         => get_post_meta( $variation->ID, '_dreamax_lm_cancellation_policy', true ),
				'label'         => __( 'Cancellation policy', 'dreamax-license-manager' ),
				'options'       => $variation_options,
				'wrapper_class' => 'form-row form-row-last',
			)
		);
	}

	/**
	 * Handles the save product operation.
	 *
	 * @param int $post_id Post id value.
	 */
	public function save_product( int $post_id ): void {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the product-editor nonce before firing this save hook.
		$this->save_values( $product, $_POST, null );
	}

	/**
	 * Handles the save variation operation.
	 *
	 * @param int $variation_id Variation id value.
	 * @param int $index Index value.
	 */
	public function save_variation( int $variation_id, int $index ): void {
		if ( ! current_user_can( 'edit_post', $variation_id ) ) {
			return;
		}
		$product = wc_get_product( $variation_id );
		if ( ! $product ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the variation-editor nonce before firing this save hook.
		$this->save_values( $product, $_POST, $index );
	}

	/**
	 * Handles the duplicate operation.
	 *
	 * @param WC_Product $duplicate Duplicate value.
	 */
	public function duplicate( WC_Product $duplicate ): void {
		$duplicate->update_meta_data( '_dreamax_lm_product_public_id', PublicId::generate( 'prd' ) );
		$duplicate->save_meta_data();
	}

	/**
	 * Handles the save values operation.
	 *
	 * @param WC_Product $product Product value.
	 * @param array      $source Source value.
	 * @phpstan-param array<string,mixed> $source Source value.
	 * @param ?int       $index Index value.
	 * @phpstan-param int|null $index Index value.
	 */
	private function save_values( WC_Product $product, array $source, ?int $index ): void {
		$fields  = array( '_dreamax_lm_source', '_dreamax_lm_issuance', '_dreamax_lm_activation_limit', '_dreamax_lm_valid_days', '_dreamax_lm_refund_policy', '_dreamax_lm_cancellation_policy' );
		$enabled = null === $index ? isset( $source['_dreamax_lm_enabled'] ) : isset( $source['_dreamax_lm_enabled'][ $index ] );
		$product->update_meta_data( '_dreamax_lm_enabled', $enabled ? 'yes' : 'no' );
		foreach ( $fields as $field ) {
			$value = null === $index ? ( $source[ $field ] ?? '' ) : ( $source[ $field ][ $index ] ?? '' );
			$product->update_meta_data( $field, sanitize_text_field( wp_unslash( (string) $value ) ) );
		}
		if ( $enabled && ! $product->get_meta( '_dreamax_lm_product_public_id', true ) ) {
			$product->update_meta_data( '_dreamax_lm_product_public_id', PublicId::generate( 'prd' ) );
		}
		$product->save_meta_data();
	}

	/**
	 * Handles the policy options operation.
	 *
	 * @return array<string,string>
	 */
	private function policy_options(): array {
		return array(
			'retain'         => __( 'Retain unchanged', 'dreamax-license-manager' ),
			'suspend'        => __( 'Suspend temporarily', 'dreamax-license-manager' ),
			'revoke'         => __( 'Permanently revoke', 'dreamax-license-manager' ),
			'release_unused' => __( 'Release only if never delivered or used', 'dreamax-license-manager' ),
		);
	}
}
