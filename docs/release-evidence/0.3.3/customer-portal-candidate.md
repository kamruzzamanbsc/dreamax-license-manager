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

## Preliminary deterministic build

The initial recorded commit produced two identical builds with 68 production files and SHA-256 `0df2bfc3dd9812b2e7e1b5ac6150d0f4482f5040030639e4366ce14cbddfb39e`. Manifest provenance, ZIP root, inventory, prohibited-path exclusions, and private-value scans passed.

That artifact is superseded because inspection found stale version-specific wording in the included `docs/BUILDING.md`. A final deterministic build must be generated from the later documentation-correction commit and receive the same inspection before any release decision.

## Pending gates

- Official Plugin Check for the exact final artifact.
- Final deterministic build and artifact inspection from the corrected recorded commit.
- Required manual WordPress, WooCommerce, customer-session, mail, cache, and compatibility checks.
- Separate authorization for merge, tag, GitHub Release, SVN publication, and production deployment.
