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

The exact `0.3.2` ZIP also passed official Plugin Check 2.1.0 in static and runtime-enabled modes with no errors. The hosted WordPress.org readme validator returned zero errors and zero warnings, with optional notes only for absent Upgrade Notice, Screenshots, and donate-link content.

A fresh disposable WordPress 7.1, WooCommerce 11.0.1, PHP 8.2.4, MariaDB 10.4.28, and InnoDB site activated the artifact, installed schema version 3, and created nine plugin tables. A guarded diagnostic run verified 22 loopback requests, 11 credential contracts, six parallel workers, authorization/scope/proxy/rate/audit/concurrency behavior, private audit payloads, and exact cleanup. The PHP built-in server collapsed duplicate raw Authorization headers before WordPress received them, so that one version-specific raw-header case remains unverified rather than being promoted to PASS.

## Git and release state

- Correction branch: `fix/wordpress-org-review-round-1`.
- The deterministic ZIP was built twice from correction commit `5ecc0b28650f05d1b98eb5e017e894948fc717d5`; both hashes and inventories matched. The ignored artifact contains 64 files and has SHA-256 `d58f3e7ed3d3b7219037f11d5219812a91ab686ac6c1b41def4972e4336379fb`.
- The prior `0.3.1` submission, release commit, `main`, and `v0.3.1` remain unchanged.
- No WordPress.org upload, email reply, merge, tag, GitHub release, SVN action, or production deployment is authorized by this correction work.

## Blockers and next decision

- The duplicate raw Authorization-header case should be rerun under Apache or another server that preserves or combines duplicate fields; the PHP built-in server cannot provide this evidence.
- The project owner must separately authorize any replacement WordPress.org upload and reviewer email reply after inspecting the `0.3.2` artifact and evidence.
- WordPress.org SVN publication remains blocked until directory approval and separate explicit authorization.

## Last updated

2026-09-03 (Asia/Dhaka)
