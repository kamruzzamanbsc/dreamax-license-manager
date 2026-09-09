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

- PHPUnit: 282 tests and 1,826 assertions passed.
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

## Official Plugin Check and local smoke

- The official Plugin Check `2.1.0` package was verified against the WordPress.org SHA-256 manifest for all 3,032 packaged files before use.
- The final artifact SHA-256 was rechecked as `92c0bf37369f21b57f05b36d98254b6bba651fc614ef0335620a8a5cb58fcc98` immediately before installation.
- Plugin Check ran in `update` mode against the installed final artifact on disposable WordPress `7.1` and WooCommerce `11.1.0`. Both inactive-state and active-state runs completed with no errors.
- Active-state bootstrap loaded `DREAMAX_LM_VERSION` as `0.3.3`; storage tables were ready, cleanup was scheduled, and the plugin REST route registered in a Sodium-enabled runtime.
- The copied local stack did not have a test master key, and Sodium remained disabled in Apache's PHP configuration. The public ping therefore remained intentionally unavailable (`503` in the Sodium-enabled probe, while Apache paused plugin registration), so this is not evidence that the remaining end-to-end runtime gates passed.
- No PHP debug-log growth was observed during the HTTP smoke requests. Test-only plugin files, verified tooling, and the temporary PHP server were removed afterward without invoking the plugin uninstaller or deleting existing test data.

## Pending gates

- Required manual WordPress, WooCommerce, customer-session, master-key, mail, cache, and compatibility checks in a fully configured disposable environment.
- Separate authorization for merge, tag, GitHub Release, SVN publication, and production deployment.
