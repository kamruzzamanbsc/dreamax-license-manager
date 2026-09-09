# Changelog

## 0.3.3 - Customer Portal

- Rebuilt the WooCommerce My Account licenses screen with endpoint-scoped styling, a stable desktop account grid, responsive license cards, and a clearer two-step guest-order claim flow.
- Added a login-protected `[dreamax_license_dashboard]` standalone customer portal with overview metrics, responsive license cards, secure reveal/copy, guest-order claiming, account details, and accessible tab navigation independent of WooCommerce My Account layouts.
- Added a nonce- and capability-protected Customer Portal setup screen that publishes the standalone dashboard page without relying on the WordPress block editor and prevents duplicate page creation.
- Made guest-claim form responses independent of the WooCommerce frontend session during `admin-post.php` requests, and contained transport failures inside the generic fail-closed customer response instead of allowing a WordPress critical-error page.
- Preserved consumed guest-claim records during rejected replay attempts instead of reclassifying the terminal audit state when order ownership had changed after the successful claim.
- Replaced the fresh-install recovery warning with an actionable encryption setup notice linking administrators to the one-time master-key generator.

## 0.3.2 — WordPress.org review corrections

- Replaced direct and inline asset output with WordPress-enqueued static assets.
- Moved scoped Bearer authentication for privileged REST routes into named permission callbacks while preserving the frozen v1 response envelope and locked execution recheck.
- Removed direct REST Authorization and Idempotency header reads from server globals in favor of strict request-object validation.
- Added explicit WooCommerce product-edit capability checks and clarified that the plugin itself has no paywall, trial, quota, license gate, or external-service dependency.

## 0.3.1 — Administration and pre-submission hardening

- Redesigned license inventory, detail, activity, import/export, status, API credential, and WooCommerce order-operation administration for clearer state, controlled actions, and responsive alignment.
- Added preview-bound order recovery, action-availability guidance, safer guest ownership confirmation, and auditable credential lifecycle controls.
- Hardened privileged REST parsing for absent bodies and authorization headers.
- Replaced trusted dynamic SQL identifier interpolation with explicit prepared identifiers, clarified uninstall-only schema cleanup, and passed the official Plugin Check with no errors or warnings.

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
- Added guarded three-site private-clone multisite verification for existing/future-site initialization, per-site encryption and restore, data/credential/API/export/audit/job isolation, network deactivation, and current-site-only uninstall.
- Initialize sites created after network activation, clear each site's cleanup schedule during network deactivation, and accept WordPress's exact persisted enabled value for confirmed permanent uninstall.
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
- Added unit and source-contract coverage plus an ADR, customer/merchant instructions, and guarded live F25 WordPress/WooCommerce/InnoDB/mail/parallel-worker acceptance with exact cleanup.
- Added generic storage and cleanup Site Health recovery signals plus guarded private-clone F26 fault injection for plugin storage, required audit storage, WordPress cron, upload temporary storage, and WooCommerce dependency loss, with fail-closed public behavior, transaction rollback, full recovery, source preservation, and exact cleanup.
- Added product and variation refund/cancellation settings with per-license policy snapshots.
- Added deterministic per-quantity and per-item refund mapping plus retry-safe cancellation handling.
- Added safe post-delivery quantity increase/decrease/delete behavior without silent key deletion or reuse.
- Added authorized order-action resend and a bounded preview/confirm tool for missing-slot historical backfill and resend.
- Added pure order-slot policy unit test sources and WooCommerce order-policy operating documentation.
- Corrected cancellation-policy audit metadata to encode its not-applicable refund field within the published non-negative contract.
- Added guarded WordPress/WooCommerce/HPOS/InnoDB acceptance for the complete F13 refund, cancellation, quantity, pool, lifecycle, backfill, resend, replay, audit, and cleanup matrix.
- Completed the guarded F32 audit compatibility matrix for accepted/rejected writes, validation and persistence rollback, all six historical compatibility statuses, recursive redaction, warning-free administrator rendering, byte-stable history, and exact cleanup; all 33 Free V1 gates now have evidence.
- Reconciled the unshipped 0.3.0 release-candidate headers, tested-version claims, build manifest, and provenance guidance with the accepted WordPress 7.1 and WooCommerce 11.0.1 runtime matrix.

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
