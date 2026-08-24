<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Encryption;

use Dreamax\LicenseManager\Support\Base64Url;
use RuntimeException;

final class Crypto {
	private MasterKey $master_key;

	public function __construct( ?MasterKey $master_key = null ) {
		$this->master_key = $master_key ?? new MasterKey();
	}

	public function ready(): bool {
		if ( ! $this->master_key->ready() || ! $this->master_key->verify_or_record_identifier() ) {
			return false;
		}

		$probe = 'dreamax-readiness-probe';
		try {
			return hash_equals( $probe, $this->decrypt( $this->encrypt( $probe ) ) );
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	public function encrypt( string $plaintext ): string {
		$key   = $this->master_key->derived( 'license-encryption' );
		$nonce = random_bytes( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES );
		$aad   = $this->aad();
		$data  = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt( $plaintext, $aad, $nonce, $key );
		sodium_memzero( $key );

		return 'v1.' . Base64Url::encode( $nonce ) . '.' . Base64Url::encode( $data );
	}

	public function decrypt( string $payload ): string {
		$parts = explode( '.', $payload );
		if ( 3 !== count( $parts ) || 'v1' !== $parts[0] ) {
			throw new RuntimeException( 'Unsupported encrypted payload.' );
		}

		$nonce = Base64Url::decode( $parts[1] );
		$data  = Base64Url::decode( $parts[2] );
		$key   = $this->master_key->derived( 'license-encryption' );
		$clear = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt( $data, $this->aad(), $nonce, $key );
		sodium_memzero( $key );

		if ( false === $clear ) {
			throw new RuntimeException( 'Encrypted license data could not be authenticated.' );
		}

		return $clear;
	}

	public function fingerprint( string $canonical, string $purpose = 'license-lookup' ): string {
		$key = $this->master_key->derived( $purpose );
		$mac = hash_hmac( 'sha256', $canonical, $key, true );
		sodium_memzero( $key );
		return $mac;
	}

	public function keyed_hash( string $value, string $purpose ): string {
		return $this->fingerprint( $value, $purpose );
	}

	private function aad(): string {
		return 'dreamax-lm|license-key|site:' . get_current_blog_id() . '|v1';
	}
}
