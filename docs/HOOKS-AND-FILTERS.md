# Hooks and filters

Dreamax License Manager intentionally exposes a small WordPress hook surface.
The REST v1 contract and the typed commercial extension contract are the main
developer integration boundaries. Hook callbacks must not bypass capability,
authorization, encryption, transaction, or audit requirements.

## Filters

### `dreamax_lm_low_pool_threshold`

Filters the number of available imported keys at or below which the admin
inventory reports a low-pool warning.

- Default: `5`
- Accepted result after normalization: `0` to `1000`
- Value type: integer

```php
add_filter(
	'dreamax_lm_low_pool_threshold',
	static function (): int {
		return 10;
	}
);
```

### `dreamax_lm_pool_insight_product_limit`

Filters the bounded number of products and variations inspected for imported
key-pool warnings.

- Default: `200`
- Accepted result after normalization: `1` to `500`
- Value type: integer

Increasing this value can add administration-query work on large catalogs.

### `dreamax_lm_guest_claim_lifetime`

Filters the lifetime of a newly issued guest-order claim proof when the calling
code does not provide an explicit override.

- Default: `1800` seconds (30 minutes)
- Accepted range: `300` to `86400` seconds (5 minutes to 24 hours)
- Value type: integer

Values outside the accepted range are rejected rather than silently clamped.
Keep the lifetime as short as the customer workflow reasonably permits.

## Actions

### `dreamax_lm_register_commercial_providers_v1`

Runs once during the versioned commercial-contract bootstrap and receives an
open `Dreamax\LicenseManager\Contracts\Commercial\V1\CommercialProviderRegistry`.
An extension may register the supported typed provider interfaces during this
action. The registry locks immediately afterward; duplicate, invalid, or late
registration is rejected.

```php
use Dreamax\LicenseManager\Contracts\Commercial\V1\CommercialProviderRegistry;

add_action(
	'dreamax_lm_register_commercial_providers_v1',
	static function ( CommercialProviderRegistry $registry ): void {
		// Register one implemented typed provider here.
		// $registry->register_entitlement_provider( $provider );
	}
);
```

The action is not a general-purpose way to mutate Free plugin storage. Free
retains authority over license state, activation state, cryptography, public API
meanings, expiry mutations, and audit events. See
`docs/COMMERCIAL-EXTENSIONS.md` for the complete boundary and supported
interfaces.

## Compatibility

Removing a documented hook, changing its argument meaning, or weakening its
validation is a compatibility change. Contributions that alter this surface
must include focused tests, documentation, and an explicit compatibility plan.
