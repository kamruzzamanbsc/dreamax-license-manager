<?php
/**
 * Defines the CommercialContracts class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Contracts\Commercial\V1;

use Dreamax\LicenseManager\Database\Schema;
use Dreamax\LicenseManager\Encryption\Crypto;
use Dreamax\LicenseManager\Support\Health;
use Throwable;

/**
 * Publishes and initializes the versioned Free-Pro boundary.
 */
final class CommercialContracts {
	/** Current commercial contract version. */
	public const VERSION = 1;

	/**
	 * Supported commercial contract capabilities.
	 *
	 * @var list<string>
	 */
	private const CAPABILITIES = array(
		'billing_event_adapter.v1',
		'core_authority.v1',
		'entitlement_provider.v1',
		'installation_credential_issuer.v1',
		'license_expiry_extension_command.v1',
		'package_authorization.v1',
		'release_metadata_provider.v1',
	);

	/**
	 * Locked runtime registry.
	 *
	 * @var CommercialProviderRegistry|null
	 */
	private static ?CommercialProviderRegistry $registry = null;

	/** Returns non-sensitive Free compatibility state. */
	public static function descriptor(): ContractDescriptor {
		return new ContractDescriptor(
			array(
				'contract_version' => self::VERSION,
				'free_version'     => defined( 'DREAMAX_LM_VERSION' ) ? (string) DREAMAX_LM_VERSION : '0.0.0',
				'schema_version'   => Schema::VERSION,
				'capabilities'     => self::CAPABILITIES,
				'storage_ready'    => Health::storage_ready(),
				'encryption_ready' => ( new Crypto() )->ready(),
			)
		);
	}

	/** Initializes and locks provider registration exactly once. */
	public static function bootstrap(): CommercialProviderRegistry {
		if ( null !== self::$registry ) {
			return self::$registry;
		}

		$authority = new CoreAuthority();
		$registry  = new CommercialProviderRegistry( $authority );
		try {
			do_action( 'dreamax_lm_register_commercial_providers_v1', $registry );
		} catch ( Throwable $exception ) {
			$registry = new CommercialProviderRegistry( $authority );
		}
		$registry->lock();
		self::$registry = $registry;
		return $registry;
	}

	/**
	 * Returns the initialized provider registry.
	 *
	 * @throws ContractException When bootstrap has not completed.
	 */
	public static function registry(): CommercialProviderRegistry {
		if ( null === self::$registry ) {
			throw new ContractException( 'Commercial contracts are not initialized.' );
		}
		return self::$registry;
	}
}
