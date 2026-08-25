# F32 versioned audit-event contract evidence — 0.3.0

- Date: 2026-08-25
- Classification: `MANUAL_ENVIRONMENT_REQUIRED`
- Scope: source, unit/consumer/source-contract, coding-standard, static-analysis, syntax, portable-autoload, rename/addition, version, whitespace, and non-content-printing secret/artifact evidence
- Secret handling: this artifact contains no plaintext license key, guest-claim proof/hash, API credential, Authorization value, idempotency secret, master key, password, cookie, nonce, session token, request body, customer record, or secret-bearing URL.

## Repository inventory and decisions

- 44 production event types across 44 emitters now select an `AuditEventCatalog` type expression and schema v1 explicitly.
- Four earlier documentation-only names—`activation_failed`, `license_expired`, `license_imported`, and `license_renewed`—are recognizable, non-persistable legacy catalog entries with explicit reasons.
- The existing schema v3 event table already contains opaque ID, positive schema version, actor/references, UTC occurrence time, and metadata fields; no database migration or historical rewrite is required.
- New writes validate event type/version, actor, positive internal references, bounded opaque request reference, required/optional typed metadata, and cross-field variants before persistence.
- Read paths preserve unknown/unsupported/legacy rows and attach a compatibility status rather than coercing them to v1.
- The two pre-existing v1 shapes for `license_revoked` and `guest_claim_administrator_override` are explicit compatible variants, not silent semantic changes.
- Recursive key/value redaction covers nested arrays and objects at both write and read boundaries.
- The contract is site-local through WordPress blog-prefixed event tables and does not claim cryptographic tamper-proofing.

## Local automated evidence

The final branch run passed: optimized Composer autoload generated 1,603 classes without ambiguity; Composer validation and WordPress PHPCS passed; PHPStan passed 49/49 files; PHPUnit passed 114/114 tests with 667 assertions; PHP syntax passed 51/51 production files; portable autoload passed 49/49 production classes; the rename verifier passed all 41 main-baseline identities and eight F25/F31/F32 additions; version consistency and `diff --check` passed; and a non-content-printing scan of 38 intended files found no high-confidence secret or prohibited artifact path. Coverage includes catalog/emitter bidirectional completeness, positive explicit versions, opaque IDs, UTC source generation, actors/references, required/optional/unknown/incompatible metadata, F25 and F31 event sets, v1 consumer fixtures, unsupported and legacy compatibility statuses, recursive object/value redaction, privacy/uninstall source guarantees, site-local table ownership, and frozen existing source-contract suites.

## Remaining live evidence

F32 is not fully PASS. A disposable WordPress/MySQL environment must still demonstrate:

- successful and rejected event writes against real WordPress database APIs, including rollback when catalog validation or audit persistence fails;
- rendering/export/support paths for supported, legacy, unknown, malformed, and unsupported-version rows without warnings or secret exposure;
- real administrator, public REST, WooCommerce, privacy exporter/eraser, F25 claim, and F31 credential event rows matching the published catalog;
- two-site creation/read/privacy/uninstall isolation with independent prefixes;
- mixed historical rows retained byte-for-byte in storage while read-side metadata is redacted and compatibility-labeled;
- database/WordPress compromise language reviewed in operational documentation, with no unsupported tamper-proof claim.
