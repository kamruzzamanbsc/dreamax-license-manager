# Open-source readiness

Status date: 2026-09-24

## Objective

Prepare accurate, evidence-backed material for open-source support applications
without inflating adoption, security, or production-readiness claims.

This file tracks repository preparation only. It does not claim eligibility,
selection, endorsement, or acceptance by any external program.

## Program-checking rule

Open-source support programs use different and changing qualification signals.
Common signals include project usage, dependent projects or packages, download
volume, maintainer responsibilities, external contributions, community
participation, criticality, and evidence of active maintenance.

Re-check the official criteria and terms of the intended program immediately
before submitting an application. Keep program-specific notes and private
application data outside this public repository.

## Evidence-backed project baseline

- Dreamax License Manager `0.4.1` is published on WordPress.org.
- The repository is public and the local `main` branch was clean before this
  readiness work began.
- The `0.4.1` release record reports 354 PHPUnit tests and 2,247 assertions,
  with PHPCS, PHPStan, release metadata, deterministic-build, and exact-package
  verification passing.
- Architecture, threat-model, API, recovery, migration, privacy, audit-event,
  and release-evidence documentation is committed.
- The 2026-09-24 public snapshot showed fewer than 10 active WordPress.org
  installations, no WordPress.org reviews, 6 GitHub stars, 4 forks, and one
  listed GitHub contributor: the repository-owner account.
- No qualifying dependency, download, external-contribution, or criticality
  score is currently recorded in this repository.

The release evidence is historical evidence, not a substitute for re-running
the applicable checks against future changes.

## Quick application-readiness plan

### P0: public truth and reviewer clarity

- [x] Record the readiness plan and the dated baseline.
- [x] Replace stale project-status and TODO material with the current `0.4.1`
  baseline.
- [x] Make the README explain the problem, infrastructure positioning,
  architecture, developer entry points, security boundaries, limitations, and
  maintenance evidence.
- [x] Align the GitHub repository description with the published project:
  `Open-source self-hosted licensing infrastructure for WordPress and
  WooCommerce developers.`
- [x] Verify that maintained local version/status statements agree across the
  README, project status, plugin header, WordPress.org readme, and live GitHub
  repository description.

### P1: visible maintenance quality

- [x] Expand the contribution guide with setup, standards, tests, PR scope,
  security boundaries, and review expectations.
- [x] Add a code of conduct with expected behavior, scope, and enforcement.
- [x] Confirm a reliable private maintainer contact path for the code of conduct
  and security policy: `support@dreamaxsoft.com`, with
  `info@dreamaxsoft.com` as the fallback.
- [x] Expand the security policy with supported versions, scope, reporting,
  response expectations, and disclosure handling without promising an
  unstaffed SLA.
- [x] Add pull-request and issue templates that request reproduction,
  compatibility, security, and validation evidence without soliciting secrets.
- [x] Add a GitHub Actions quality workflow for Composer validation, syntax,
  PHPUnit, PHPStan, PHPCS, and release-metadata validation.
- [x] Run the available local PHP quality suite and record the exact result.
  The workflow itself is not described as green until it runs remotely.
- [x] Run Composer metadata validation and the locked dependency audit in the
  first remote CI workflow.

### P2: release and application evidence

- [x] Audit the difference between WordPress.org releases and Git tags/GitHub
  Releases. The 2026-09-23 snapshot is recorded below. Creating tags or releases
  remains an external action requiring separate approval and provenance review.
- [x] Add a concise quality-evidence map that links claims to committed records.
- [x] Prepare an application evidence packet with maintainer role, recent
  maintenance, release history, architecture, security practices, real usage,
  and current ecosystem metrics.
- [x] Draft a short program-neutral narrative explaining the WordPress
  licensing problem, why self-hosting matters, the maintainer workload, the
  proposed maintenance workflows, and the expected ecosystem benefit.
- [x] Verify the repository-owner account's visible public contribution history.
  The 2026-09-24 GitHub snapshot found no merged pull requests authored into
  repositories outside that account during the prior 12 months. The account's
  other visible original repository does not establish a quantitative route.
- [x] Re-check the intended program's official criteria and public project
  metrics on 2026-09-24. None of the published quantitative routes is currently
  established by the available evidence. The program explicitly permits an
  application from maintainers who do not fit a listed route when they can
  explain quiet ecosystem value. Re-check again immediately before submission.

### Ongoing: real ecosystem evidence

- [x] Draft bounded, contribution-ready issues backed by real project needs in
  `docs/CONTRIBUTOR-ISSUE-DRAFTS.md`. Publication remains an external action.
- [x] Add a small WordPress reference integration demonstrating the supported
  public API in `examples/wordpress-plugin-client.php`, with integration and
  update-boundary guidance in `docs/REFERENCE-INTEGRATION.md`.
- [ ] Collect permissioned feedback from real WordPress/WooCommerce developers.
- [ ] Respond consistently to genuine support, issues, reviews, and pull
  requests.
- [ ] Record authentic integrations, dependents, downloads, contributors, and
  maintenance outcomes as they appear.

These are ongoing adoption activities, not numbers to manufacture. Do not buy,
exchange, automate, or otherwise fabricate stars, downloads, reviews,
contributors, dependents, or testimonials.

## Application claims policy

An application may accurately describe the project as published, actively
maintained, self-hosted, security-sensitive WordPress/WooCommerce licensing
infrastructure. Until supported by new evidence, it must not describe the
project as widely adopted, production-proven across many sites, free of
vulnerabilities, or qualified under a quantitative program route.

## Current-change validation

Validated locally on 2026-09-23 without changing production data:

- PHP 8.0.30 syntax: 231 PHP files passed.
- PHP 8.2.12 syntax: 231 PHP files passed.
- PHPUnit 10.5.64 on PHP 8.2.12: 354 tests and 2,247 assertions passed.
- PHPStan: no errors.
- PHPCS: passed with no output.
- Release metadata: passed and remained internally consistent at `0.4.1`.
- Git whitespace validation: passed.
- Repository scan for the excluded vendor names: no matches outside ignored
  dependency, build, and Git metadata directories.

GitHub Actions run
[`35858086787`](https://github.com/kamruzzamanbsc/dreamax-license-manager/actions/runs/35858086787)
completed successfully for commit `b26fa6c0fb48aa4a3f7dbcc0db1d21b82728eab2`.
Its tests/static-checks job and both PHP 8.0 and PHP 8.2 syntax jobs passed. The
remote job also completed strict Composer metadata validation and the locked
dependency audit.

Follow-up run
[`35858471311`](https://github.com/kamruzzamanbsc/dreamax-license-manager/actions/runs/35858471311)
also completed successfully for commit
`741389c4a3c5d33b0ac23d8bd908670d21f65eef`, including both syntax jobs and the
complete tests/static-checks job.

Final readiness run
[`35858751125`](https://github.com/kamruzzamanbsc/dreamax-license-manager/actions/runs/35858751125)
completed successfully for commit
`e0b8b0811effa67cdf2dce81520bdf9ebba82d36`, including both syntax jobs and the
complete tests/static-checks job.

## Application-day eligibility snapshot

The intended program page inspected on 2026-09-24 listed five quantitative or
role-based routes: at least 500 dependent repositories, at least 100 dependent
packages, at least 200,000 combined monthly registry downloads, a listed core
role in a recognized foundation or language project, at least 100 pull requests
merged into repositories the applicant does not own during the prior 12 months,
at least 20 unique external contributors with merged pull requests during that
period, or an OpenSSF criticality score of at least 0.4.

No route above is established for this applicant or project by the public
evidence currently recorded. The truthful application route is the program's
explicit invitation to explain a project that the ecosystem may quietly depend
on even when a listed threshold is not met. Approval remains uncertain, and the
application must emphasize real maintenance work and security-sensitive public
value rather than imply present scale.

## Release-history audit snapshot

WordPress.org publishes version `0.4.1`. The local tag list inspected on
2026-09-23 contains `v0.3.0`, `v0.3.1`, `v0.3.2`, `v0.3.5`, and `v0.3.6`; it
does not contain tags for `0.3.3`, `0.3.4`, `0.3.7`, `0.3.8`, `0.4.0`, or
`0.4.1`. The public GitHub page inspected on the same date showed no GitHub
Releases.

This is a consistency gap, not authorization to manufacture history. Before
backfilling anything, map each published WordPress.org package to its reviewed
source commit and release evidence. Never move an existing tag. Tag creation,
GitHub Release publication, and remote changes require separate approval.

## External actions requiring separate approval

- Creating, moving, or publishing Git tags or GitHub Releases.
- Publishing issue drafts, opening or merging pull requests, pushing commits,
  or contacting an external program operator.
- Changing WordPress.org SVN content or directory metadata.
- Submitting an external application.
