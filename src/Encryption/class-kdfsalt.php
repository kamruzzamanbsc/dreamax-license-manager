<?php
/**
 * Defines the KdfSalt class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Encryption;

use Closure;
use Dreamax\LicenseManager\Support\Base64Url;
use RuntimeException;
use Throwable;

/**
 * Loads, initializes, and compatibly migrates a site-local KDF salt.
 */
final class KdfSalt {
	private const PREFIX = 'v1.';

	private const BYTE_LENGTH = 32;

	private const ENCODED_LENGTH = 43;

	private const ERROR = 'The site KDF salt is unavailable.';

	/**
	 * Site-local repository.
	 *
	 * @var KdfSaltRepository
	 */
	private KdfSaltRepository $repository;

	/**
	 * Random-byte generator.
	 *
	 * @var Closure(int):string
	 */
	private Closure $random_bytes;

	/**
	 * Initializes salt dependencies.
	 *
	 * @param ?KdfSaltRepository $repository  Site-local repository.
	 * @param ?Closure           $random_bytes Cryptographic random-byte generator.
	 * @phpstan-param (Closure(int): string)|null $random_bytes
	 */
	public function __construct( ?KdfSaltRepository $repository = null, ?Closure $random_bytes = null ) {
		$this->repository   = $repository ?? new WordPressKdfSaltRepository();
		$this->random_bytes = $random_bytes ?? static fn( int $length ): string => random_bytes( $length );
	}

	/**
	 * Returns exactly 32 salt bytes or fails closed.
	 *
	 * @param string $identifier Current non-secret root identifier.
	 * @throws RuntimeException When a safe canonical salt is unavailable.
	 */
	public function load( string $identifier ): string {
		try {
			$stored = $this->repository->read();
			if ( null !== $stored ) {
				return $this->resolve_stored( $stored );
			}

			if ( ! $this->repository->identifier_matches( $identifier ) || $this->repository->has_protected_data() ) {
				throw $this->failure();
			}

			return $this->initialize();
		} catch ( Throwable $error ) {
			throw $this->failure();
		}
	}

	/**
	 * Initializes a fresh site with one concurrency-safe canonical value.
	 *
	 * @throws RuntimeException When initialization or verification fails.
	 */
	private function initialize(): string {
		$generated = ( $this->random_bytes )( self::BYTE_LENGTH );
		if ( self::BYTE_LENGTH !== strlen( $generated ) ) {
			throw $this->failure();
		}

		try {
			$encoded = $this->encode( $generated );
			$created = $this->repository->initialize( $encoded );
			$stored  = $this->repository->read();
			if ( null === $stored ) {
				throw $this->failure();
			}

			$winner = $this->resolve_stored( $stored );
			if ( $created && ! hash_equals( $generated, $winner ) ) {
				sodium_memzero( $winner );
				throw $this->failure();
			}

			return $winner;
		} finally {
			sodium_memzero( $generated );
		}
	}

	/**
	 * Decodes the versioned format or migrates a valid legacy raw value.
	 *
	 * @param string $stored Stored representation.
	 */
	private function resolve_stored( string $stored ): string {
		if ( self::BYTE_LENGTH === strlen( $stored ) ) {
			return $this->migrate_legacy( $stored );
		}

		return $this->decode( $stored );
	}

	/**
	 * Migrates raw 32-byte legacy storage without changing the salt bytes.
	 *
	 * @param string $legacy Legacy raw salt bytes.
	 * @throws RuntimeException When migration or verification fails.
	 */
	private function migrate_legacy( string $legacy ): string {
		$encoded  = $this->encode( $legacy );
		$replaced = $this->repository->replace( $encoded );
		$stored   = $this->repository->read();

		if ( null === $stored || ( ! $replaced && self::BYTE_LENGTH === strlen( $stored ) ) ) {
			throw $this->failure();
		}

		$migrated = $this->decode( $stored );
		if ( ! hash_equals( $legacy, $migrated ) ) {
			sodium_memzero( $migrated );
			throw $this->failure();
		}

		return $migrated;
	}

	/**
	 * Encodes 32 bytes as the canonical ASCII-safe versioned representation.
	 *
	 * @param string $salt Raw salt bytes.
	 * @throws RuntimeException When the byte length is invalid.
	 */
	private function encode( string $salt ): string {
		if ( self::BYTE_LENGTH !== strlen( $salt ) ) {
			throw $this->failure();
		}

		return self::PREFIX . Base64Url::encode( $salt );
	}

	/**
	 * Strictly decodes the canonical representation.
	 *
	 * @param string $stored Stored representation.
	 * @throws RuntimeException When the representation is invalid.
	 */
	private function decode( string $stored ): string {
		if ( 1 !== preg_match( '/^v1\.([A-Za-z0-9_-]{43})$/D', $stored, $matches ) ) {
			throw $this->failure();
		}

		$decoded = Base64Url::decode( $matches[1] );
		if ( self::BYTE_LENGTH !== strlen( $decoded ) || self::ENCODED_LENGTH !== strlen( $matches[1] ) || ! hash_equals( $matches[1], Base64Url::encode( $decoded ) ) ) {
			sodium_memzero( $decoded );
			throw $this->failure();
		}

		return $decoded;
	}

	/**
	 * Creates the fixed non-sensitive readiness failure.
	 */
	private function failure(): RuntimeException {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed internal text contains no salt and is never rendered directly.
		return new RuntimeException( self::ERROR );
	}
}
