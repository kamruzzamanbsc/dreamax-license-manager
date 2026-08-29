<?php

declare(strict_types=1);

namespace {
	if ( ! function_exists( 'get_current_blog_id' ) ) {
		function get_current_blog_id(): int {
			return 1;
		}
	}

	if ( ! function_exists( 'get_option' ) ) {
		function get_option( string $option, $default = false ) {
			return array_key_exists( $option, $GLOBALS['dreamax_lm_test_options'] ?? array() )
				? $GLOBALS['dreamax_lm_test_options'][ $option ]
				: $default;
		}
	}

	if ( ! function_exists( 'update_option' ) ) {
		function update_option( string $option, $value, ?bool $autoload = null ): bool {
			$GLOBALS['dreamax_lm_test_options'][ $option ] = $value;
			return true;
		}
	}
}

namespace Dreamax\LicenseManager\Tests\Unit {
	use Closure;
	use Dreamax\LicenseManager\Encryption\Crypto;
	use Dreamax\LicenseManager\Encryption\KdfSalt;
	use Dreamax\LicenseManager\Encryption\KdfSaltRepository;
	use Dreamax\LicenseManager\Encryption\MasterKey;
	use Dreamax\LicenseManager\Support\Base64Url;
	use PHPUnit\Framework\TestCase;
	use RuntimeException;

	final class KdfSaltTest extends TestCase {
		public static function setUpBeforeClass(): void {
			if ( ! defined( MasterKey::CONSTANT_NAME ) ) {
				define( MasterKey::CONSTANT_NAME, Base64Url::encode( random_bytes( 32 ) ) );
			}
		}

		protected function setUp(): void {
			$GLOBALS['dreamax_lm_test_options'] = array();
		}

		public function test_ascii_safe_round_trip_through_text_option_storage(): void {
			$repository = new MemoryTextKdfSaltRepository();
			$generated  = random_bytes( 32 );
			$loaded     = ( new KdfSalt( $repository, static fn(): string => $generated ) )->load( 'identifier' );

			self::assertTrue( hash_equals( $generated, $loaded ) );
			self::assertIsString( $repository->stored );
			self::assertSame( 1, preg_match( '/^v1\.[A-Za-z0-9_-]{43}$/D', $repository->stored ) );
			self::assertSame( 1, preg_match( '//u', $repository->stored ) );
			self::assertSame( 46, strlen( $repository->stored ) );
		}

		public function test_canonical_value_decodes_to_exactly_thirty_two_bytes(): void {
			$repository         = new MemoryTextKdfSaltRepository();
			$repository->stored = $this->encode( random_bytes( 32 ) );
			$loaded             = ( new KdfSalt( $repository ) )->load( 'identifier' );

			self::assertSame( 32, strlen( $loaded ) );
		}

		/**
		 * @dataProvider malformedValues
		 */
		public function test_malformed_and_incorrect_length_values_fail_closed( string $stored ): void {
			$repository         = new MemoryTextKdfSaltRepository();
			$repository->stored = $stored;

			$this->expectException( RuntimeException::class );
			$this->expectExceptionMessage( 'The site KDF salt is unavailable.' );
			( new KdfSalt( $repository ) )->load( 'identifier' );
		}

		/**
		 * @return list<array{string}>
		 */
		public static function malformedValues(): array {
			return array(
				array( '' ),
				array( 'v1.invalid' ),
				array( 'v1.' . str_repeat( 'A', 42 ) ),
				array( 'v1.' . str_repeat( 'A', 44 ) ),
				array( 'v1.' . str_repeat( 'A', 42 ) . 'B' ),
				array( 'v2.' . str_repeat( 'A', 43 ) ),
			);
		}

		public function test_stored_salt_is_stable_across_requests_and_service_instances(): void {
			$repository = new MemoryTextKdfSaltRepository();
			$first      = ( new KdfSalt( $repository ) )->load( 'identifier' );
			$second     = ( new KdfSalt( $repository ) )->load( 'identifier' );

			self::assertTrue( hash_equals( $first, $second ) );
			self::assertSame( 1, $repository->initialize_calls );
		}

		public function test_encryption_readiness_self_test_succeeds_across_separate_instances(): void {
			$repository = new MemoryTextKdfSaltRepository();
			$first_key  = new MasterKey( new KdfSalt( $repository ) );
			$GLOBALS['dreamax_lm_test_options']['dreamax_lm_master_key_id'] = $first_key->identifier();
			$first  = new Crypto( $first_key );
			$second = new Crypto( new MasterKey( new KdfSalt( $repository ) ) );

			self::assertTrue( $first->ready() );
			self::assertTrue( $second->ready() );
			self::assertSame( 1, $repository->initialize_calls );
		}

		public function test_database_write_failure_fails_closed_with_generic_error(): void {
			$repository                     = new MemoryTextKdfSaltRepository();
			$repository->initialize_failure = true;

			try {
				( new KdfSalt( $repository ) )->load( 'identifier' );
				self::fail( 'Expected fail-closed initialization.' );
			} catch ( RuntimeException $error ) {
				self::assertSame( 'The site KDF salt is unavailable.', $error->getMessage() );
				self::assertNull( $error->getPrevious() );
			}
		}

		public function test_successful_write_with_mismatched_read_back_fails_closed(): void {
			$repository                            = new MemoryTextKdfSaltRepository();
			$repository->mismatch_after_initialize = $this->encode( random_bytes( 32 ) );

			$this->expectException( RuntimeException::class );
			$this->expectExceptionMessage( 'The site KDF salt is unavailable.' );
			( new KdfSalt( $repository ) )->load( 'identifier' );
		}

		public function test_concurrent_first_initialization_uses_the_canonical_winner(): void {
			$winner                                = random_bytes( 32 );
			$repository                            = new MemoryTextKdfSaltRepository();
			$repository->concurrent_initialize_winner = $this->encode( $winner );
			$loser                                 = ( new KdfSalt( $repository ) )->load( 'identifier' );

			self::assertTrue( hash_equals( $winner, $loser ) );
			self::assertSame( 1, $repository->initialize_calls );
		}

		public function test_missing_salt_with_protected_rows_never_generates_replacement(): void {
			$repository                 = new MemoryTextKdfSaltRepository();
			$repository->protected_data = true;
			$generator_calls            = 0;

			try {
				( new KdfSalt(
					$repository,
					static function () use ( &$generator_calls ): string {
						++$generator_calls;
						return random_bytes( 32 );
					}
				) )->load( 'identifier' );
				self::fail( 'Expected protected-data recovery mode.' );
			} catch ( RuntimeException $error ) {
				self::assertSame( 'The site KDF salt is unavailable.', $error->getMessage() );
			}

			self::assertSame( 0, $generator_calls );
			self::assertNull( $repository->stored );
		}

		public function test_fresh_empty_site_with_matching_identifier_initializes_once(): void {
			$repository = new MemoryTextKdfSaltRepository();
			$loaded     = ( new KdfSalt( $repository ) )->load( 'identifier' );

			self::assertSame( 32, strlen( $loaded ) );
			self::assertSame( 1, $repository->initialize_calls );
			self::assertIsString( $repository->stored );
		}

		public function test_missing_salt_with_mismatched_identifier_fails_before_generation(): void {
			$repository                     = new MemoryTextKdfSaltRepository();
			$repository->identifier_matches = false;

			$this->expectException( RuntimeException::class );
			( new KdfSalt( $repository ) )->load( 'identifier' );
		}

		public function test_legacy_raw_salt_migrates_without_changing_derived_keys(): void {
			$legacy             = random_bytes( 32 );
			$repository         = new MemoryTextKdfSaltRepository();
			$repository->stored = $legacy;
			$first              = ( new MasterKey( new KdfSalt( $repository ) ) )->derived( 'legacy-compatibility' );
			$second             = ( new MasterKey( new KdfSalt( $repository ) ) )->derived( 'legacy-compatibility' );

			self::assertTrue( hash_equals( $first, $second ) );
			self::assertIsString( $repository->stored );
			self::assertSame( 1, preg_match( '/^v1\.[A-Za-z0-9_-]{43}$/D', $repository->stored ) );
			self::assertSame( 1, $repository->replace_calls );
		}

		public function test_legacy_migration_write_failure_fails_closed(): void {
			$repository                  = new MemoryTextKdfSaltRepository();
			$repository->stored          = random_bytes( 32 );
			$repository->replace_failure = true;

			$this->expectException( RuntimeException::class );
			$this->expectExceptionMessage( 'The site KDF salt is unavailable.' );
			( new KdfSalt( $repository ) )->load( 'identifier' );
		}

		public function test_failures_and_sources_do_not_emit_sensitive_material(): void {
			$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Encryption/class-kdfsalt.php' );

			self::assertStringNotContainsString( 'error_log', $source );
			self::assertStringNotContainsString( 'trigger_error', $source );
			self::assertStringNotContainsString( 'var_dump', $source );
			self::assertStringNotContainsString( 'print_r', $source );
		}

		private function encode( string $bytes ): string {
			return 'v1.' . Base64Url::encode( $bytes );
		}
	}

	final class MemoryTextKdfSaltRepository implements KdfSaltRepository {
		public ?string $stored = null;

		public bool $identifier_matches = true;

		public bool $protected_data = false;

		public bool $initialize_failure = false;

		public bool $replace_failure = false;

		public ?string $mismatch_after_initialize = null;

		public ?string $concurrent_initialize_winner = null;

		public int $initialize_calls = 0;

		public int $replace_calls = 0;

		public function read(): ?string {
			return $this->stored;
		}

		public function initialize( string $encoded ): bool {
			++$this->initialize_calls;
			if ( $this->initialize_failure ) {
				throw new RuntimeException( 'Repository failure details must not escape.' );
			}
			if ( null !== $this->concurrent_initialize_winner ) {
				$this->store_text( $this->concurrent_initialize_winner );
				return false;
			}
			if ( null !== $this->stored ) {
				return false;
			}

			$this->store_text( $this->mismatch_after_initialize ?? $encoded );
			return true;
		}

		public function replace( string $encoded ): bool {
			++$this->replace_calls;
			if ( $this->replace_failure ) {
				throw new RuntimeException( 'Repository failure details must not escape.' );
			}

			$this->store_text( $encoded );
			return true;
		}

		public function identifier_matches( string $identifier ): bool {
			return $this->identifier_matches;
		}

		public function has_protected_data(): bool {
			return $this->protected_data;
		}

		private function store_text( string $encoded ): void {
			if ( 1 !== preg_match( '//u', $encoded ) ) {
				throw new RuntimeException( 'Text storage rejected non-UTF-8 bytes.' );
			}

			$this->stored = unserialize( serialize( $encoded ) );
		}
	}
}
