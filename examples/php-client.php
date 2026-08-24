<?php

declare(strict_types=1);

/**
 * Dependency-light Dreamax v1 client example.
 * Persist instance_id once per installation; never derive it only from a mutable URL.
 */
final class DreamaxLicenseClient {
	public function __construct(
		private string $base_url,
		private string $product_public_id,
		private string $instance_id
	) {}

	/** @return array<string,mixed> */
	public function activate( string $license_key, string $idempotency_key, ?string $label = null ): array {
		return $this->post( '/licenses/activate', $license_key, $idempotency_key, $label );
	}

	/** @return array<string,mixed> */
	public function validate( string $license_key, ?string $label = null ): array {
		return $this->post( '/licenses/validate', $license_key, null, $label );
	}

	/** @return array<string,mixed> */
	public function deactivate( string $license_key, string $idempotency_key ): array {
		return $this->post( '/licenses/deactivate', $license_key, $idempotency_key, null );
	}

	/** @return array<string,mixed> */
	private function post( string $path, string $license_key, ?string $idempotency_key, ?string $label ): array {
		$url = rtrim( $this->base_url, '/' ) . '/wp-json/dreamax-license-manager/v1' . $path;
		if ( 'https' !== parse_url( $url, PHP_URL_SCHEME ) ) {
			throw new RuntimeException( 'HTTPS is required.' );
		}
		$body = json_encode( array_filter( array( 'license_key' => $license_key, 'product_public_id' => $this->product_public_id, 'instance_id' => $this->instance_id, 'instance_label' => $label ), static fn( $value ): bool => null !== $value ), JSON_THROW_ON_ERROR );
		$headers = array( 'Content-Type: application/json', 'Accept: application/json' );
		if ( null !== $idempotency_key ) {
			$headers[] = 'Idempotency-Key: ' . $idempotency_key;
		}
		$context = stream_context_create( array( 'http' => array( 'method' => 'POST', 'header' => implode( "\r\n", $headers ), 'content' => $body, 'timeout' => 10, 'ignore_errors' => true ) ) );
		$response = file_get_contents( $url, false, $context );
		if ( false === $response ) {
			throw new RuntimeException( 'The licensing endpoint could not be reached.' );
		}
		$result = json_decode( $response, true, 32, JSON_THROW_ON_ERROR );
		if ( ! is_array( $result ) || ! isset( $result['success'], $result['code'], $result['request_id'], $result['timestamp'], $result['data'] ) ) {
			throw new RuntimeException( 'The response does not match the v1 envelope.' );
		}
		return $result;
	}
}
