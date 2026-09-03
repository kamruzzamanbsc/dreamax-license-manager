# TODO

## Prioritized unfinished tasks

1. **P0 - Owner review and authorization:** Inspect the `0.3.2` correction diff, official validation evidence, artifact hash, and remaining duplicate-header limitation. Obtain separate explicit authorization before a replacement WordPress.org upload or reviewer reply.
2. **P1 - Repeat the raw duplicate-header case under Apache:** Use an unmistakably disposable WordPress/WooCommerce/MySQL environment whose web server preserves or combines duplicate Authorization fields. Do not treat the PHP built-in-server skip as a PASS.
3. **P1 - Prepare WordPress.org publication only after approval:** Obtain separate explicit authorization before checking out or modifying SVN, uploading code/assets, or publishing a stable tag.
4. **P2 - Production readiness:** Separately validate backups, external master-key recovery, HTTPS, InnoDB, mail delivery, cache/proxy behavior, and a disposable restore before any deployment decision.

## One clear next action

The project owner should review the pushed correction evidence and decide whether to authorize the Apache duplicate-header rerun, replacement WordPress.org upload, and reviewer email reply as separate actions.

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
- [x] Disposable WordPress 7.1/WooCommerce 11.0.1/MariaDB activation, schema, and guarded REST diagnostic checks completed with exact cleanup.
- [ ] Duplicate raw Authorization-header behavior rerun for `0.3.2` under Apache or an equivalent server.
- [ ] Separate replacement-upload and reviewer-reply authorization received.
- [ ] WordPress.org accepted the correction and directory approval was received.

Do not alter `main`, `v0.3.1`, the submitted `0.3.1` artifact, or any hosted release merely to advance this checklist.
