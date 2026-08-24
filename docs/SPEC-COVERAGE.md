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
| Privileged Bearer API | `CredentialService`, `PrivilegedRoutes` | Foundation; rotation/revocation UI and tests pending |
| Versioned audit | schema + `EventRepository` | Foundation; catalog completeness pending |
| Release provenance | release docs/scripts/manifest schema | Planned evidence; deterministic two-build proof pending |
| P0 lifecycle | creation, state machine, `LifecycleService`, expiry/activation/order-slot unit sources, operations guides | Partial: manual/bulk and Woo refund/cancel/quantity workflows complete at source level; runtime evidence remains |
| P0 WooCommerce | product/variation settings, allocation, email/order display, refund/cancel policy, quantity safety, resend, preview-confirmed backfill | Partial: guest claim and full runtime/concurrency/HPOS evidence remain |
| P0 customer portal | masked list and audited reveal | Partial: activation list/deactivation and complete cache tests pending |
| P0 REST | public + management foundation | Partial: complete JSON schemas, HMAC mode, exact abuse/timing evidence pending |
| P0 credentials | creation/authentication/scopes | Partial: rotation/revocation/last-used coalescing incomplete |
| P0 storage/recovery | authenticated encryption/blind index/recovery notice | Partial: full backup wizard and rotation plan tests pending |
| P0 admin | filtered list, bulk actions, detail/installations/audit, reassignment, activity, guarded delete, order preview/confirm tools | Partial: setup wizard, product/order/customer links, indicators, UI/a11y and runtime authorization evidence remain |
| P0 portability | bounded CSV import/export | Partial: mapping UI, background jobs, downloadable error report incomplete |
| P0 audit | core creation/activation/delivery/reveal/export/privacy events | Partial event catalog |
| P0 setup/diagnostics | requirements, Site Health, master-key snippet | Partial smoke tests and system report |
| P1 migration/webhooks/tooling/reminders/renewal | Milestone 6 roadmap | Intentionally not implemented before stable P0 |
| P2 blocks/background tools/dashboard | Milestone 7 roadmap | Intentionally not implemented before P1 |
| Data model | `Schema`, ADR | Foundation; migration evolution evidence pending |
| Architecture/threat/privacy/performance/i18n/a11y | dedicated docs and code conventions | Contract recorded; verification incomplete |
| Unit/integration/REST/security/compatibility tests | test tree and must-pass table | Unit/reference foundations; WordPress/WooCommerce runtime unavailable here |
| Definition of done | `FREE-V1-MUST-PASS.md` | Open blockers; no unsupported pass statements |
| Documentation deliverables | `docs/` index and guides | Core guides present; specialized launch guides remain blockers |
| Milestone/output contract | roadmap and changelog | Followed; WooCommerce refund/cancel/quantity/resend/backfill milestone complete at source level; next bounded milestone is secure guest claim plus customer activation management |

The ledger is deliberately strict: a requirement is not complete merely because a class or screen exists. It closes only with the command/procedure and observed evidence recorded in `FREE-V1-MUST-PASS.md`.
