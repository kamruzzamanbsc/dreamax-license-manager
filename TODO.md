# TODO

## Prioritized unfinished tasks

1. **P0 — Await WordPress.org review:** Monitor the registered project mailbox, including spam filtering, for the human-review email. Do not resubmit merely because review is pending.
2. **P0 — Process review safely:** When the email arrives, verify the sender and exact assigned slug, preserve the message, and assess every reviewer request before changing files or replying.
3. **P1 — Address review findings if required:** Create a bounded plan, update only necessary source/tests/docs with approval, rerun the proportionate full validation set, build deterministically from a recorded commit, and upload a replacement submission only when justified and authorized.
4. **P1 — Prepare WordPress.org publication after approval:** Obtain separate explicit authorization before checking out or modifying SVN, uploading plugin code/assets, or publishing a stable tag.
5. **P2 — Production readiness:** Separately validate backups, external master-key recovery, HTTPS, InnoDB, mail delivery, cache/proxy behavior, supported WordPress/WooCommerce/PHP versions, and a disposable restore before any production deployment decision.

## One clear next action

Wait for the WordPress.org human-review email; make no source, artifact, branch, tag, release, or SVN change while the submission remains awaiting review.

## Deployment and release checklist

- [x] Candidate metadata consistently identifies version `0.3.1`.
- [x] Official Plugin Check recorded no errors or warnings.
- [x] WordPress.org readme validator recorded no errors.
- [x] PHPUnit, PHPCS, PHPStan, metadata, and recorded release-gate checks passed.
- [x] Deterministic ZIP built from recorded commit; hash and inventory matched.
- [x] Submitted ZIP filename/version/slug matched the intended candidate.
- [x] WordPress.org accepted the submission and initially assigned `dreamax-license-manager`.
- [ ] Human review completed and approval received.
- [ ] Final assigned slug confirmed unchanged after human review.
- [ ] Separate authorization received for WordPress.org SVN work.
- [ ] Approved source mapped to SVN `trunk` and the intended version tag without dev files, secrets, or local artifacts.
- [ ] WordPress.org assets and readme validated in the SVN layout, if publication assets are approved.
- [ ] SVN diff reviewed before commit; no SVN commit performed without explicit approval.
- [ ] Published directory page, ZIP, stable tag, and version metadata verified after an authorized SVN publication.
- [ ] Production deployment separately authorized and target-environment backup/recovery checks completed.

Do not alter `main`, `v0.3.1`, the source branch, an existing GitHub release, or the submitted artifact merely to advance this checklist.
