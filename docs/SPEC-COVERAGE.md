# Master specification coverage ledger

Controlling prompt SHA-256: `9015f7168559ec9ff4030328f0ea921151a03a2968232c12a8edfa6b2132b0e7`  
Review date: 2026-08-24  
Build status: development version `0.3.0`; no production-readiness claim.

| Specification area | Evidence | Status |
| --- | --- | --- |
| Project configuration and operating rules | Main header, README, ADR, release gates | Foundation; official URLs unresolved |
| Product promise/use cases/scope | Architecture and roadmap | Contract recorded |
| Atomic activation | `ActivationService`, transaction and unique schema | Implemented foundation; real concurrency test blocks release |
| REST idempotency | `IdempotencyRepository` | Implemented foundation; bounded wait and full fixture evidence pending |
| Generator entropy | `KeyGenerator`, ADR, unit tests | Implemented foundation |
| Degraded modes | `DEGRADED-MODES.md`, Site Health recovery signals, guarded F11/F12/F26 private-clone verifiers | Missing/wrong-key, plugin-storage, audit-storage, WordPress-cron, upload-temporary-storage, and WooCommerce dependency failures plus exact recovery/non-mutation are verified |
| Privacy erasure/retention | `Privacy`, `PRIVACY-RETENTION.md` | Foundation; cross-customer/repeated integration tests pending |
| Frozen v1 client | `tests/fixtures/reference-client/v1` | Fixture added; live server execution pending |
| HTTPS/request bounds/proxy | `TransportGuard`, `SourceAddress` | Foundation; deployment matrix pending |
| Encryption provisioning/recovery | `MasterKey`, `Crypto`, setup snippet, recovery guide | Missing/wrong-key recovery, uniform public failure, database-only warning, complete database-plus-key restoration, restored license validation, exact non-mutation, and multisite key separation/site-local restore verified |
| Product public identity | product settings/duplicate hook, ADR | Foundation; variation/delete/restore/remap tests pending |
| Multisite site ownership | blog-prefixed schema, site derivation/network activation, future-site hook, network deactivation, guarded private-clone verifier | Existing and future sites, keys, storage, credentials, API/export selection, audit, jobs, restore, and uninstall isolation verified across three sites |
| Least privilege | `Capabilities` | Implemented map; security tests pending |
| Privileged Bearer API | `CredentialService`, `CredentialToken`, `CredentialPolicy`, `PrivilegedRoutes`, ADR 0003, guarded F31 verifier | Live REST/header/proxy/scope/expiry/rate/audit-failure/MySQL-concurrency plus migration, capability, and multisite isolation acceptance complete |
| Versioned audit | `AuditEventCatalog`, `EventRepository`, published catalog/consumer guide, guarded F32 verifier, integrated producer fixtures | Complete for 44 emitted v1 types and four legacy names; accepted/rejected writes, rollback, six compatibility statuses, redaction, mixed-history rendering/non-mutation, privacy, REST, WooCommerce, credentials, and multisite ownership have live evidence |
| Release provenance | release docs/scripts/manifest schema | Deterministic two-clean-build comparison and external provenance sidecar implemented; artifact remains an unshipped candidate |
| P0 lifecycle | creation, state machine, `LifecycleService`, expiry/activation/order-slot unit sources, operations guides | Guarded live refund/cancel/quantity/resend/backfill and extend/reset/reassign acceptance complete; recovery/degraded release gates remain |
| P0 WooCommerce | product/variation settings, allocation, email/order display, refund/cancel policy, quantity safety, resend, preview-confirmed backfill, secure guest account claim/release/override | Simple/variable allocation, duplicate/concurrent hooks, captured processing and guest-claim mail, complete guest ownership/replay/concurrency and F13 order-policy matrices, and repeated HPOS/Checkout Block versus classic-storage/classic-checkout parity have live evidence |
| P0 customer portal | masked list and audited reveal | Partial: activation list/deactivation and complete cache tests pending |
| P0 REST | public + management foundation | Partial: complete JSON schemas, HMAC mode, exact abuse/timing evidence pending |
| P0 credentials | creation/authentication/scopes/expiration/rotation/revocation | Source, administrator replay, real REST/HTTP, failure rollback, parallel lifecycle, migration, capability, and multisite contracts complete |
| P0 storage/recovery | authenticated encryption/blind index/recovery notice | Partial: full backup wizard and rotation plan tests pending |
| P0 admin | filtered list, bulk actions, detail/installations/audit, reassignment, activity, guarded delete, order preview/confirm tools | Partial: setup wizard, product/order/customer links, indicators, UI/a11y and runtime authorization evidence remain |
| P0 portability | bounded CSV import/export | Partial: mapping UI, background jobs, downloadable error report incomplete |
| P0 audit | central versioned catalog, validated repository boundary, consumer fixtures, recursive redaction, guarded F32 verifier | Complete live acceptance for transactional writes/rollback, integrated producers, all compatibility labels, recursive redaction, warning-free administrator rendering, byte-stable history, privacy, and multisite isolation |
| P0 setup/diagnostics | requirements, Site Health, master-key snippet | Partial smoke tests and system report |
| P1 migration/webhooks/tooling/reminders/renewal | Milestone 6 roadmap | Intentionally not implemented before stable P0 |
| P2 blocks/background tools/dashboard | Milestone 7 roadmap | Intentionally not implemented before P1 |
| Data model | `Schema`, ADRs 0001/0002/0003/0004, `docs/MIGRATIONS.md` | Sequential v1/v2/v3 additive snapshots, real-MariaDB preservation/interruption/replay, current indexes, future-version refusal, isolated second-prefix behavior, and actual three-site network acceptance verified |
| Architecture/threat/privacy/performance/i18n/a11y | dedicated docs and code conventions | Deterministic performance smoke and bounded export implemented; live full-scale and other manual verification remains incomplete |
| Unit/integration/REST/security/compatibility tests | test tree and must-pass table | Unit/reference foundations plus guarded disposable WordPress/WooCommerce/HPOS/InnoDB acceptance across recorded gates; remaining environment matrices stay open |
| Definition of done | `FREE-V1-MUST-PASS.md` | All 33 gates are `PASS_WITH_EVIDENCE`; no unsupported pass statements |
| Documentation deliverables | `docs/` index and guides | Core guides present; specialized launch guides remain blockers |
| Milestone/output contract | roadmap and changelog | Followed; F31 privileged Bearer and F32 audit compatibility acceptance are live-verified |

The ledger is deliberately strict: a requirement is not complete merely because a class or screen exists. It closes only with the command/procedure and observed evidence recorded in `FREE-V1-MUST-PASS.md`.
