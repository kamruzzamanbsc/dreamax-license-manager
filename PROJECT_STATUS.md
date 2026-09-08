# Project status

## Current objective

Reconcile the completed `0.3.2` WordPress.org release into Git `main`, then prepare the standalone Customer Portal as a separately reviewed future release. Preserve the published `v0.3.2` tag and WordPress.org `tags/0.3.2` source.

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

The exact `0.3.2` ZIP also passed official Plugin Check 2.1.0 in static and runtime-enabled modes with no errors. The hosted WordPress.org readme validator returned zero errors and zero warnings, with optional notes only for absent Upgrade Notice, Screenshots, and donate-link content.

A fresh disposable WordPress 7.1, WooCommerce 11.0.1, PHP 8.2.4, MariaDB 10.4.28, and InnoDB site activated the artifact, installed schema version 3, and created nine plugin tables. The unchanged guarded F31 verifier then completed under isolated Apache 2.4.56: 23 loopback requests, 11 credential contracts, six parallel workers, duplicate raw-header rejection, authorization/scope/proxy/rate/audit/concurrency behavior, private audit payloads, and exact cleanup all passed.

## Git and release state

- Correction branch: `fix/wordpress-org-review-round-1`.
- The deterministic ZIP was built twice from correction commit `5ecc0b28650f05d1b98eb5e017e894948fc717d5`; both hashes and inventories matched. The ignored artifact contains 64 files and has SHA-256 `d58f3e7ed3d3b7219037f11d5219812a91ab686ac6c1b41def4972e4336379fb`.
- WordPress.org approved the plugin and the exact `0.3.2` plugin source was published to `trunk` and `tags/0.3.2` in SVN revision `3687082`.
- Directory icons, banners, and six screenshots were published in revision `3687261`; the public listing copy was expanded in revision `3687322` and aligned with the deployed feature set in revision `3687346`.
- Git tag `v0.3.2` resolves to correction/evidence commit `bf43977f63dfddbf6c418317884dd96cd1f0919b`.
- The standalone Customer Portal is isolated on `feature/customer-license-portal` and is not included in WordPress.org version `0.3.2`.
- Git `main` does not yet contain the `0.3.2` correction commits.

## Next decisions

- Review and merge `fix/wordpress-org-review-round-1` into `main` without moving `v0.3.2`.
- Prepare the Customer Portal under a new version only after the release branch is integrated and the documented validation gates are rerun.

## Last updated

2026-09-09 (Asia/Dhaka)
