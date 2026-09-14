<?php
/**
 * Defines the CommercialProviderRegistry class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Contracts\Commercial\V1;

/**
 * Accepts one provider per capability during the bounded registration window.
 */
final class CommercialProviderRegistry {
	/**
	 * Free-owned authority adapter.
	 *
	 * @var CoreAuthorityInterface
	 */
	private CoreAuthorityInterface $core_authority;
	/**
	 * Free-owned expiry extension authority.
	 *
	 * @var LicenseExpiryExtensionAuthorityInterface
	 */
	private LicenseExpiryExtensionAuthorityInterface $expiry_extension_authority;
	/**
	 * Registered entitlement provider.
	 *
	 * @var EntitlementProviderInterface|null
	 */
	private ?EntitlementProviderInterface $entitlement_provider = null;
	/**
	 * Registered credential issuer.
	 *
	 * @var InstallationCredentialIssuerInterface|null
	 */
	private ?InstallationCredentialIssuerInterface $credential_issuer = null;
	/**
	 * Registered release provider.
	 *
	 * @var ReleaseMetadataProviderInterface|null
	 */
	private ?ReleaseMetadataProviderInterface $release_provider = null;
	/**
	 * Registered package authorizer.
	 *
	 * @var PackageAuthorizationInterface|null
	 */
	private ?PackageAuthorizationInterface $package_authorizer = null;
	/**
	 * Billing adapters keyed by stable provider identifier.
	 *
	 * @var array<string,BillingEventAdapterInterface>
	 */
	private array $billing_adapters = array();
	/**
	 * Whether the registration window is closed.
	 *
	 * @var bool
	 */
	private bool $locked = false;

	/**
	 * Creates an open registry around the immutable Free authority.
	 *
	 * @param CoreAuthorityInterface                        $core_authority Free-owned authority adapter.
	 * @param LicenseExpiryExtensionAuthorityInterface|null $expiry_extension_authority Free-owned command authority.
	 */
	public function __construct( CoreAuthorityInterface $core_authority, ?LicenseExpiryExtensionAuthorityInterface $expiry_extension_authority = null ) {
		$this->core_authority             = $core_authority;
		$this->expiry_extension_authority = $expiry_extension_authority ?? new LicenseExpiryExtensionAuthority();
	}

	/** Returns the immutable Free authority adapter. */
	public function core_authority(): CoreAuthorityInterface {
		return $this->core_authority;
	}

	/** Returns the narrow Free-owned expiry extension authority. */
	public function expiry_extension_authority(): LicenseExpiryExtensionAuthorityInterface {
		return $this->expiry_extension_authority;
	}

	/**
	 * Registers the single entitlement provider.
	 *
	 * @param EntitlementProviderInterface $provider Entitlement provider.
	 * @throws ContractException When registration is closed or duplicated.
	 */
	public function register_entitlement_provider( EntitlementProviderInterface $provider ): void {
		$this->assert_open();
		if ( null !== $this->entitlement_provider ) {
			throw new ContractException( 'An entitlement provider is already registered.' );
		}
		$this->entitlement_provider = $provider;
	}

	/** Returns the registered entitlement provider when available. */
	public function entitlement_provider(): ?EntitlementProviderInterface {
		return $this->entitlement_provider;
	}

	/**
	 * Registers the single installation credential issuer.
	 *
	 * @param InstallationCredentialIssuerInterface $provider Credential issuer.
	 * @throws ContractException When registration is closed or duplicated.
	 */
	public function register_credential_issuer( InstallationCredentialIssuerInterface $provider ): void {
		$this->assert_open();
		if ( null !== $this->credential_issuer ) {
			throw new ContractException( 'An installation credential issuer is already registered.' );
		}
		$this->credential_issuer = $provider;
	}

	/** Returns the registered credential issuer when available. */
	public function credential_issuer(): ?InstallationCredentialIssuerInterface {
		return $this->credential_issuer;
	}

	/**
	 * Registers one billing adapter per stable provider identifier.
	 *
	 * @param BillingEventAdapterInterface $adapter Billing adapter.
	 * @throws ContractException When registration is invalid, closed, or duplicated.
	 */
	public function register_billing_adapter( BillingEventAdapterInterface $adapter ): void {
		$this->assert_open();
		$provider = strtolower( $adapter->provider() );
		if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]{1,31}$/D', $provider ) ) {
			throw new ContractException( 'The billing provider identifier is invalid.' );
		}
		if ( isset( $this->billing_adapters[ $provider ] ) ) {
			throw new ContractException( 'A billing adapter is already registered for this provider.' );
		}
		$this->billing_adapters[ $provider ] = $adapter;
	}

	/**
	 * Returns the billing adapter for one provider.
	 *
	 * @param string $provider Stable billing provider identifier.
	 */
	public function billing_adapter( string $provider ): ?BillingEventAdapterInterface {
		$provider = strtolower( $provider );
		return $this->billing_adapters[ $provider ] ?? null;
	}

	/**
	 * Registers the single release metadata provider.
	 *
	 * @param ReleaseMetadataProviderInterface $provider Release metadata provider.
	 * @throws ContractException When registration is closed or duplicated.
	 */
	public function register_release_provider( ReleaseMetadataProviderInterface $provider ): void {
		$this->assert_open();
		if ( null !== $this->release_provider ) {
			throw new ContractException( 'A release metadata provider is already registered.' );
		}
		$this->release_provider = $provider;
	}

	/** Returns the registered release metadata provider when available. */
	public function release_provider(): ?ReleaseMetadataProviderInterface {
		return $this->release_provider;
	}

	/**
	 * Registers the single package authorizer.
	 *
	 * @param PackageAuthorizationInterface $provider Package authorizer.
	 * @throws ContractException When registration is closed or duplicated.
	 */
	public function register_package_authorizer( PackageAuthorizationInterface $provider ): void {
		$this->assert_open();
		if ( null !== $this->package_authorizer ) {
			throw new ContractException( 'A package authorization provider is already registered.' );
		}
		$this->package_authorizer = $provider;
	}

	/** Returns the registered package authorizer when available. */
	public function package_authorizer(): ?PackageAuthorizationInterface {
		return $this->package_authorizer;
	}

	/** Permanently closes the provider registration window. */
	public function lock(): void {
		$this->locked = true;
	}

	/** Returns whether provider registration is closed. */
	public function locked(): bool {
		return $this->locked;
	}

	/**
	 * Rejects registry mutation after bootstrap.
	 *
	 * @throws ContractException When registration is closed.
	 */
	private function assert_open(): void {
		if ( $this->locked ) {
			throw new ContractException( 'Commercial provider registration is closed.' );
		}
	}
}
