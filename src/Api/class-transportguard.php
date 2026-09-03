<?php
/**
 * Defines the TransportGuard class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Api;

use Dreamax\LicenseManager\Credentials\CredentialToken;
use Dreamax\LicenseManager\Licenses\LicenseException;
use WP_REST_Request;

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
	 *
	 * @param WP_REST_Request $request Request value.
	 */
	public function assert_public_request( WP_REST_Request $request ): void {
		$this->assert_https();
		$this->assert_content_type( $request );
		$this->assert_size( (string) $request->get_body(), 16 * 1024, (string) $request->get_header( 'Idempotency-Key' ), 128 );
		$this->assert_no_query_secrets();
	}

	/**
	 * Validates an authenticated browser form carrying claim proof.
	 */
	public function assert_interactive_request(): void {
		$this->assert_https();
		$this->assert_interactive_size( 16 * 1024 );
		$this->assert_no_query_secrets();
	}

	/**
	 * Handles the assert privileged request operation.
	 *
	 * @param WP_REST_Request $request Request value.
	 * @param bool            $has_body Has body value.
	 * @throws LicenseException When the operation cannot be completed.
	 */
	public function assert_privileged_request( WP_REST_Request $request, bool $has_body ): void {
		$body          = (string) $request->get_body();
		$authorization = (string) $request->get_header( 'Authorization' );
		$this->assert_https();
		if ( $has_body ) {
			$this->assert_content_type( $request );
		}
		if ( '' !== $body ) {
			$this->assert_no_body_credentials( $body );
		}
		$this->assert_size( $body, 64 * 1024, (string) $request->get_header( 'Idempotency-Key' ), 128 );
		if ( strlen( $authorization ) > CredentialToken::HEADER_LIMIT || false !== strpos( $authorization, "\n" ) || false !== strpos( $authorization, "\r" ) ) {
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
		if ( $local && 1 === (int) get_option( 'dreamax_lm_allow_http_local', 0 ) ) {
			return;
		}
		throw new LicenseException( 'server_unavailable', 'HTTPS is required for licensing requests.', 503 );
	}

	/**
	 * Handles the assert content type operation.
	 *
	 * @param WP_REST_Request $request Request value.
	 * @throws LicenseException When the operation cannot be completed.
	 */
	private function assert_content_type( WP_REST_Request $request ): void {
		$content_type = strtolower( trim( explode( ';', (string) $request->get_header( 'Content-Type' ) )[0] ) );
		if ( 'application/json' !== $content_type ) {
			throw new LicenseException( 'invalid_request', 'Use the application/json content type.', 415 );
		}
	}

	/**
	 * Handles the assert size operation.
	 *
	 * @param string $body Raw request body.
	 * @param int    $body_limit Body limit value.
	 * @param string $idempotency_key Idempotency key value.
	 * @param int    $idempotency_limit Idempotency limit value.
	 * @throws LicenseException When the operation cannot be completed.
	 */
	private function assert_size( string $body, int $body_limit, string $idempotency_key, int $idempotency_limit ): void {
		if ( strlen( $body ) > $body_limit ) {
			throw new LicenseException( 'invalid_request', 'The request body is too large.', 413 );
		}
		if ( strlen( $idempotency_key ) > $idempotency_limit ) {
			throw new LicenseException( 'invalid_request', 'The idempotency key is too long.', 400 );
		}
	}

	/**
	 * Enforces the browser-form body ceiling using a sanitized request length.
	 *
	 * @param int $body_limit Body limit value.
	 * @throws LicenseException When the operation cannot be completed.
	 */
	private function assert_interactive_size( int $body_limit ): void {
		$length = isset( $_SERVER['CONTENT_LENGTH'] ) ? absint( sanitize_text_field( wp_unslash( (string) $_SERVER['CONTENT_LENGTH'] ) ) ) : 0;
		if ( $length > $body_limit ) {
			throw new LicenseException( 'invalid_request', 'The request body is too large.', 413 );
		}
	}

	/**
	 * Handles the assert no query secrets operation.
	 *
	 * @throws LicenseException When the operation cannot be completed.
	 */
	private function assert_no_query_secrets(): void {
		$blocked = array( 'license_key', 'license', 'key', 'token', 'code', 'claim_code', 'claim_token', 'claim_proof', 'proof', 'authorization', 'idempotency_key' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only inspection rejects query-carried secrets before request processing.
		$query_args = map_deep( wp_unslash( $_GET ), 'sanitize_text_field' );
		foreach ( array_keys( $query_args ) as $name ) {
			if ( in_array( sanitize_key( (string) $name ), $blocked, true ) ) {
				throw new LicenseException( 'invalid_request', 'Secrets are not accepted in the URL.', 400 );
			}
		}
		$query = wp_json_encode( $query_args );
		if ( is_string( $query ) && ( new CredentialToken() )->body_contains_credential( $query ) ) {
			throw new LicenseException( 'invalid_request', 'Secrets are not accepted in the URL.', 400 );
		}
	}

	/**
	 * Rejects credential proof carried in a privileged JSON body.
	 *
	 * @param string $body Raw request body.
	 * @throws LicenseException When the body contains credential material.
	 */
	private function assert_no_body_credentials( string $body ): void {
		if ( '' !== $body && ( new CredentialToken() )->body_contains_credential( $body ) ) {
			throw new LicenseException( 'invalid_request', 'Credentials are accepted only in the Authorization header.', 400 );
		}
	}
}
