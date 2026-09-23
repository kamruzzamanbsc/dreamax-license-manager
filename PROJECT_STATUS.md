# Project status

## Current published baseline

Dreamax License Manager `0.4.1` is published on WordPress.org. The repository
contains the actively maintained development source for the plugin.

The `0.4.1` release verification records:

- 354 PHPUnit tests and 2,247 assertions passing.
- PHPCS, PHPStan, release-metadata, and ZIP-structure checks passing.
- Two isolated deterministic builds with identical inventories and ZIP hashes.
- Exact-file parity between the reviewed candidate and the generated
  WordPress.org package.
- A guarded live verification of the commercial renewal command boundary with
  exact fixture cleanup.

See `docs/release-evidence/0.4.1/release-verification.md`. These are recorded
release results, not claims that every check has been re-run against later
changes.

## Current objective

Improve public open-source readiness and prepare honest, evidence-backed
support-program application material while preserving published behavior,
security boundaries, and release history.

The tracked plan is `docs/OPEN-SOURCE-READINESS.md`.

## Current strengths

- Published self-hosted WordPress/WooCommerce licensing infrastructure.
- Namespaced modules for licensing, activation, encryption, credentials,
  customer access, WooCommerce workflows, privacy, and import/export.
- Documented threat model, recovery process, public API, migrations, audit-event
  contract, deterministic packaging, and guarded acceptance procedures.
- No runtime Composer package dependencies.

## Current readiness gaps

- The new GitHub Actions quality workflow has not run remotely. The available
  local PHP test, analysis, coding-standard, syntax, and release-metadata checks
  pass, but Composer validation and dependency audit remain pending because no
  Composer executable is available on the current shell PATH.
- Public GitHub version/status messaging and WordPress.org publication state
  need a final consistency review.
- Current public adoption and external-contribution evidence is limited and must
  not be overstated.
- Git tag and GitHub Release parity with published WordPress.org versions needs
  provenance-safe remediation after the completed discrepancy audit.

## Change boundaries

Repository preparation does not authorize a push, pull request, tag, GitHub
Release, repository-settings change, WordPress.org update, production action,
or program application. Each external action requires separate approval.

## Last updated

2026-09-23 (Asia/Dhaka)
