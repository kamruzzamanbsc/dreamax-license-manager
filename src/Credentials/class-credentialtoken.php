<?php
/**
 * Defines the CredentialToken class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Credentials;

use Dreamax\LicenseManager\Support\Base64Url;

/**
 * Generates, parses, and verifies the frozen privileged credential format.
 */
final class CredentialToken {
	public const PUBLIC_ID_BYTES  = 16;
	public const SECRET_BYTES     = 32;
	public const PUBLIC_ID_LENGTH = 22;
	public const SECRET_LENGTH    = 43;
	public const HEADER_LIMIT     = 256;

	/**
	 * A valid modern verifier used only to equalize unknown and malformed failures.
	 */
	private const DUMMY_ARGON2ID_HASH = '$argon2id$v=19$m=65536,t=4,p=1$d0dqRHFxcC5saE5aUndjTQ$6ii5D3Cw7vXPBjPHk/1sRwT/Jcv/44mDVz82GXAdpEg';
	private const DUMMY_BCRYPT_HASH   = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

	/**
	 * Generates one public identifier and secret.
	 *
	 * @return array{public_id:string,secret:string,credential:string}
	 */
	public function generate(): array {
		$public_id = Base64Url::encode( random_bytes( self::PUBLIC_ID_BYTES ) );
		$secret    = $this->generate_secret();
		return array(
			'public_id'  => $public_id,
			'secret'     => $secret,
			'credential' => $this->format( $public_id, $secret ),
		);
	}

	/**
	 * Generates only a replacement secret for same-ID rotation.
	 */
	public function generate_secret(): string {
		return Base64Url::encode( random_bytes( self::SECRET_BYTES ) );
	}

	/**
	 * Formats a generated identifier and secret using the frozen wire contract.
	 *
	 * @param string $public_id Public credential ID.
	 * @param string $secret Credential secret.
	 */
	public function format( string $public_id, string $secret ): string {
		return 'dlm_v1_' . $public_id . '.' . $secret;
	}

	/**
	 * Parses only the exact frozen Authorization value.
	 *
	 * @param string $header Authorization header.
	 * @return array{public_id:string,secret:string}|null
	 */
	public function parse( string $header ): ?array {
		if ( '' === $header || strlen( $header ) > self::HEADER_LIMIT || false !== strpos( $header, ',' ) || preg_match( '/[\x00-\x1F\x7F]/', $header ) ) {
			return null;
		}
		if ( 1 !== preg_match( '/^Bearer dlm_v1_([A-Za-z0-9_-]{22})\.([A-Za-z0-9_-]{43})$/D', $header, $matches ) ) {
			return null;
		}
		return array(
			'public_id' => $matches[1],
			'secret'    => $matches[2],
		);
	}

	/**
	 * Creates a modern password verifier for a credential secret.
	 *
	 * @param string $secret Credential secret.
	 */
	public function hash( string $secret ): string {
		return password_hash( $secret, defined( 'PASSWORD_ARGON2ID' ) ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT );
	}

	/**
	 * Verifies a secret without exposing verifier details.
	 *
	 * @param string $secret Presented secret.
	 * @param string $hash Stored verifier.
	 */
	public function verify( string $secret, string $hash ): bool {
		return password_verify( $secret, $hash );
	}

	/**
	 * Performs the same verifier work for malformed or unknown credentials.
	 *
	 * @param string $secret Candidate secret or fixed substitute.
	 */
	public function dummy_verify( string $secret ): void {
		$hash = defined( 'PASSWORD_ARGON2ID' ) ? self::DUMMY_ARGON2ID_HASH : self::DUMMY_BCRYPT_HASH;
		password_verify( $secret, $hash );
	}

	/**
	 * Detects a Bearer credential or credential-bearing key in a request payload.
	 *
	 * @param string $body Raw request body.
	 */
	public function body_contains_credential( string $body ): bool {
		if ( 1 === preg_match( '/dlm_v1_[A-Za-z0-9_-]{22}\.[A-Za-z0-9_-]{43}/', $body ) ) {
			return true;
		}
		$decoded = json_decode( $body, true );
		return is_array( $decoded ) && $this->array_contains_credential( $decoded );
	}

	/**
	 * Recursively detects credential-bearing field names or values.
	 *
	 * @param array $values Payload values.
	 * @phpstan-param array<array-key,mixed> $values
	 */
	private function array_contains_credential( array $values ): bool {
		foreach ( $values as $field => $value ) {
			$name = strtolower( str_replace( '-', '_', (string) $field ) );
			if ( preg_match( '/(^|_)(authorization|bearer|credential|api_secret|access_token)($|_)/', $name ) ) {
				return true;
			}
			if ( is_array( $value ) && $this->array_contains_credential( $value ) ) {
				return true;
			}
			if ( is_string( $value ) && 1 === preg_match( '/dlm_v1_[A-Za-z0-9_-]{22}\.[A-Za-z0-9_-]{43}/', $value ) ) {
				return true;
			}
		}
		return false;
	}
}
