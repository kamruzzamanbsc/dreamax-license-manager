# TODO

## Prioritized unfinished tasks

1. **P0 - Reconcile the published release into Git:** Review and merge `fix/wordpress-org-review-round-1` into `main` without moving the published `v0.3.2` tag.
2. **P1 - Prepare the Customer Portal release:** Integrate the release branch into `feature/customer-license-portal`, assign a new version, update release documentation, and rerun the required checks.
3. **P1 - Review the Customer Portal separately:** Open and review a dedicated pull request; do not include it retroactively in `0.3.2`.
4. **P2 - Future WordPress.org publication:** Build and inspect a new deterministic artifact, then update SVN only after separate release authorization.
5. **P2 - Production readiness:** Separately validate backups, external master-key recovery, HTTPS, InnoDB, mail delivery, cache/proxy behavior, and a disposable restore before any production deployment decision.

## One clear next action

Complete review of `fix/wordpress-org-review-round-1` and merge it into `main` after explicit authorization. Keep the Customer Portal isolated until its own versioned release review.

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
- [ ] The correction branch has been reviewed and merged into Git `main`.

Do not move `v0.3.2`, alter the published `tags/0.3.2` source, or include the Customer Portal in that historical release.
