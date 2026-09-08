# TODO

## Prioritized unfinished tasks

1. **P0 - Validate the Customer Portal candidate:** Complete automated checks and a focused security, compatibility, packaging, and UI review for version `0.3.3`.
2. **P1 - Review the Customer Portal separately:** Publish the candidate branch and open a dedicated pull request to `main`; do not include it retroactively in `0.3.2`.
3. **P1 - Build the exact release candidate:** After review, create and inspect a deterministic artifact from an authorized recorded commit.
4. **P2 - Future WordPress.org publication:** Update SVN only after separate tag and publication authorization.
5. **P2 - Production readiness:** Separately validate backups, external master-key recovery, HTTPS, InnoDB, mail delivery, cache/proxy behavior, and a disposable restore before any production deployment decision.

## One clear next action

Run the complete local automated checks and focused review for Customer Portal candidate `0.3.3`.

## Review correction checklist

- [x] WordPress.org review findings recorded and assessed.
- [x] Privileged REST routes use named authentication/scope permission callbacks.
- [x] Request headers use the REST request object; remaining transport input is sanitized.
- [x] Administration/account assets use WordPress enqueue/localization APIs.
- [x] Product saves include explicit capability checks.
- [x] Public documentation clearly rules out trialware/paywall behavior.
- [x] Candidate metadata consistently identifies version `0.3.2`.
- [x] Local PHPUnit, PHPCS, PHPStan, syntax, Composer, autoload, audit, and whitespace checks passed.
- [x] Deterministic `0.3.2` ZIP built twice and inspected from recorded commit `5ecc0b28650f05d1b98eb5e017e894948fc717d5`.
- [x] Official Plugin Check 2.1.0 static and runtime-enabled checks completed with no errors.
- [x] Hosted WordPress.org readme validation completed with zero errors and zero warnings.
- [x] Disposable WordPress 7.1/WooCommerce 11.0.1/MariaDB activation and schema checks completed with exact cleanup.
- [x] Unchanged guarded F31 verifier completed under isolated Apache 2.4.56, including duplicate raw Authorization-header rejection and exact cleanup.
- [x] Replacement-upload and reviewer-reply authorization received from the project owner.
- [x] WordPress.org accepted the correction and directory approval was received.
- [x] Version `0.3.2`, directory assets, listing copy, and six accurate screenshots were published through SVN.
- [x] Git tag `v0.3.2` was published at the reviewed correction commit.
- [x] The correction branch was reviewed and merged into Git `main` through pull request #43.

## Customer Portal candidate checklist

- [x] Remote `main` was integrated into `feature/customer-license-portal`.
- [x] Candidate metadata consistently identifies version `0.3.3`.
- [x] PHPUnit, PHPCS, PHPStan, release metadata, and production syntax checks pass.
- [x] Focused source security and UI review has no blocking findings.
- [ ] Official Plugin Check, deterministic packaging, artifact inspection, and required manual runtime gates pass.
- [ ] Candidate branch is committed and published after explicit authorization.
- [ ] A dedicated Customer Portal pull request is reviewed and merged after explicit authorization.
- [ ] Deterministic artifact, manual runtime checks, tag, and WordPress.org publication receive separate authorization.

Do not move `v0.3.2`, alter the published `tags/0.3.2` source, or include the Customer Portal in that historical release.
