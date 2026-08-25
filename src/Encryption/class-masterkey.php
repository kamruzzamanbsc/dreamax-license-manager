<?php
/**
 * Defines the MasterKey class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Encryption;

use Dreamax\LicenseManager\Support\Base64Url;
use RuntimeException;
use Throwable;

/**
 * Handles Master key operations.
 */
final class MasterKey {
	public const CONSTANT_NAME = 'DREAMAX_LICENSE_MANAGER_MASTER_KEY';

	/**
	 * Site-local KDF salt manager.
	 *
	 * @var KdfSalt
	 */
	private KdfSalt $kdf_salt;

	/**
	 * Initializes key dependencies.
	 *
	 * @param ?KdfSalt $kdf_salt Site-local KDF salt manager.
	 */
	public function __construct( ?KdfSalt $kdf_salt = null ) {
		$this->kdf_salt = $kdf_salt ?? new KdfSalt();
	}

	/**
	 * Handles the ready operation.
	 */
	public function ready(): bool {
		try {
			$this->root();
			return extension_loaded( 'sodium' );
		} catch ( Throwable $error ) {
			return false;
		}
	}

	/**
	 * Handles the root operation.
	 *
	 * @throws RuntimeException When the operation cannot be completed.
	 */
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
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The throwable is chained as the previous exception and is not output.
			throw new RuntimeException( 'The Dreamax master key is not valid base64url.', 0, $error );
		}

		if ( 32 !== strlen( $key ) ) {
			throw new RuntimeException( 'The Dreamax master key must decode to exactly 32 bytes.' );
		}

		return $key;
	}

	/**
	 * Handles the derived operation.
	 *
	 * @param string $purpose Purpose value.
	 */
	public function derived( string $purpose ): string {
		$salt    = $this->kdf_salt->load( $this->identifier() );
		$context = sprintf( 'dreamax-lm|site:%d|%s|v1', get_current_blog_id(), $purpose );
		$derived = hash_hkdf( 'sha256', $this->root(), 32, $context, $salt );
		sodium_memzero( $salt );
		return $derived;
	}

	/**
	 * Handles the identifier operation.
	 */
	public function identifier(): string {
		return substr( hash( 'sha256', $this->root() ), 0, 16 );
	}

	/**
	 * Handles the verify or record identifier operation.
	 */
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
