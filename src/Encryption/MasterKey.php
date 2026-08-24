<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Encryption;

use Dreamax\LicenseManager\Support\Base64Url;
use RuntimeException;
use Throwable;

final class MasterKey {
	public const CONSTANT_NAME = 'DREAMAX_LICENSE_MANAGER_MASTER_KEY';

	public function ready(): bool {
		try {
			$this->root();
			return extension_loaded( 'sodium' );
		} catch ( Throwable $error ) {
			return false;
		}
	}

	public function root(): string {
		if ( ! defined( self::CONSTANT_NAME ) ) {
			throw new RuntimeException( 'The Dreamax master key is not configured.' );
		}

		$value = constant( self::CONSTANT_NAME );
		if ( ! is_string( $value ) ) {
			throw new RuntimeException( 'The Dreamax master key has an invalid type.' );
		}

		try {
			$key = Base64Url::decode( $value );
		} catch ( Throwable $error ) {
			throw new RuntimeException( 'The Dreamax master key is not valid base64url.', 0, $error );
		}

		if ( 32 !== strlen( $key ) ) {
			throw new RuntimeException( 'The Dreamax master key must decode to exactly 32 bytes.' );
		}

		return $key;
	}

	public function derived( string $purpose ): string {
		$salt = get_option( 'dreamax_lm_kdf_salt' );
		if ( ! is_string( $salt ) || 32 !== strlen( $salt ) ) {
			$salt = random_bytes( 32 );
			update_option( 'dreamax_lm_kdf_salt', $salt, false );
		}

		$context = sprintf( 'dreamax-lm|site:%d|%s|v1', get_current_blog_id(), $purpose );
		return hash_hkdf( 'sha256', $this->root(), 32, $context, $salt );
	}

	public function identifier(): string {
		return substr( hash( 'sha256', $this->root() ), 0, 16 );
	}

	public function verify_or_record_identifier(): bool {
		$stored = get_option( 'dreamax_lm_master_key_id' );
		$id     = $this->identifier();

		if ( false === $stored ) {
			update_option( 'dreamax_lm_master_key_id', $id, false );
			return true;
		}

		return is_string( $stored ) && hash_equals( $stored, $id );
	}
}
