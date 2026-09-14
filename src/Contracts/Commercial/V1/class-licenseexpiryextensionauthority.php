<?php
/**
 * Defines the LicenseExpiryExtensionAuthority class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Contracts\Commercial\V1;

use Dreamax\LicenseManager\Encryption\Crypto;
use Dreamax\LicenseManager\Licenses\LicenseException;
use Dreamax\LicenseManager\Licenses\LifecycleService;
use Dreamax\LicenseManager\Support\Health;
use InvalidArgumentException;
use Throwable;

/** Adapts one narrow commercial command to the Free-owned lifecycle service. */
final class LicenseExpiryExtensionAuthority implements LicenseExpiryExtensionAuthorityInterface {
	/**
	 * Free-owned lifecycle service.
	 *
	 * @var LifecycleService
	 */
	private LifecycleService $lifecycle;

	/** Initializes the authority with the Free-owned lifecycle service. */
	public function __construct() {
		$this->lifecycle = new LifecycleService();
	}

	/**
	 * Applies one validated expiry extension command.
	 *
	 * @param LicenseExpiryExtensionCommand $command Validated command.
	 */
	public function extend( LicenseExpiryExtensionCommand $command ): LicenseExpiryExtensionResult {
		$now = gmdate( 'Y-m-d\TH:i:s\Z' );
		if ( ! Health::storage_ready() ) {
			return new LicenseExpiryExtensionResult( false, 'core_storage_unavailable', null, null, false, $now );
		}
		if ( ! ( new Crypto() )->ready() ) {
			return new LicenseExpiryExtensionResult( false, 'core_encryption_unavailable', null, null, false, $now );
		}

		try {
			$result   = $this->lifecycle->extend_from_commercial_contract(
				$command->license_public_id(),
				$command->product_public_id(),
				$command->extension_days(),
				$command->effective_timestamp(),
				$command->operation_id(),
				$command->digest()
			);
			$replayed = true === ( $result['replayed'] ?? false );
			return new LicenseExpiryExtensionResult(
				true,
				$replayed ? 'replayed' : 'extended',
				(string) $result['public_id'],
				(string) $result['expires_at'],
				$replayed,
				$now
			);
		} catch ( LicenseException $exception ) {
			return new LicenseExpiryExtensionResult( false, $exception->machine_code(), null, null, false, $now );
		} catch ( InvalidArgumentException $exception ) {
			return new LicenseExpiryExtensionResult( false, 'command_rejected', null, null, false, $now );
		} catch ( Throwable $exception ) {
			return new LicenseExpiryExtensionResult( false, 'core_storage_unavailable', null, null, false, $now );
		}
	}
}
