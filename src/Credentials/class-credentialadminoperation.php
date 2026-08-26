<?php
/**
 * Defines the CredentialAdminOperation class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Credentials;

use RuntimeException;
use Throwable;

/**
 * Enforces one-time credential administration POST operations.
 */
final class CredentialAdminOperation {
	public const CREATE = 'admin-create';
	public const ROTATE = 'admin-rotate';

	/**
	 * Reservation repository.
	 *
	 * @var CredentialAdminOperationRepository
	 */
	private CredentialAdminOperationRepository $repository;

	/**
	 * Initializes the operation gate.
	 *
	 * @param CredentialAdminOperationRepository|null $repository Reservation repository.
	 */
	public function __construct( ?CredentialAdminOperationRepository $repository = null ) {
		$this->repository = $repository ?? new WordPressCredentialAdminOperationRepository();
	}

	/**
	 * Issues a WordPress-user-bound operation token for one form.
	 *
	 * @param string   $operation Operation name.
	 * @param string   $context Non-secret operation context.
	 * @param int|null $actor_id Authenticated administrator ID.
	 * @return array{id:string,nonce:string}
	 * @throws RuntimeException When a token cannot be issued.
	 */
	public function issue( string $operation, string $context = '', ?int $actor_id = null ): array {
		$actor_id = $actor_id ?? get_current_user_id();
		if ( ! $this->allowed( $operation ) || $actor_id < 1 ) {
			throw new RuntimeException( 'The credential operation token could not be issued.' );
		}
		$operation_id = wp_generate_uuid4();
		return array(
			'id'    => $operation_id,
			'nonce' => wp_create_nonce( $this->nonce_action( $operation_id, $operation, $context, $actor_id ) ),
		);
	}

	/**
	 * Executes the first valid submission and returns no result for every replay.
	 *
	 * @param string   $operation_id One-time operation identifier.
	 * @param string   $operation_nonce User-bound WordPress nonce.
	 * @param string   $operation Operation name.
	 * @param string   $context Non-secret operation context.
	 * @param int      $actor_id Authenticated administrator ID.
	 * @param array    $payload Exact operation payload.
	 * @phpstan-param array<string,mixed> $payload Exact operation payload.
	 * @param callable $callback Credential mutation callback.
	 * @phpstan-param callable():array<string,mixed> $callback Credential mutation callback.
	 * @return array{processed:bool,result:array<string,mixed>|null}
	 * @throws RuntimeException When reservation or mutation completion fails.
	 */
	public function execute( string $operation_id, string $operation_nonce, string $operation, string $context, int $actor_id, array $payload, callable $callback ): array {
		if ( ! $this->valid( $operation_id, $operation_nonce, $operation, $context, $actor_id ) ) {
			return array(
				'processed' => false,
				'result'    => null,
			);
		}

		try {
			$scope = $this->repository->reserve( $operation_id, $operation, $actor_id, $payload );
		} catch ( Throwable ) {
			throw new RuntimeException( 'The credential operation could not be processed.' );
		}
		if ( null === $scope ) {
			return array(
				'processed' => false,
				'result'    => null,
			);
		}

		$result = null;
		try {
			$result = $callback();
			$this->repository->complete( $scope );
			return array(
				'processed' => true,
				'result'    => $result,
			);
		} catch ( Throwable ) {
			if ( is_array( $result ) && isset( $result['credential'] ) && is_string( $result['credential'] ) && '' !== $result['credential'] ) {
				sodium_memzero( $result['credential'] );
			}
			$this->fail_safely( $scope );
			throw new RuntimeException( 'The credential operation could not be processed.' );
		}
	}

	/**
	 * Validates a user/action/context-bound operation token.
	 *
	 * @param string $operation_id One-time operation identifier.
	 * @param string $nonce User-bound WordPress nonce.
	 * @param string $operation Operation name.
	 * @param string $context Non-secret operation context.
	 * @param int    $actor_id Authenticated administrator ID.
	 */
	private function valid( string $operation_id, string $nonce, string $operation, string $context, int $actor_id ): bool {
		if ( ! $this->allowed( $operation ) || $actor_id < 1 || ! wp_is_uuid( $operation_id, 4 ) || '' === $nonce ) {
			return false;
		}
		return false !== wp_verify_nonce( $nonce, $this->nonce_action( $operation_id, $operation, $context, $actor_id ) );
	}

	/**
	 * Builds the WordPress nonce action without credential material.
	 *
	 * @param string $operation_id One-time operation identifier.
	 * @param string $operation Operation name.
	 * @param string $context Non-secret operation context.
	 * @param int    $actor_id Authenticated administrator ID.
	 */
	private function nonce_action( string $operation_id, string $operation, string $context, int $actor_id ): string {
		return 'dreamax_lm_credential_admin|' . $operation . '|' . $actor_id . '|' . $operation_id . '|' . hash( 'sha256', $context );
	}

	/**
	 * Checks the closed operation catalog.
	 *
	 * @param string $operation Operation name.
	 */
	private function allowed( string $operation ): bool {
		return in_array( $operation, array( self::CREATE, self::ROTATE ), true );
	}

	/**
	 * Best-effort terminal failure recording without making the reservation reusable.
	 *
	 * @param string $scope Opaque reservation scope.
	 */
	private function fail_safely( string $scope ): void {
		try {
			$this->repository->fail( $scope );
		} catch ( Throwable ) {
			// The durable processing reservation already prevents another mutation.
			return;
		}
	}
}
