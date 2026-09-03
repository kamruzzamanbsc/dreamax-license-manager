# TODO

## Prioritized unfinished tasks

1. **P0 - Submit the correction through the authorized review workflow:** Use the authenticated WordPress.org submission page to upload the exact verified `0.3.2` ZIP, then reply to the existing reviewer thread with a concise finding-to-fix summary and validation results.
2. **P1 - Await and process the reviewer response:** Preserve the response, confirm the assigned slug, and assess any further request before changing source or publication state.
3. **P1 - Prepare WordPress.org publication only after directory approval:** Do not modify SVN, upload code/assets, or publish a stable tag until approval is received and the exact publication action is authorized.
4. **P2 - Production readiness:** Separately validate backups, external master-key recovery, HTTPS, InnoDB, mail delivery, cache/proxy behavior, and a disposable restore before any deployment decision.

## One clear next action

Upload the exact verified `0.3.2` ZIP through the authenticated WordPress.org review workflow and reply to the existing reviewer thread. Do not merge, tag, publish SVN, create a GitHub release, or deploy merely to complete the review response.

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
- [ ] WordPress.org accepted the correction and directory approval was received.

Do not alter `main`, `v0.3.1`, the submitted `0.3.1` artifact, or any hosted release merely to advance this checklist.
