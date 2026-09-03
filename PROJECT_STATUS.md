# Project status

## Current objective

Prepare the bounded `0.3.2` correction candidate requested by the first WordPress.org human review. Preserve the submitted `0.3.1` source, `main`, and `v0.3.1`; do not upload, reply, merge, tag, publish, or release without separate authorization.

## Review-round-one corrections

- Replaced permissive callbacks on all privileged REST routes with named callbacks that authenticate the Bearer credential and enforce the route's exact scope before dispatch.
- Preserved intentionally public license lifecycle routes; they authenticate using their public v1 license-key protocol rather than a WordPress user session.
- Replaced direct authorization/idempotency superglobal reads with `WP_REST_Request` header access and sanitized the remaining transport metadata.
- Replaced direct administration/account script and stylesheet output with WordPress enqueue/localization APIs.
- Added explicit product-save capability checks while retaining WooCommerce's upstream nonce verification.
- Clarified in public copy that the plugin itself has no payment, subscription, trial, quota, external service, or paid-feature unlock.
- Advanced candidate metadata and documentation from `0.3.1` to `0.3.2` and added focused source-contract coverage.

## Local validation

The correction work passed the following local checks before its final review:

- PHPUnit: 274 tests and 1,766 assertions.
- WordPress PHPCS: no errors or warnings.
- PHPStan: 55 files, no errors.
- Composer package validation and release metadata validation.
- Production PHP syntax: 57 files.
- Production autoload/path verification: 55 unique production symbols.
- Composer locked-dependency audit: no advisories or abandoned packages.
- Git whitespace validation.

The official hosted Plugin Check and WordPress.org readme validator have not been rerun for `0.3.2`. WordPress CLI is unavailable in this workspace, and no disposable live WordPress/WooCommerce environment was authorized for runtime verification.

## Git and release state

- Correction branch: `fix/wordpress-org-review-round-1`.
- The deterministic ZIP must be built only from the final recorded correction commit and kept under ignored `build/` output.
- The prior `0.3.1` submission, release commit, `main`, and `v0.3.1` remain unchanged.
- No WordPress.org upload, email reply, merge, tag, GitHub release, SVN action, or production deployment is authorized by this correction work.

## Blockers and next decision

- Official Plugin Check/readme validation and any live WordPress/WooCommerce verification remain external/manual checks.
- The project owner must separately authorize any replacement WordPress.org upload and reviewer email reply after inspecting the `0.3.2` artifact and evidence.
- WordPress.org SVN publication remains blocked until directory approval and separate explicit authorization.

## Last updated

2026-09-03 (Asia/Dhaka)
