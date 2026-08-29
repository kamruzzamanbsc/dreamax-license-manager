<?php
/**
 * Defines the CredentialAdminOperationRepository interface.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Credentials;

/**
 * Persists one-way credential administration operation reservations.
 */
interface CredentialAdminOperationRepository {
	/**
	 * Atomically reserves an operation or reports an existing reservation.
	 *
	 * @param string $operation_id One-time operation identifier.
	 * @param string $operation Bounded operation name.
	 * @param int    $actor_id Authenticated administrator ID.
	 * @param array  $payload Canonical operation payload.
	 * @phpstan-param array<string,mixed> $payload Canonical operation payload.
	 * @return string|null Opaque reservation scope for the winner; null for a replay or conflict.
	 */
	public function reserve( string $operation_id, string $operation, int $actor_id, array $payload ): ?string;

	/**
	 * Marks a winning operation complete without persisting its result.
	 *
	 * @param string $scope Opaque reservation scope.
	 */
	public function complete( string $scope ): void;

	/**
	 * Marks a winning operation failed without making its token reusable.
	 *
	 * @param string $scope Opaque reservation scope.
	 */
	public function fail( string $scope ): void;
}
