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

`CoreAuthorityInterface` is the only supported route from an extension to authoritative Free license and installation state. Its snapshots exclude license keys, ciphertext, key and instance fingerprints, internal Free IDs, customer email, private metadata, table names, and repository objects. Extensions must not query or mutate `dreamax_lm_*` tables directly.

Secret-bearing `LicenseProof`, `InstallationProof`, and `IssuedCredential` values redact debug output and reject serialization. Provider messages are immutable, field-allowlisted, nesting-bounded, and reject common secret-bearing fields.

The provider capabilities themselves are not implemented or advertised by Free. Free contains no remote call, paywall, trial gate, disabled commercial control, placeholder secret, or dormant commercial dependency. Free behavior and its public REST v1 contract remain unchanged when no extension is installed.
