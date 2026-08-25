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
| Degraded modes | `DEGRADED-MODES.md`, recovery notice | Contract recorded; drill matrix pending |
| Privacy erasure/retention | `Privacy`, `PRIVACY-RETENTION.md` | Foundation; cross-customer/repeated integration tests pending |
| Frozen v1 client | `tests/fixtures/reference-client/v1` | Fixture added; live server execution pending |
| HTTPS/request bounds/proxy | `TransportGuard`, `SourceAddress` | Foundation; deployment matrix pending |
| Encryption provisioning/recovery | `MasterKey`, `Crypto`, setup snippet, recovery guide | Foundation; disposable restore drill pending |
| Product public identity | product settings/duplicate hook, ADR | Foundation; variation/delete/restore/remap tests pending |
| Multisite site ownership | blog-prefixed schema, site derivation/network activation | Foundation; two-site tests pending |
| Least privilege | `Capabilities` | Implemented map; security tests pending |
| Privileged Bearer API | `CredentialService`, `CredentialToken`, `CredentialPolicy`, `PrivilegedRoutes`, ADR 0003 | Lifecycle implemented; live REST/proxy/MySQL/multisite concurrency evidence pending |
| Versioned audit | `AuditEventCatalog`, `EventRepository`, published catalog/consumer guide, fixtures | Source contract complete for 44 emitted v1 types and four legacy names; live WordPress/MySQL/privacy/multisite evidence pending |
| Release provenance | release docs/scripts/manifest schema | Deterministic two-clean-build comparison and external provenance sidecar implemented; artifact remains an unshipped candidate |
| P0 lifecycle | creation, state machine, `LifecycleService`, expiry/activation/order-slot unit sources, operations guides | Partial: manual/bulk and Woo refund/cancel/quantity workflows complete at source level; runtime evidence remains |
| P0 WooCommerce | product/variation settings, allocation, email/order display, refund/cancel policy, quantity safety, resend, preview-confirmed backfill, secure guest account claim/release/override | Partial: full runtime/concurrency/HPOS/mail evidence remains |
| P0 customer portal | masked list and audited reveal | Partial: activation list/deactivation and complete cache tests pending |
| P0 REST | public + management foundation | Partial: complete JSON schemas, HMAC mode, exact abuse/timing evidence pending |
| P0 credentials | creation/authentication/scopes/expiration/rotation/revocation | Source and local policy/security contracts complete; live integration/concurrency evidence pending |
| P0 storage/recovery | authenticated encryption/blind index/recovery notice | Partial: full backup wizard and rotation plan tests pending |
| P0 admin | filtered list, bulk actions, detail/installations/audit, reassignment, activity, guarded delete, order preview/confirm tools | Partial: setup wizard, product/order/customer links, indicators, UI/a11y and runtime authorization evidence remain |
| P0 portability | bounded CSV import/export | Partial: mapping UI, background jobs, downloadable error report incomplete |
| P0 audit | central versioned catalog, validated repository boundary, consumer fixtures, recursive redaction | Source/local contract complete; integrated rollback, privacy-tool, multisite, and historical-row acceptance pending |
| P0 setup/diagnostics | requirements, Site Health, master-key snippet | Partial smoke tests and system report |
| P1 migration/webhooks/tooling/reminders/renewal | Milestone 6 roadmap | Intentionally not implemented before stable P0 |
| P2 blocks/background tools/dashboard | Milestone 7 roadmap | Intentionally not implemented before P1 |
| Data model | `Schema`, ADRs 0001/0002/0003/0004, `docs/MIGRATIONS.md` | Sequential v1/v2/v3 additive snapshots and source replay verified; live MySQL preservation/interruption/multisite evidence pending |
| Architecture/threat/privacy/performance/i18n/a11y | dedicated docs and code conventions | Deterministic performance smoke and bounded export implemented; live full-scale and other manual verification remains incomplete |
| Unit/integration/REST/security/compatibility tests | test tree and must-pass table | Unit/reference foundations; WordPress/WooCommerce runtime unavailable here |
| Definition of done | `FREE-V1-MUST-PASS.md` | Open blockers; no unsupported pass statements |
| Documentation deliverables | `docs/` index and guides | Core guides present; specialized launch guides remain blockers |
| Milestone/output contract | roadmap and changelog | Followed; F25, F31, and F32 are complete at source/local-contract level; live acceptance and customer activation management remain |

The ledger is deliberately strict: a requirement is not complete merely because a class or screen exists. It closes only with the command/procedure and observed evidence recorded in `FREE-V1-MUST-PASS.md`.
