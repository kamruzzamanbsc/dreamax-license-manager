# F32 versioned audit-event contract evidence — 0.3.0

- Date: 2026-08-30
- Classification: `PASS_WITH_EVIDENCE`
- Environment: WordPress 7.1; WooCommerce 11.0.1; PHP 8.2.4; MariaDB 10.4.28; HPOS enabled; private disposable multisite clone evidence; Dreamax License Manager 0.3.0
- Scope: authoritative event catalog, accepted and rejected writes, transactional rollback, compatibility reads, recursive redaction, administrator rendering, integrated producers, privacy, multisite ownership, historical-row preservation, operational documentation, exact cleanup, and full local quality gates
- Sensitive-data handling: no license value, claim proof/hash, API credential/verifier, Authorization value, request body, master key, KDF salt, password, Cookie value, nonce, session token, customer record, private identifier, database name, URL, or private path is retained.

## Authoritative contract

- `AuditEventCatalog` defines all 44 production schema-v1 event types and four recognizable, non-persistable legacy names.
- Every production emitter selects a catalog event expression and positive schema version explicitly.
- New writes validate the event/version pair, actor, internal references, bounded request reference, allowlisted typed metadata, and cross-field variants before persistence.
- Recursive key/value redaction runs at both write and read boundaries.
- Consumers preserve unknown, unsupported, legacy, and malformed history and label it `supported`, `legacy_catalog_entry`, `legacy_unversioned`, `unsupported_version`, `unknown_type`, or `legacy_payload`.
- The existing schema-v3 event table already contains the complete envelope; no migration or historical rewrite is required.

## Guarded live write, rollback, and compatibility matrix

The final verifier required the explicit disposable marker, a local site, the active plugin, an existing administrator, and InnoDB event and rate-limit tables. It snapshotted authoritative aggregates before creating one opaque transactional marker and exact verifier-owned event rows.

The live matrix demonstrated:

- one valid privacy-tool event was accepted through `EventRepository`, stored with schema v1, and stripped a protected extra field before persistence;
- four unknown, legacy, wrong-actor, or invalid-metadata writes were rejected;
- a catalog-validation exception rolled back a preceding mutation to the owned InnoDB marker;
- an injected audit-table persistence failure also rolled back the preceding marker mutation;
- one supported row plus six direct historical fixtures produced all six documented compatibility statuses;
- supported, legacy-catalog, unversioned, unsupported-version, unknown-type, invalid-payload, and malformed-JSON rows were returned without rejection;
- read-side recursive redaction removed protected synthetic values while retaining safe historical metadata;
- the administrator Activity page rendered supported, legacy, unknown, malformed, and unsupported rows without a warning or protected-value disclosure; and
- raw historical envelopes and metadata remained byte-for-byte identical before and after repository reads and administrator rendering.

Cleanup deleted only the exact owned public event identities and binary marker inside a transaction. Starting and ending event/rate aggregates matched, zero owned rows remained, and no outbound email was attempted.

## Integrated producer and site-ownership evidence

Previously accepted guarded evidence completes the producer matrix without repeating destructive business fixtures:

- F03 and F13 prove real WooCommerce allocation/order-policy events and administrator activity consumption.
- F08 proves real public REST lifecycle events and stable response behavior.
- F19 proves administrator CSV import/export event counts and capability-separated export behavior.
- F25 proves claim lifecycle events, concurrency, and protected-payload exclusion.
- F27 proves repeated WordPress privacy erasure emits exact `privacy_data_anonymized` rows while retaining business integrity.
- F31 proves credential lifecycle events across real REST/HTTP, audit-failure rollback, and parallel workers.
- F29/F30 proves independent blog-prefix audit creation/read ownership and permanent-uninstall isolation across a private three-site network.
- The preserved F32 activity observation independently rendered administrator, customer, and WooCommerce actors and exact created/assigned/delivered event sequences.

## Operational integrity boundary

`docs/AUDIT-EVENTS.md`, `docs/AUDIT-EVENT-CONSUMERS.md`, and ADR 0004 consistently describe the table as append-oriented operational evidence. They make no cryptographic or tamper-proof claim against a compromised WordPress installation or database administrator.

## Verification

- guarded disposable F32 WordPress/InnoDB matrix passed;
- successful writes: 1;
- rejected writes: 4;
- compatibility statuses: 6 of 6;
- mixed historical fixtures: 6;
- catalog-validation rollback passed;
- audit-persistence rollback passed;
- read redaction and warning-free administrator rendering passed;
- historical rows remained byte-for-byte stable;
- database aggregates remained unchanged;
- owned fixture residue: 0;
- outbound email: 0;
- source-contract and catalog tests passed;
- PHPUnit: 256 tests, 1,582 assertions passed;
- WordPress coding standards passed;
- PHPStan passed 55 source files;
- Composer and release metadata validation passed;
- no sensitive output was produced.

## Result

The central catalog, guarded live write/rollback/compatibility matrix, and accepted producer/privacy/multisite evidence jointly satisfy F32. F32 is `PASS_WITH_EVIDENCE`; the Free V1 gate ledger is 33 `PASS_WITH_EVIDENCE`, 0 `MANUAL_ENVIRONMENT_REQUIRED`, 0 `AUTOMATABLE_PENDING`, and 0 `IMPLEMENTATION_BLOCKER`.
