# Audit-event consumer and migration guide

Applies to the development 0.3.0 event contract. The complete field inventory is in `AUDIT-EVENTS.md`.

## Consumer dispatch

Treat `(event_type, schema_version)` as the payload discriminator. Do not dispatch on `event_type` alone and do not infer a schema from the plugin version. For a supported pair:

1. validate or read the required fields documented for that version;
2. ignore documented optional fields the consumer does not understand;
3. retain the opaque event/reference identifiers as strings or integers without deriving business meaning from their representation;
4. treat `occurred_at` as UTC;
5. do not treat metadata as a source of credentials, license keys, or personal profile data.

An additive optional field may appear in a later producer while retaining schema v1 only when it does not change any existing field or event meaning. Consumers must therefore ignore optional fields they do not recognize. Producers must first add the field and safe type to the catalog; arbitrary undeclared metadata is rejected.

## Unsupported and legacy rows

Repository reads preserve stored rows and add `contract_status`:

- `supported`: the known type/version and stored envelope satisfy the current contract;
- `legacy_catalog_entry`: an earlier published name is recognized but never received a supported production payload contract;
- `legacy_unversioned`: the stored version is missing, zero, or otherwise non-positive;
- `unsupported_version`: the event type is known but the positive version is unknown to this consumer;
- `unknown_type`: the event type is not in this catalog;
- `legacy_payload`: the type/version is known but the historical envelope or payload does not satisfy the frozen contract.

Unknown, unsupported, and legacy rows remain displayable as opaque history. Consumers must not coerce them to v1, guess field meanings, or destructively rewrite them. Read-side redaction still applies.

## Incompatible changes

An incompatible change includes removing or renaming a field, changing a field type or meaning, changing actor/reference semantics, making an optional field required, or changing the event’s business meaning. Such a change requires:

1. a new positive schema version under the same event type only when the broad event meaning remains recognizable, otherwise a new event type;
2. an additive catalog definition that retains every earlier supported version;
3. producer changes that explicitly emit the new version;
4. fixtures for both old and new versions;
5. migration/rollout notes describing mixed-version operation and consumer fallback;
6. a data migration only when a new storage representation is required—valid historical event rows are not rewritten merely to adopt a new payload version.

## Current migration position

The database already stores a positive `schema_version`, opaque event ID, UTC occurrence time, actors, references, and JSON metadata, so F32 requires no destructive database migration and does not increase the plugin schema version. Existing v1 rows remain in place. The repository now validates new writes and labels read compatibility. Four previously documented but non-emitted names remain non-persistable legacy entries.

This contract supplies operational audit compatibility, not cryptographic tamper-proofing against a fully compromised WordPress installation or database administrator.
