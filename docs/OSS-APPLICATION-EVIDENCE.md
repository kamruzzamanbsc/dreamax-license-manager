# Open-source program application evidence

Snapshot date: 2026-09-23

This packet prepares truthful source material for an open-source support-program
application. It is not an application, an eligibility determination, or a claim
of acceptance.

## Applicant details to confirm

- Applicant name: `[confirm before submission]`
- GitHub username: `[confirm before submission]`
- Maintainer role and repository permissions: `[confirm before submission]`
- Other qualifying projects or contribution history: `[verify before submission]`
- Preferred private contact: `[provide directly in the application; do not commit]`

## Project identity

- Project: Dreamax License Manager
- Repository: https://github.com/kamruzzamanbsc/dreamax-license-manager
- WordPress.org: https://wordpress.org/plugins/dreamax-license-manager/
- License: GPL-2.0-or-later
- Current published version: `0.4.1`
- Positioning: open-source, self-hosted licensing infrastructure for WordPress
  and WooCommerce developers

## Evidence map

| Claim | Committed evidence |
| --- | --- |
| Architecture and module boundaries | `docs/ARCHITECTURE.md`; `docs/adr/` |
| Security model and residual risks | `docs/THREAT-MODEL.md`; `SECURITY.md` |
| Encryption and recovery operations | `docs/KEYS-AND-NORMALIZATION.md`; `docs/RECOVERY.md` |
| Public and privileged API contracts | `docs/API.md`; `docs/openapi-v1.yaml`; `docs/API-CREDENTIALS.md` |
| Extension surface | `docs/HOOKS-AND-FILTERS.md`; `docs/COMMERCIAL-EXTENSIONS.md` |
| Customer and guest access controls | `docs/GUEST-ORDER-CLAIMS.md`; `docs/CACHE-CDN.md` |
| Privacy and portability | `docs/PRIVACY-RETENTION.md`; `docs/IMPORT-EXPORT.md` |
| Release gates | `docs/FREE-V1-MUST-PASS.md`; `docs/BUILDING.md`; `docs/RELEASE.md` |
| Current release verification | `docs/release-evidence/0.4.1/release-verification.md` |
| Contribution and conduct | `CONTRIBUTING.md`; `CODE_OF_CONDUCT.md` |

## Current measured signals

The public pages inspected on 2026-09-23 showed fewer than 10 active
WordPress.org installations, no WordPress.org reviews, and no visible GitHub
stars or forks. No dependency count, qualifying download total, external merged
pull-request total, external-contributor total, or OpenSSF criticality score has
been established for this application packet.

Re-check every metric on the submission date. Do not describe the project as
widely adopted or claim a quantitative eligibility route without direct
evidence. The applicant's qualifying activity across other repositories must be
assessed separately.

## Maintainer workload suitable for advanced tooling

- Review security-sensitive authorization, recovery, credential, and customer
  isolation changes.
- Identify missing success, failure, replay, concurrency, and compatibility
  tests.
- Triage issues and reduce them to bounded, reviewable maintenance tasks.
- Keep REST, OpenAPI, PHP examples, operating docs, and release notes aligned.
- Review WordPress and WooCommerce compatibility changes.
- Check release candidates against deterministic packaging and acceptance
  evidence without replacing human authorization.

## Draft application narrative

Dreamax License Manager is an open-source, self-hosted licensing infrastructure
plugin for WordPress and WooCommerce developers. It helps stores that sell
software issue, deliver, validate, activate, recover, and audit licenses without
requiring an external licensing SaaS. Store owners retain their licensing data
and cryptographic control, while developers receive a versioned REST API and a
bounded extension contract.

The project is security-sensitive maintenance work rather than a simple product
key screen. It includes authenticated encryption, an external master-key
boundary, transactional activation enforcement, scoped expiring management
credentials, idempotency, rate limiting, customer isolation, privacy workflows,
recovery modes, deterministic packaging, and versioned audit events. The
repository also maintains guarded disposable-environment verification for
migrations, concurrency, WooCommerce order modes, multisite isolation, recovery,
and exact release-package comparison.

The project is early in public adoption, and the application does not claim
broad usage. Its intended ecosystem contribution is a transparent, auditable,
self-hosted licensing foundation that WordPress developers can inspect and
integrate instead of rebuilding the same sensitive infrastructure or depending
entirely on a hosted vendor.

Advanced maintenance tooling would be used for pull-request review, issue
triage, compatibility analysis, security-boundary review, failure-path test
design, API documentation alignment, and release-checklist verification. Human
maintainers would continue to review changes, control releases, and run
sensitive validation only in bounded disposable environments.

## Pre-submission verification

- [ ] Confirm the applicant identity, core-maintainer role, and repository
  permissions.
- [x] Confirm a reliable private security/conduct reporting address:
  `support@dreamaxsoft.com`, with `info@dreamaxsoft.com` as the fallback.
- [ ] Check whether the applicant qualifies through work on another project or
  external merged pull requests.
- [ ] Re-check the official program page and terms.
- [ ] Re-measure public project and contributor signals.
- [ ] Replace no placeholders with sensitive contact data in the repository;
  supply contact details only in the private form.
- [ ] Confirm that every linked claim still matches the current public branch.
- [x] Record the successful initial CI run:
  https://github.com/kamruzzamanbsc/dreamax-license-manager/actions/runs/35858086787
- [ ] Obtain separate authorization before submitting the external application.
