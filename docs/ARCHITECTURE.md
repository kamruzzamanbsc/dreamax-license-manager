# Architecture

Review date: 2026-08-24

## Context

This is a new plugin with no inherited repository architecture. WordPress and WooCommerce are integration boundaries; licensing rules remain in namespaced services. All tables and options are site-local.

## Modules

- `Database`: schema, installation, transactions, migrations.
- `Encryption`: master-key contract, purpose-separated derivation, authenticated encryption, blind indexes.
- `Licenses`, `Generators`, `Activations`: lifecycle, key production, normalization, atomic capacity. `LifecycleService` owns confirmed administrator transitions, retry-safe extension/reset, reassignment, and guarded deletion.
- `Events`: append-only, schema-versioned audit evidence.
- `Api`, `Credentials`: public v1 routes, transport guards, idempotency, rate limits, scoped Bearer authentication.
- `Integrations/WooCommerce`: snapshotted product policies, deterministic order-slot allocation, refund/cancellation transitions, guarded quantity edits, resend, and preview-confirmed historical backfill.
- `CustomerPortal`, `Admin`, `ImportExport`, `Privacy`: human workflows and WordPress integration. `GuestClaimService` owns emailed single-use guest-order proofs, atomic account ownership, invalidation, and privacy integration.

Hook adapters must remain thin. Domain services must not render HTML. No module may query another module's table except through a documented repository/service boundary; the current development foundation still contains a few direct read-only administration queries tracked in `SPEC-COVERAGE.md` for refactoring.

## State model

Persisted license lifecycle:

```text
available -> assigned -> suspended -> assigned
     |          |           |          |
     +----------+-----------+--------> revoked (terminal in v1)
```

`expired`, `activated`, `activation_available`, and `activation_limit_reached` are computed. Expiry never rewrites lifecycle. Activation rows are only `active` or `inactive`; reactivation reuses the row. Audit events preserve transitions.

## Atomic activation

1. Resolve the key using a keyed fingerprint without logging raw material.
2. Start an InnoDB transaction.
3. Lock the owning license row with `SELECT ... FOR UPDATE`.
4. Resolve the unique `(license_id, instance_fingerprint)` row.
5. Return an already-active row as natural-key replay success.
6. Count active rows and enforce `NULL` unlimited, `0` disabled, positive maximum while the license lock is held.
7. Insert/reactivate and append the required event in the same transaction.
8. Commit or roll back all changes. Retry only bounded deadlock/serialization failures.

## Identity

- `lic_` + 128 random bits identifies a license publicly.
- `prd_` + 128 random bits identifies a sellable product/variation.
- `act_` + 128 random bits identifies an activation.
- A client supplies a 16–128 byte opaque `instance_id`; only its keyed fingerprint persists.
- A mutable `instance_label` is descriptive personal data, never the durable identity.

Product edits/restores retain the ID. Ordinary duplication generates a new ID. Historical licenses retain their original ID after product deletion.

## WooCommerce order integrity

Allocation identities use the order-item ID plus quantity slot and are protected by a database unique constraint. Product/variation order-policy values are snapshotted in license metadata. Partial refunds map to stable quantity slots; each per-license policy operation is transactionally claimed. Delivered or generated keys cannot re-enter a shared pool. WooCommerce order reads and writes use its CRUD objects for HPOS compatibility, while the plugin's own tables remain behind repositories/services.

Guest account claiming adds one unique active-proof slot and one unique claimed-owner row per order. Verification locks the proof, claimed owner, and all associated license rows before WooCommerce CRUD and plugin ownership updates occur in the same database transaction. See ADR 0002.

## Time, cache, and transport

Server UTC is authoritative. Sensitive responses are private/no-store. Production credential-bearing requests require HTTPS; forwarded protocol/address headers are honored only when the direct source is an explicitly configured trusted proxy. HTTP is permitted only when the local exception is explicitly enabled and the resolved source is loopback.

## Multisite

Tables use the current blog prefix; options, credentials, rate buckets, scheduled work, salts, and derived keys are site-local. Normal jobs do not enumerate sites. Network activation initializes each current site independently. Network-global management is not part of Free V1.
