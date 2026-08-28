# Changelog

## 0.3.0 — WooCommerce order-policy milestone

- Added guarded fresh single-site WordPress prefix bootstrap and Dreamax activation verification for exact InnoDB schema, role defaults, scheduled cleanup, replay convergence, and exact temporary-table cleanup.
- Added guarded real-MariaDB v1/v2/v3 migration, replay, interruption recovery, data/index preservation, second-prefix isolation, and future-version refusal verification with exact cleanup.
- Added guarded missing/wrong master-key recovery verification on a private disposable clone, and fail public licensing routes closed before key-dependent work when encryption is not ready.
- Added guarded complete database-plus-key disaster-recovery verification with an independent restore, database-only recovery proof, pre-backup license validation, protected-state preservation, and exact temporary database/clone cleanup.
- Explicitly enforce the private no-store cache-header contract on authenticated My Account license and reveal responses.
- Added a guarded live WordPress capability-isolation verifier and action-boundary regression coverage.
- Added a guarded live simple paid-order verifier with loopback-only processing-email capture and sensitive-value-free cleanup checks.
- Added guarded duplicate Processing/Completed allocation-hook replay verification with exact no-duplicate row, event, and order-note assertions.
- Added guarded product and installation stable-identity lifecycle verification with rollback and ownership-scoped fixture cleanup.
- Added guarded unchanged frozen-v1-client live execution with a loopback-only REST path adapter and exact fixture/rate-state restoration.
- Added guarded live idempotency replay, conflict, secret-free storage, scheduled expiry-cleanup, future-row preservation, and exact fixture-restoration verification.
- Added a guarded 17-outcome public lifecycle REST matrix with exact owned-fixture and rate-state restoration.
- Normalized absent optional idempotency headers and emitted the documented keyed `product_mismatch` result without persisting or querying raw presented keys.
- Added guarded customer-list, reveal-IDOR, and registered-order isolation verification with transaction-scoped attacker cleanup.
- Added guarded public-API enumeration, proxy-spoof, rejection-timing, and failure-rate-limit verification with exact option and rate-state restoration.
- Added guarded authenticated direct and loopback-intermediary cache-header verification for My Account document and reveal responses with exact session/audit restoration.
- Added guarded paid variable-product verification for two explicit variation identities and policies, with exact per-quantity/per-item allocation and ownership-scoped cleanup.
- Added guarded live WordPress privacy export/erasure verification across the 100-row boundary, repeated erasure, cross-customer isolation, audit, and exact owned cleanup.
- Added guarded private-clone compatibility verification with repeated equivalent paid-order outcomes across HPOS/Checkout Block and classic-storage/classic-checkout modes, physical datastore assertions, source preservation, and exact cleanup.
- Added guarded parallel-worker pool-allocation and activation concurrency verification with proven in-flight contenders, exact outcome/event contracts, private input transport, and ownership-scoped cleanup.
- Added guarded multipart CSV safety verification for upload and row boundaries, bounded memory, preview no-write, duplicate and normalization behavior, capability-separated exports, formula escaping, audit, and exact cleanup.
- Added guarded live 10,000-license/50,000-event performance verification with bounded pagination, duplicate-slot rejection, streamed masked export, fixed memory ceilings, and exact owned-fixture cleanup.
- Store site KDF salts as strictly validated versioned base64url options, with atomic first initialization, authoritative read-back, protected-data guards, and key-preserving legacy raw-salt migration.
- Prevent refreshed or concurrent credential create/rotation administration POSTs from repeating mutations, without persisting or replaying plaintext results.
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
- Corrected cancellation-policy audit metadata to encode its not-applicable refund field within the published non-negative contract.
- Added guarded WordPress/WooCommerce/HPOS/InnoDB acceptance for the complete F13 refund, cancellation, quantity, pool, lifecycle, backfill, resend, replay, audit, and cleanup matrix.

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
