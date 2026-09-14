# ADR 0004: Versioned audit-event contract

Status: accepted; live integration evidence complete

Date: 2026-08-25

## Context

ADR 0001 requires every audit event to have an opaque identifier, stable type, positive payload schema version, UTC time, actor classification, references, and allowlisted metadata. Before F32, `EventRepository` hardcoded version 1, accepted any event name/actor/metadata shape, recent reads omitted the version, and documentation listed both emitted and non-emitted names without an executable compatibility boundary. F25 and F31 added compatibility-sensitive claim and credential events that must remain stable.

## Decision

`AuditEventCatalog` is the authoritative code contract. At adoption it defined 44 production-emitted schema-v1 event types and four explicitly non-persistable legacy published names; later releases may add new, independently named event contracts without changing those frozen meanings. Each supported version defines allowed actors, license/actor/request reference policies, required and optional metadata fields, safe types, emitter paths, and cross-field variant rules. Every production emitter passes a catalog event expression and `SCHEMA_V1` explicitly. `EventRepository` recursively redacts, validates, encodes, and only then persists the event with an opaque `evt_` ID and server UTC occurrence time.

Metadata contracts are closed at write time. A producer may add a documented optional field under the same version only when existing meaning is unchanged; arbitrary undeclared fields are rejected. Incompatible field, actor, reference, or semantic changes require a new positive payload version and retained support/fixtures for earlier versions.

The existing event-table schema already owns every required envelope field, so there is no database migration and schema version 3 remains current. Historical rows are never destructively rewritten. Read paths include `schema_version` and `request_id`, redact metadata again, and label each row `supported`, `legacy_catalog_entry`, `legacy_unversioned`, `unsupported_version`, `unknown_type`, or `legacy_payload`.

The earlier documentation-only names `activation_failed`, `license_expired`, `license_imported`, and `license_renewed` have no trustworthy emitted payload definition. The catalog recognizes them for historical display but rejects new writes. Imports use `license_created` with source metadata; expiry is computed; renewal remains outside the implemented surface.

## Security and privacy

Allowed actors are `administrator`, `api_credential`, `customer`, `privacy_tool`, `public_api`, `system`, and `woocommerce`. Integer references must be positive when present; request/operation references are bounded and reject controls. Recursive redaction covers nested arrays and objects, secret-bearing names, and high-confidence secret values carried under innocent names. Strict metadata allowlists minimize personal data and prevent arbitrary support/request content from entering audit storage.

Event writes remain transactional where their business services already own a transaction. Tables continue to use the current WordPress blog prefix; no network-global catalog state or cross-site query is introduced. Permanent uninstall behavior is unchanged and already includes the event table; normal deactivation retains it.

## Compatibility consequences

Consumers dispatch on `(event_type, schema_version)`, consume required fields, ignore unknown documented optional fields, and decline to interpret unsupported pairs. The catalog deliberately preserves the two existing schema-v1 variants for `license_revoked` and `guest_claim_administrator_override` instead of redefining their history.

This is append-oriented operational evidence, not a cryptographic ledger. It cannot and does not claim tamper-proofing against a fully compromised WordPress installation or database administrator.

Guarded live WordPress/InnoDB evidence now covers accepted and rejected writes, rollback on catalog validation and audit persistence failure, every compatibility status, recursive read redaction, warning-free administrator rendering, byte-for-byte historical-row preservation, and exact cleanup. Accepted WooCommerce, REST, CSV, claim, privacy, credential, and private multisite evidence completes the integrated producer and site-ownership matrix. F32 is therefore `PASS_WITH_EVIDENCE`.
