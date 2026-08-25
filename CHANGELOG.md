# Changelog

## 0.3.0 — WooCommerce order-policy milestone

- Store site KDF salts as strictly validated versioned base64url options, with atomic first initialization, authoritative read-back, protected-data guards, and key-preserving legacy raw-salt migration.
- Added sequential schema v1/v2/v3 migration contracts and a disposable source verifier.
- Added official readme validation evidence, deterministic clean builds, external provenance manifests, and dependency/license inventory.
- Added seeded smoke/full performance fixtures and bounded keyset-batched CSV export.
- Added the authoritative F32 catalog for all 44 production audit types and four non-persistable legacy published names, with explicit schema versions at every emitter.
- Added actor/reference/metadata validation, recursive key/value redaction, compatibility-labeled historical reads, consumer fixtures/guidance, ADR 0004, and local F32 evidence.
- Completed the privileged Bearer credential lifecycle with exact parsing, uniform authentication failures, per-credential limits, expiration, coalesced usage timestamps, zero-overlap versioned rotation, and irreversible idempotent revocation.
- Added nonce/capability-protected credential inventory and mutation actions, recursive credential redaction, schema v3 migration fields, an atomic-lifecycle ADR, operating documentation, and F31 local evidence.
- Added an authenticated, emailed one-time-code workflow for claiming paid guest orders without accepting email-only or URL-based proof.
- Added transactional single-use claim/ownership records, rate limits, ownership invalidation, HPOS-safe order updates, privacy handling, and versioned redacted audit events.
- Added unit and source-contract coverage plus an ADR, customer/merchant instructions, and F25 evidence; live WordPress/WooCommerce/database/mail/concurrency acceptance evidence remains required.
- Added product and variation refund/cancellation settings with per-license policy snapshots.
- Added deterministic per-quantity and per-item refund mapping plus retry-safe cancellation handling.
- Added safe post-delivery quantity increase/decrease/delete behavior without silent key deletion or reuse.
- Added authorized order-action resend and a bounded preview/confirm tool for missing-slot historical backfill and resend.
- Added pure order-slot policy unit test sources and WooCommerce order-policy operating documentation.
- WordPress/WooCommerce runtime, mail, concurrency, HPOS, and authorization acceptance evidence remains blocked in this environment.

## 0.2.0 — lifecycle administration milestone

- Added a transactional lifecycle service for suspend, restore, terminal revoke, expiry extension, activation reset, reassignment, and guarded pool-record deletion.
- Added per-license operation replay markers so retried extensions, resets, reassignments, and transitions do not apply twice.
- Added filtered license administration, explicit-confirmation bulk actions, license detail/installations/audit views, and recent activity.
- Added complete state-transition matrix and expiry-policy unit test sources plus lifecycle operating documentation.
- Runtime WordPress/WooCommerce, PHPUnit, static-analysis, concurrency, and authorization evidence remains blocked in this environment.

## 0.1.0 — development foundation

- Added site-local transactional schema, authenticated key encryption, blind indexing, normalization and generator policies.
- Added atomic activation core, public v1 API, idempotency/rate-limit foundations, and scoped Bearer management API.
- Added WooCommerce product/order foundations, customer reveal, administration, CSV transfer, privacy integration, diagnostics, ADRs, and release gates.
- This is not Free V1 and carries open blockers in `docs/FREE-V1-MUST-PASS.md`.
