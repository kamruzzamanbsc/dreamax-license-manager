<?php

declare(strict_types=1);

namespace {
	if ( ! function_exists( 'wp_generate_uuid4' ) ) {
		function wp_generate_uuid4(): string {
			$sequence = (int) ( $GLOBALS['dreamax_lm_operation_uuid_sequence'] ?? 0 ) + 1;
			$GLOBALS['dreamax_lm_operation_uuid_sequence'] = $sequence;
			return sprintf( '00000000-0000-4000-8000-%012d', $sequence );
		}
	}

	if ( ! function_exists( 'wp_is_uuid' ) ) {
		function wp_is_uuid( string $uuid, ?int $version = null ): bool {
			$required = null === $version ? '[0-9a-f]' : (string) $version;
			return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-' . $required . '[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di', $uuid );
		}
	}

	if ( ! function_exists( 'wp_create_nonce' ) ) {
		function wp_create_nonce( string $action = '-1' ): string {
			return substr( hash( 'sha256', 'credential-admin-test|' . $action ), -10 );
		}
	}

	if ( ! function_exists( 'wp_verify_nonce' ) ) {
		function wp_verify_nonce( string $nonce, string $action = '-1' ) {
			if ( $GLOBALS['dreamax_lm_operation_nonce_expired'] ?? false ) {
				return false;
			}
			return hash_equals( wp_create_nonce( $action ), $nonce ) ? 1 : false;
		}
	}
}

namespace Dreamax\LicenseManager\Tests\Unit {
	use Closure;
	use Dreamax\LicenseManager\Credentials\CredentialAdminOperation;
	use Dreamax\LicenseManager\Credentials\CredentialAdminOperationRepository;
	use PHPUnit\Framework\TestCase;
	use RuntimeException;

	final class CredentialAdminOperationTest extends TestCase {
		private MemoryCredentialAdminOperationRepository $repository;
		private CredentialAdminOperation $operations;

		protected function setUp(): void {
			$GLOBALS['dreamax_lm_operation_uuid_sequence'] = 0;
			$GLOBALS['dreamax_lm_operation_nonce_expired'] = false;
			$this->repository = new MemoryCredentialAdminOperationRepository();
			$this->operations = new CredentialAdminOperation( $this->repository );
		}

		public function test_first_create_succeeds_once_and_identical_replay_creates_no_row_or_result(): void {
			$issued  = $this->operations->issue( CredentialAdminOperation::CREATE, '', 7 );
			$created = 0;
			$payload = array( 'name' => 'Local test', 'scopes' => array( 'licenses:read' ), 'expires_at' => '' );
			$mutate  = static function () use ( &$created ): array {
				++$created;
				return array( 'status' => 'created' );
			};

			$first  = $this->operations->execute( $issued['id'], $issued['nonce'], CredentialAdminOperation::CREATE, '', 7, $payload, $mutate );
			$replay = $this->operations->execute( $issued['id'], $issued['nonce'], CredentialAdminOperation::CREATE, '', 7, $payload, $mutate );

			self::assertTrue( $first['processed'] );
			self::assertSame( array( 'status' => 'created' ), $first['result'] );
			self::assertFalse( $replay['processed'] );
			self::assertNull( $replay['result'] );
			self::assertSame( 1, $created );
			self::assertSame( 1, $this->repository->reservation_count() );
		}

		public function test_first_rotation_succeeds_once_and_identical_replay_does_not_rotate_or_return_data(): void {
			$context = 'credential-reference|1';
			$issued  = $this->operations->issue( CredentialAdminOperation::ROTATE, $context, 7 );
			$rotated = 0;
			$payload = array( 'public_id' => 'credential-reference', 'expected_version' => 1 );
			$mutate  = static function () use ( &$rotated ): array {
				++$rotated;
				return array( 'status' => 'rotated' );
			};

			$first  = $this->operations->execute( $issued['id'], $issued['nonce'], CredentialAdminOperation::ROTATE, $context, 7, $payload, $mutate );
			$replay = $this->operations->execute( $issued['id'], $issued['nonce'], CredentialAdminOperation::ROTATE, $context, 7, $payload, $mutate );

			self::assertTrue( $first['processed'] );
			self::assertFalse( $replay['processed'] );
			self::assertNull( $replay['result'] );
			self::assertSame( 1, $rotated );
			self::assertSame( 1, $this->repository->reservation_count() );
		}

		public function test_expired_malformed_foreign_actor_action_and_context_tokens_fail_closed(): void {
			$callbacks = 0;
			$mutate    = static function () use ( &$callbacks ): array {
				++$callbacks;
				return array();
			};
			$issued = $this->operations->issue( CredentialAdminOperation::CREATE, '', 7 );

			$GLOBALS['dreamax_lm_operation_nonce_expired'] = true;
			$expired = $this->operations->execute( $issued['id'], $issued['nonce'], CredentialAdminOperation::CREATE, '', 7, array(), $mutate );
			$GLOBALS['dreamax_lm_operation_nonce_expired'] = false;
			$malformed = $this->operations->execute( 'invalid', $issued['nonce'], CredentialAdminOperation::CREATE, '', 7, array(), $mutate );
			$actor     = $this->operations->execute( $issued['id'], $issued['nonce'], CredentialAdminOperation::CREATE, '', 8, array(), $mutate );
			$action    = $this->operations->execute( $issued['id'], $issued['nonce'], CredentialAdminOperation::ROTATE, '', 7, array(), $mutate );
			$context   = $this->operations->execute( $issued['id'], $issued['nonce'], CredentialAdminOperation::CREATE, 'different', 7, array(), $mutate );

			foreach ( array( $expired, $malformed, $actor, $action, $context ) as $outcome ) {
				self::assertFalse( $outcome['processed'] );
				self::assertNull( $outcome['result'] );
			}
			self::assertSame( 0, $callbacks );
			self::assertSame( 0, $this->repository->reservation_count() );
		}

		public function test_same_token_with_changed_payload_is_consumed_without_second_mutation(): void {
			$issued    = $this->operations->issue( CredentialAdminOperation::CREATE, '', 7 );
			$mutations = 0;
			$mutate    = static function () use ( &$mutations ): array {
				++$mutations;
				return array( 'status' => 'created' );
			};
			$this->operations->execute( $issued['id'], $issued['nonce'], CredentialAdminOperation::CREATE, '', 7, array( 'name' => 'First' ), $mutate );
			$conflict = $this->operations->execute( $issued['id'], $issued['nonce'], CredentialAdminOperation::CREATE, '', 7, array( 'name' => 'Changed' ), $mutate );

			self::assertFalse( $conflict['processed'] );
			self::assertNull( $conflict['result'] );
			self::assertSame( 1, $mutations );
		}

		public function test_concurrent_duplicate_submissions_have_one_winner(): void {
			$issued       = $this->operations->issue( CredentialAdminOperation::CREATE, '', 7 );
			$payload      = array( 'name' => 'Concurrent' );
			$mutations    = 0;
			$loser        = null;
			$mutate       = static function () use ( &$mutations ): array {
				++$mutations;
				return array( 'status' => 'created' );
			};
			$this->repository->on_first_reserve = function () use ( &$loser, $issued, $payload, $mutate ): void {
				$loser = $this->operations->execute( $issued['id'], $issued['nonce'], CredentialAdminOperation::CREATE, '', 7, $payload, $mutate );
			};

			$winner = $this->operations->execute( $issued['id'], $issued['nonce'], CredentialAdminOperation::CREATE, '', 7, $payload, $mutate );

			self::assertTrue( $winner['processed'] );
			self::assertIsArray( $loser );
			self::assertFalse( $loser['processed'] );
			self::assertNull( $loser['result'] );
			self::assertSame( 1, $mutations );
			self::assertSame( 1, $this->repository->reservation_count() );
		}

		public function test_reservation_and_completion_failures_remain_consumed_and_fail_closed(): void {
			$issued    = $this->operations->issue( CredentialAdminOperation::CREATE, '', 7 );
			$mutations = 0;
			$this->repository->complete_failure = true;

			try {
				$this->operations->execute(
					$issued['id'],
					$issued['nonce'],
					CredentialAdminOperation::CREATE,
					'',
					7,
					array(),
					static function () use ( &$mutations ): array {
						++$mutations;
						return array( 'status' => 'created' );
					}
				);
				self::fail( 'Expected fail-closed completion.' );
			} catch ( RuntimeException $error ) {
				self::assertSame( 'The credential operation could not be processed.', $error->getMessage() );
				self::assertNull( $error->getPrevious() );
			}

			$this->repository->complete_failure = false;
			$replay = $this->operations->execute( $issued['id'], $issued['nonce'], CredentialAdminOperation::CREATE, '', 7, array(), static fn(): array => array() );
			self::assertFalse( $replay['processed'] );
			self::assertNull( $replay['result'] );
			self::assertSame( 1, $mutations );
			self::assertSame( 'failed', $this->repository->only_state() );
		}
	}

	final class MemoryCredentialAdminOperationRepository implements CredentialAdminOperationRepository {
		/** @var array<string,array{digest:string,state:string}> */
		private array $reservations = array();
		public bool $complete_failure = false;
		public ?Closure $on_first_reserve = null;

		public function reserve( string $operation_id, string $operation, int $actor_id, array $payload ): ?string {
			$scope  = hash( 'sha256', $operation . '|' . $actor_id . '|' . $operation_id, true );
			$key    = bin2hex( $scope );
			$digest = hash( 'sha256', (string) json_encode( $payload ) );
			if ( isset( $this->reservations[ $key ] ) ) {
				return null;
			}
			$this->reservations[ $key ] = array( 'digest' => $digest, 'state' => 'processing' );
			$hook = $this->on_first_reserve;
			$this->on_first_reserve = null;
			if ( $hook instanceof Closure ) {
				$hook();
			}
			return $scope;
		}

		public function complete( string $scope ): void {
			if ( $this->complete_failure ) {
				throw new RuntimeException( 'Completion failed.' );
			}
			$this->reservations[ bin2hex( $scope ) ]['state'] = 'completed';
		}

		public function fail( string $scope ): void {
			$this->reservations[ bin2hex( $scope ) ]['state'] = 'failed';
		}

		public function reservation_count(): int {
			return count( $this->reservations );
		}

		public function only_state(): string {
			$row = reset( $this->reservations );
			return is_array( $row ) ? $row['state'] : '';
		}
	}
}
