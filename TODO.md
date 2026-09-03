# TODO

## Prioritized unfinished tasks

1. **P0 — Finish the authorized local correction checkpoint:** Commit the validated `0.3.2` review fixes, build the deterministic ZIP from that exact commit, inspect its manifest/inventory, and push only `fix/wordpress-org-review-round-1`.
2. **P0 — Run official submission checks:** Validate the resulting ZIP with the current official Plugin Check and hosted WordPress.org readme validator before any replacement submission.
3. **P0 — Owner review and authorization:** Let the project owner inspect the diff, evidence, and artifact. Obtain separate explicit authorization before uploading a replacement ZIP or replying to the reviewer.
4. **P1 — Verify in a disposable live environment:** Exercise the corrected REST authorization paths, account asset loading, product-save capabilities, proxy/HTTPS behavior, and supported WordPress/WooCommerce versions without using production data.
5. **P1 — Prepare WordPress.org publication only after approval:** Obtain separate explicit authorization before checking out or modifying SVN, uploading code/assets, or publishing a stable tag.
6. **P2 — Production readiness:** Separately validate backups, external master-key recovery, HTTPS, InnoDB, mail delivery, cache/proxy behavior, and a disposable restore before any deployment decision.

## One clear next action

Complete the authorized local commit, deterministic artifact verification, and correction-branch push. Stop before any WordPress.org upload, email reply, merge, tag, release, SVN action, or deployment.

## Review correction checklist

- [x] WordPress.org review findings recorded and assessed.
- [x] Privileged REST routes use named authentication/scope permission callbacks.
- [x] Request headers use the REST request object; remaining transport input is sanitized.
- [x] Administration/account assets use WordPress enqueue/localization APIs.
- [x] Product saves include explicit capability checks.
- [x] Public documentation clearly rules out trialware/paywall behavior.
- [x] Candidate metadata consistently identifies version `0.3.2`.
- [x] Local PHPUnit, PHPCS, PHPStan, syntax, Composer, autoload, audit, and whitespace checks passed.
- [ ] Deterministic `0.3.2` ZIP built and inspected from the recorded correction commit.
- [ ] Current official Plugin Check and hosted readme validation completed for that ZIP.
- [ ] Disposable live WordPress/WooCommerce verification completed.
- [ ] Separate replacement-upload and reviewer-reply authorization received.
- [ ] WordPress.org accepted the correction and directory approval was received.

Do not alter `main`, `v0.3.1`, the submitted `0.3.1` artifact, or any hosted release merely to advance this checklist.
