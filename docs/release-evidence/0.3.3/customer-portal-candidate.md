# Customer Portal 0.3.3 candidate

Date assessed: 2026-09-09 (Asia/Dhaka)

## Scope

This evidence records preparation of the standalone Customer Portal candidate after the published `0.3.2` baseline was merged into Git `main`. It does not authorize or record a final tag, GitHub Release, WordPress.org SVN update, or production deployment.

## Candidate source

- Branch: `feature/customer-license-portal`.
- Initial candidate commit: `9dd49622c7fdd37067880c31f6aa9a9aad7486e7`.
- Pull request: #44, targeting `main`.
- Version/header/constant/readme Stable Tag: `0.3.3`.

## Automated validation

- PHPUnit: 282 tests and 1,828 assertions passed after the guest-claim replay-state correction.
- PHPStan: 57 files, no errors.
- WordPress PHPCS: passed with no errors or warnings.
- Production syntax: 103 PHP files passed.
- Release metadata validation: passed at version `0.3.3`.
- Composer package validation: strict validation passed.
- Locked dependency audit: no security vulnerability advisories found.
- Focused release/source contract suite: 23 tests and 235 assertions passed.

## Focused review

- Customer license queries remain scoped to the signed-in WordPress user.
- Key reveal remains nonce-protected, ownership-checked, audited, escaped, and privately cached.
- Guest-claim issue and verification retain action-specific nonces, interactive transport checks, generic failure behavior, and safe same-site redirects.
- Customer Portal page creation requires the license-management capability and an action-specific nonce.
- Desktop, 500px, and 360px UI QA renders were inspected without overlap, clipping, or inaccessible persistent controls.

## Deterministic build

- Final artifact source commit: `0518d336ae97f149db31cdd1e2a85e3743e27a6b`.
- Artifact: `dreamax-license-manager-0.3.3.zip` with 68 production files.
- Distribution SHA-256: `92c0bf37369f21b57f05b36d98254b6bba651fc614ef0335620a8a5cb58fcc98`.
- Inventory SHA-256: `847c5a315711f7ac675fbfffadac5cdbac21e9eee5b8fc43985c978cee861587`.
- Two isolated builds produced identical ZIP hashes and inventories.
- Manifest provenance, every file size/hash, the single plugin root, version metadata, prohibited-path exclusions, and private-value scans passed.
- Composer strict package validation passed and the locked dependency audit reported no security vulnerability advisories.

The earlier build from commit `9dd4962` with SHA-256 `0df2bfc3dd9812b2e7e1b5ac6150d0f4482f5040030639e4366ce14cbddfb39e` is superseded because inspection found stale version-specific wording in the included `docs/BUILDING.md`.

The artifact from commit `0518d33` is also superseded by the subsequently verified guest-claim replay-state correction. A new deterministic artifact must be created from the authorized commit containing that correction before release.

## Official Plugin Check and local smoke

- The official Plugin Check `2.1.0` package was verified against the WordPress.org SHA-256 manifest for all 3,032 packaged files before use.
- The final artifact SHA-256 was rechecked as `92c0bf37369f21b57f05b36d98254b6bba651fc614ef0335620a8a5cb58fcc98` immediately before installation.
- Plugin Check ran in `update` mode against the installed final artifact on disposable WordPress `7.1` and WooCommerce `11.1.0`. Both inactive-state and active-state runs completed with no errors.
- Active-state bootstrap loaded `DREAMAX_LM_VERSION` as `0.3.3`; storage tables were ready, cleanup was scheduled, and the plugin REST route registered in a Sodium-enabled runtime.
- The copied local stack did not have a test master key, and Sodium remained disabled in Apache's PHP configuration. The public ping therefore remained intentionally unavailable (`503` in the Sodium-enabled probe, while Apache paused plugin registration), so this is not evidence that the remaining end-to-end runtime gates passed.
- No PHP debug-log growth was observed during the HTTP smoke requests. Test-only plugin files, verified tooling, and the temporary PHP server were removed afterward without invoking the plugin uninstaller or deleting existing test data.

## Configured runtime regression

- A private disposable WordPress `7.1`, WooCommerce `11.1.0`, PHP `8.2.12`, Sodium, and MariaDB runtime completed equivalent paid-order outcomes across HPOS/Checkout Block and classic storage/classic checkout modes.
- Customer isolation passed for the account list, reveal authorization, registered-order access, and attacker rollback boundaries.
- The standalone portal passed signed-out gating, masked owner rendering, cross-customer isolation, owner reveal, attacker reveal denial, private cache headers, and scoped CSS/JavaScript loading over loopback HTTP.
- Intercepted guest-claim mail contained one code and no URL or license key. The owner claim succeeded, the attacker and replay attempts failed, the proof hash was cleared, and no external mail was sent.
- The replay check exposed and verified a correction that keeps an already consumed claim terminal instead of reclassifying it after the successful ownership change.
- The local source database, configuration, active-plugin set, empty plugin-table baseline, and private-clone boundaries were restored after the run.

## Pending gates

- Create and inspect a new deterministic artifact from the authorized correction commit, then rerun the official Plugin Check against that exact package.
- Separate authorization for merge, tag, GitHub Release, SVN publication, and production deployment.
