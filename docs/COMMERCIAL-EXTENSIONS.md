# Commercial extension contract v1

Free remains independently useful and owns license keys, public IDs, activations, order associations, cryptographic policy, and REST v1 meanings. Version 0.4.0 publishes an additive PHP contract for separately distributed extensions under `Dreamax\LicenseManager\Contracts\Commercial\V1`.

The contract includes a Free-owned `CoreAuthorityInterface` plus these extension-provider interfaces:

- `EntitlementProviderInterface`: versioned per-feature grants, never a global Pro boolean.
- `InstallationCredentialIssuerInterface`: exchange initial license proof for a revocable installation-scoped credential.
- `BillingEventAdapterInterface`: idempotent provider-neutral renewal/refund inputs.
- `ReleaseMetadataProviderInterface`: versioned product/release/channel/checksum metadata.
- `PackageAuthorizationInterface`: short-lived scoped product/version/installation download authorization.

Signed entitlement documents use asymmetric signatures, document and signing-key IDs, issue/expiry/grace metadata, rotation, and revocation. Private signing material stays server-side. Cached offline grace is bounded and never turns a tampered or revoked credential into success.

Extensions register providers during `dreamax_lm_register_commercial_providers_v1`. Registration locks immediately after that action. Duplicate or late providers are rejected. The compatibility descriptor reports contract, Free, and schema versions plus readiness and supported capabilities without exposing configuration or customer data.

Free 0.4.1 adds `license_expiry_extension_command.v1`. Extensions obtain the `LicenseExpiryExtensionAuthorityInterface` from `CommercialProviderRegistry::expiry_extension_authority()` and submit a `LicenseExpiryExtensionCommand`. The command binds an opaque license ID to the expected opaque product ID, an extension duration, a whole-second UTC billing-event time, and a deterministic operation ID. Free validates the request, owns the transaction and audit event, rejects an operation ID reused with changed command fields, and returns the originally stored expiry for an exact replay. Commands fail closed while storage or encryption recovery is unavailable. The effective time cannot be future-dated beyond the bounded clock-skew allowance.

The command is intentionally limited to extending an existing finite license. It cannot create, import, reveal, reassign, suspend, revoke, delete, or change activation limits. Extensions remain responsible for verifying provider events and deriving deterministic operation IDs before invoking it.

`CoreAuthorityInterface` is the supported read path from an extension to authoritative Free license and installation state. `LicenseExpiryExtensionAuthorityInterface` is the only supported commercial write path. Snapshots and command results exclude license keys, ciphertext, key and instance fingerprints, internal Free IDs, customer email, private metadata, table names, and repository objects. Extensions must not query or mutate `dreamax_lm_*` tables directly.

Secret-bearing `LicenseProof`, `InstallationProof`, and `IssuedCredential` values redact debug output and reject serialization. Provider messages are immutable, field-allowlisted, nesting-bounded, and reject common secret-bearing fields.

The provider capabilities themselves are not implemented or advertised by Free. Free contains no remote call, paywall, trial gate, disabled commercial control, placeholder secret, or dormant commercial dependency. Free behavior and its public REST v1 contract remain unchanged when no extension is installed.
