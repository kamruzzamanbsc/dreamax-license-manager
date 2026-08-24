# Future commercial extension architecture

Free V1 remains independently useful and owns license keys, public IDs, activations, order associations, and REST v1 meanings. A later separate add-on may attach only through reviewed additive contracts:

- `EntitlementProviderInterface`: versioned per-feature grants, never a global Pro boolean.
- `InstallationCredentialIssuerInterface`: exchange initial license proof for a revocable installation-scoped credential.
- `BillingEventAdapterInterface`: idempotent provider-neutral renewal/refund inputs.
- `ReleaseMetadataProviderInterface`: versioned product/release/channel/checksum metadata.
- `PackageAuthorizationInterface`: short-lived scoped product/version/installation download authorization.

Signed entitlement documents use asymmetric signatures, document and signing-key IDs, issue/expiry/grace metadata, rotation, and revocation. Private signing material stays server-side. Cached offline grace is bounded and never turns a tampered or revoked credential into success.

These features are not implemented or advertised in Free V1. Free contains no remote call, paywall, trial gate, disabled commercial control, placeholder secret, or dormant dependency. Exact PHP signatures and DTO schemas require a dedicated compatibility ADR and contract tests before publication.
