<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Examples;

use RuntimeException;
use WP_Error;

/**
 * Minimal WordPress client for the Dreamax License Manager public v1 API.
 *
 * Copy and rename this class inside the consuming plugin. Do not load example
 * code directly from a remote location or ship a real key in source control.
 */
final class WordPressPluginClient {
	private string $base_url;
	private string $product_public_id;
	private string $instance_option;

	public function __construct( string $base_url, string $product_public_id, string $instance_option ) {
		if ( 'https' !== wp_parse_url( $base_url, PHP_URL_SCHEME ) ) {
			throw new RuntimeException( 'The licensing service must use HTTPS.' );
		}

		if ( 1 !== preg_match( '/^prd_[A-Za-z0-9_-]{22}$/D', $product_public_id ) ) {
			throw new RuntimeException( 'The product public identifier is invalid.' );
		}

		if ( 1 !== preg_match( '/^[a-z0-9_]{1,191}$/D', $instance_option ) ) {
			throw new RuntimeException( 'The instance option name is invalid.' );
		}

		$this->base_url         = untrailingslashit( $base_url );
		$this->product_public_id = $product_public_id;
		$this->instance_option   = $instance_option;
	}

	/**
	 * Activate this installation.
	 *
	 * Persist the idempotency key before the first attempt and reuse that exact
	 * value for an exact retry. Do not generate a new value after a timeout.
	 *
	 * @return array<string,mixed>
	 */
	public function activate( string $license_key, string $idempotency_key, ?string $label = null ): array {
		return $this->request( 'activate', $license_key, $idempotency_key, $label );
	}

	/** @return array<string,mixed> */
	public function validate( string $license_key, ?string $label = null ): array {
		return $this->request( 'validate', $license_key, null, $label );
	}

	/**
	 * Deactivate this installation.
	 *
	 * @return array<string,mixed>
	 */
	public function deactivate( string $license_key, string $idempotency_key ): array {
		return $this->request( 'deactivate', $license_key, $idempotency_key, null );
	}

	/**
	 * Return a stable, opaque identifier for this WordPress installation.
	 */
	public function instance_id(): string {
		$current = get_option( $this->instance_option, '' );
		if ( is_string( $current ) && $this->is_instance_id( $current ) ) {
			return $current;
		}

		$candidate = 'wp-' . wp_generate_uuid4();
		add_option( $this->instance_option, $candidate, '', false );

		$stored = get_option( $this->instance_option, '' );
		if ( ! is_string( $stored ) || ! $this->is_instance_id( $stored ) ) {
			throw new RuntimeException( 'A stable installation identifier could not be stored.' );
		}

		return $stored;
	}

	/**
	 * Generate an idempotency key for a new logical mutation.
	 */
	public static function new_idempotency_key(): string {
		return 'wp-' . wp_generate_uuid4();
	}

	/**
	 * @return array<string,mixed>
	 */
	private function request( string $operation, string $license_key, ?string $idempotency_key, ?string $label ): array {
		if ( '' === $license_key || strlen( $license_key ) > 512 ) {
			throw new RuntimeException( 'The license key is invalid.' );
		}

		if ( null !== $idempotency_key && 1 !== preg_match( '/^[!-~]{8,128}$/D', $idempotency_key ) ) {
			throw new RuntimeException( 'The idempotency key is invalid.' );
		}

		$headers = array(
			'Accept'       => 'application/json',
			'Content-Type' => 'application/json',
		);
		if ( null !== $idempotency_key ) {
			$headers['Idempotency-Key'] = $idempotency_key;
		}

		$body = array(
			'license_key'       => $license_key,
			'product_public_id' => $this->product_public_id,
			'instance_id'       => $this->instance_id(),
		);
		if ( null !== $label && '' !== $label ) {
			$body['instance_label'] = $label;
		}

		$response = wp_safe_remote_post(
			$this->base_url . '/wp-json/dreamax-license-manager/v1/licenses/' . $operation,
			array(
				'headers'     => $headers,
				'body'        => wp_json_encode( $body, JSON_THROW_ON_ERROR ),
				'timeout'     => 10,
				'redirection' => 0,
			)
		);

		if ( $response instanceof WP_Error ) {
			throw new RuntimeException( 'The licensing service could not be reached.' );
		}

		$status = wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		$data   = json_decode( $raw, true, 32, JSON_THROW_ON_ERROR );

		if ( ! is_array( $data ) || ! $this->is_envelope( $data ) ) {
			throw new RuntimeException( 'The licensing service returned an invalid response.' );
		}

		$data['http_status'] = $status;
		return $data;
	}

	/** @param array<mixed> $data */
	private function is_envelope( array $data ): bool {
		return isset( $data['success'], $data['code'], $data['message'], $data['request_id'], $data['timestamp'], $data['data'] )
			&& is_bool( $data['success'] )
			&& is_string( $data['code'] )
			&& is_string( $data['message'] )
			&& is_string( $data['request_id'] )
			&& is_string( $data['timestamp'] )
			&& is_array( $data['data'] );
	}

	private function is_instance_id( string $value ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9._:-]{16,128}$/D', $value );
	}
}
