<?php
/**
 * Defines the TransportGuard class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Api;

use Dreamax\LicenseManager\Licenses\LicenseException;

/**
 * Handles Transport guard operations.
 */
final class TransportGuard {
	/**
	 * Source value.
	 *
	 * @var SourceAddress
	 */
	private SourceAddress $source;

	/**
	 * Initializes the service.
	 *
	 * @param ?SourceAddress $source Source value.
	 * @phpstan-param SourceAddress|null $source Source value.
	 */
	public function __construct( ?SourceAddress $source = null ) {
		$this->source = $source ?? new SourceAddress();
	}

	/**
	 * Handles the assert public request operation.
	 */
	public function assert_public_request(): void {
		$this->assert_https();
		$this->assert_content_type();
		$this->assert_size( 16 * 1024, 128 );
		$this->assert_no_query_secrets();
	}

	/**
	 * Handles the assert privileged request operation.
	 *
	 * @param bool $has_body Has body value.
	 * @throws LicenseException When the operation cannot be completed.
	 */
	public function assert_privileged_request( bool $has_body ): void {
		$this->assert_https();
		if ( $has_body ) {
			$this->assert_content_type();
		}
		$this->assert_size( 64 * 1024, 128 );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The credential header must remain byte-exact; it is unslashed, length/control validated, and parsed strictly downstream.
		$authorization = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? (string) wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) : '';
		if ( strlen( $authorization ) > 256 || false !== strpos( $authorization, "\n" ) || false !== strpos( $authorization, "\r" ) ) {
			throw new LicenseException( 'authentication_required', 'Authentication is required.', 401 );
		}
		$this->assert_no_query_secrets();
	}

	/**
	 * Handles the assert https operation.
	 *
	 * @throws LicenseException When the operation cannot be completed.
	 */
	private function assert_https(): void {
		if ( is_ssl() ) {
			return;
		}
		$forwarded_https = $this->source->remote_is_trusted_proxy()
			&& isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] )
			&& 'https' === strtolower( trim( sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) ) );
		if ( $forwarded_https ) {
			return;
		}

		$remote = $this->source->resolve();
		$local  = in_array( $remote, array( '127.0.0.1', '::1' ), true );
		if ( $local && true === get_option( 'dreamax_lm_allow_http_local', false ) ) {
			return;
		}
		throw new LicenseException( 'server_unavailable', 'HTTPS is required for licensing requests.', 503 );
	}

	/**
	 * Handles the assert content type operation.
	 *
	 * @throws LicenseException When the operation cannot be completed.
	 */
	private function assert_content_type(): void {
		$content_type = isset( $_SERVER['CONTENT_TYPE'] ) ? strtolower( trim( explode( ';', sanitize_text_field( wp_unslash( (string) $_SERVER['CONTENT_TYPE'] ) ) )[0] ) ) : '';
		if ( 'application/json' !== $content_type ) {
			throw new LicenseException( 'invalid_request', 'Use the application/json content type.', 415 );
		}
	}

	/**
	 * Handles the assert size operation.
	 *
	 * @param int $body_limit Body limit value.
	 * @param int $idempotency_limit Idempotency limit value.
	 * @throws LicenseException When the operation cannot be completed.
	 */
	private function assert_size( int $body_limit, int $idempotency_limit ): void {
		$length = isset( $_SERVER['CONTENT_LENGTH'] ) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
		if ( $length > $body_limit ) {
			throw new LicenseException( 'invalid_request', 'The request body is too large.', 413 );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Idempotency keys are protocol identifiers whose exact bytes matter; they are unslashed and strictly length/ASCII validated by the repository.
		$key = isset( $_SERVER['HTTP_IDEMPOTENCY_KEY'] ) ? (string) wp_unslash( $_SERVER['HTTP_IDEMPOTENCY_KEY'] ) : '';
		if ( strlen( $key ) > $idempotency_limit ) {
			throw new LicenseException( 'invalid_request', 'The idempotency key is too long.', 400 );
		}
	}

	/**
	 * Handles the assert no query secrets operation.
	 *
	 * @throws LicenseException When the operation cannot be completed.
	 */
	private function assert_no_query_secrets(): void {
		foreach ( array( 'license_key', 'license', 'key', 'token', 'authorization', 'idempotency_key' ) as $name ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public API query inspection rejects secrets and performs no mutation.
			if ( isset( $_GET[ $name ] ) ) {
				throw new LicenseException( 'invalid_request', 'Secrets are not accepted in the URL.', 400 );
			}
		}
	}
}
