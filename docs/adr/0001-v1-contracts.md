# ADR 0001: Frozen Free V1 contracts

Status: accepted for development; release evidence pending  
Date: 2026-08-24

## Compatibility baseline

Require WordPress 6.9+, WooCommerce 10.8+, PHP 8.0+, Sodium, HTTPS, and transactional InnoDB plugin tables. Tested-version headers must not advance without recorded tests.

## License and activation semantics

- Persist `available`, `assigned`, `suspended`, `revoked`; compute expiry and activation conditions.
- Treat revocation as terminal in v1. Suspension is reversible.
- Use `NULL = unlimited`, `0 = disabled`, `1+ = maximum active installations` everywhere.
- Reuse one activation row per `(license_id, instance_fingerprint)`; keep history in versioned events.
- Precedence is explicit license override, variation, product, generator/import profile, then global default. Zero, unlimited, and lifetime are present values, never missing values.
- Variation issuance overrides product issuance. Modes are `per_quantity` and `per_item`; quantity never chooses a mode implicitly.

## Key normalization and entropy

`generated-ascii-v1` trims only surrounding HT/LF/CR/space, rejects non-ASCII and controls, removes internal ASCII spaces plus the one declared display separator, folds a–z to uppercase, and rejects undeclared punctuation. `import-exact-v1` preserves case, Unicode, spaces, and separators, removing only an import-file BOM and record terminator when the importer explicitly says it is processing a record.

Generated keys use independent uniform positions. Effective entropy is the sum of `log2(unique post-normalization alphabet size)` for each random position without upward rounding. Defaults provide 130 bits. Warn below 128 bits and reject below 96 bits. Prefixes, suffixes, separators, fixed text, and checksums add zero bits.

## Encryption and recovery

The only production root is `DREAMAX_LICENSE_MANAGER_MASTER_KEY`, exactly 32 random bytes encoded as unpadded base64url. Store only a per-site random KDF salt and non-secret root identifier. Derive separate encryption, lookup, instance, rate-limit, and idempotency keys with HKDF-SHA-256 contexts containing the site identity. Encrypt keys with XChaCha20-Poly1305 and versioned associated data. Search with keyed HMAC-SHA-256 in `BINARY(32)`.

Missing, malformed, or mismatched key material enters recovery mode. Block creation, assignment, reveal, export, and key-dependent API operations. Never replace the key or ciphertext automatically. Clear recovery mode only after the identifier matches and authenticated decryption/self-test succeeds.

## REST v1

Namespace: `dreamax-license-manager/v1`. Required envelope fields are `success`, `code`, `message`, `request_id`, `timestamp`, and `data`. Additive fields are compatible; changed meanings, normalization, identifiers, activation semantics, or error meanings require a new API version or documented migration.

Mutation idempotency accepts 8–128 visible ASCII bytes. Bind a keyed scope to API version, operation, license scope, product, instance, caller context, canonical payload, and caller key. Same scope and digest replays; a different digest returns `409 idempotency_conflict`. Retain for 24 hours, safely filterable only from 1 hour to 7 days. Raw keys and caller idempotency values are never stored.

Body ceilings are 16 KiB public and 64 KiB privileged; Authorization is 256 bytes; Idempotency-Key is 128 bytes. Secrets in URLs are rejected before business processing.

## Rate limits

Freeze the specification defaults: read key scope 60 with 1/second; mutation key+instance 10 with 1/6 seconds; failed/unknown network 20 with 1/30 seconds; aggregate network 120 with 2/second; privileged auth failures 10 with 1/60 seconds; endpoint breaker 600 with 10/second and a 30-second diagnostic cooldown. Stored bucket identities are keyed hashes; source networks are IPv4 /24 and IPv6 /56 by default.

## Privileged authentication

Use `Authorization: Bearer dlm_v1_<22-char-public-id>.<43-char-secret>` over verified HTTPS. Generate public ID from 16 bytes and secret from 32 bytes. Store only a password verifier. Reject malformed, multiple/comma-joined, overlong, control-bearing, query, or body credentials. Scopes are `licenses:read`, `licenses:write`, `activations:read`, `generators:read`.

## Capabilities

Administrators receive all dedicated capabilities. Shop Managers receive ordinary management and non-secret diagnostics only. Reveal, unmasked export, credential management, permanent deletion, and security/recovery stay separate.

## Audit and privacy

Each event has an opaque ID, stable type, positive `schema_version`, UTC time, actor classification, references, and allowlisted metadata. Claims are compatibility-sensitive but not cryptographically tamper-proof against a fully compromised database administrator.

Erasure anonymizes direct customer links, labels, site URLs, and network fingerprints while retaining minimum non-personal license/order/lifecycle/security evidence. No full key, API secret, claim token, raw IP, or idempotency secret is retained for audit.

## Future commercial extension contracts

Reserve additive, versioned contracts for `EntitlementProviderInterface`, `InstallationCredentialIssuerInterface`, `BillingEventAdapterInterface`, `ReleaseMetadataProviderInterface`, and `PackageAuthorizationInterface`. Free implementations are no-op and Free behavior must not depend on an add-on. Interface signatures and DTO schemas remain release-blocked until a dedicated ADR and contract tests exist; placeholders, remote calls, paywalls, and disabled commercial UI are forbidden.
