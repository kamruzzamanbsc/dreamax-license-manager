# WordPress.org review round one

Date assessed: 2026-09-03 (Asia/Dhaka)

## Scope

This evidence records the bounded local correction prepared after the first human review of the submitted `0.3.1` plugin. The publication outcome is recorded below; Git merge and any later feature release remain separate operations.

## Finding disposition

| Reviewer concern | Disposition in `0.3.2` |
| --- | --- |
| Possible trialware or locked functionality | Public copy now states explicitly that the plugin itself has no license requirement, payment, subscription, trial, quota, remote service dependency, or paid-feature unlock. License checks apply only to keys a store owner issues for their own products. |
| Direct script/style output | Administration and account interactions now use WordPress enqueue/localization APIs. |
| Nonce and capability enforcement | Existing nonce boundaries were retained; explicit product-save capability checks were added alongside WooCommerce's upstream nonce verification. |
| Unsanitized authorization/idempotency headers | REST transport validation now reads those headers from `WP_REST_Request`; remaining server/query transport metadata is sanitized before inspection. |
| Permissive privileged REST permissions | Every privileged route now has a named permission callback that enforces transport policy, Bearer authentication, and the exact route scope before handler dispatch. |
| Permissive public lifecycle REST permissions | Retained intentionally. These endpoints are public at the WordPress-user layer and apply the documented public v1 license-key authentication and transport controls inside their handlers. |

## Local evidence

- PHPUnit: 274 tests, 1,766 assertions, passed.
- WordPress PHPCS: passed with no errors or warnings.
- PHPStan: 55 files, no errors.
- Composer package and release metadata validation: passed.
- Production syntax: 57 PHP files, passed.
- Production autoload/path verification: 55 unique symbols, passed.
- Locked dependency audit: no advisories or abandoned packages.
- Git whitespace validation: passed.

## Artifact and official validation

- Source commit: `5ecc0b28650f05d1b98eb5e017e894948fc717d5`.
- Pre-publication verification artifact: `dreamax-license-manager-0.3.2.zip`; 64 files; SHA-256 `d58f3e7ed3d3b7219037f11d5219812a91ab686ac6c1b41def4972e4336379fb`.
- Reproducibility: two isolated builds had identical ZIP hashes and inventories.
- Official Plugin Check 2.1.0 static run: exit 0, no errors found.
- Official Plugin Check 2.1.0 runtime-enabled run: exit 0, no errors found.
- Hosted WordPress.org readme validator: submitted content matched the local readme after line-ending normalization; zero errors and zero warnings. Its only optional notes were the absent Upgrade Notice and Screenshots sections and donate link.

The primary disposable check used WordPress 7.1, WooCommerce 11.0.1, PHP 8.2.4, MariaDB 10.4.28, and InnoDB. The exact artifact activated successfully, installed schema version 3, and created nine plugin tables.

## Additional guarded REST evidence

The unchanged guarded F31 verifier completed against an isolated Apache 2.4.56, WordPress 7.1, WooCommerce 11.0.1, PHP 8.2.4, MariaDB 10.4.28, and InnoDB site:

- 11 credential contracts, 23 loopback HTTP requests, and six parallel workers verified;
- all four exact scopes allowed and all wrong scopes denied;
- uniform missing, malformed, unknown, expired, and revoked authentication failures;
- duplicate raw Authorization fields rejected without disclosing credential material;
- query/body credential rejection and trusted-proxy boundaries;
- expiration, per-credential rate limiting, last-used coalescing, audit rollback, and concurrency serialization;
- 37 audit rows checked for private payloads;
- exact web configuration and database aggregates restored, with zero owned fixture rows and no outbound email.

The verifier reported `duplicate_raw_header_rejected`, `authorization_forwarding_tested`, `web_configuration_restored`, `cleanup_complete`, `database_aggregates_unchanged`, and `sensitive_output: false`. Both isolated server ports were closed after the run.

The disposable WordPress/MariaDB data, Apache configuration, test key, and local servers were removed after verification. No production data, service, or credential was used. The release ZIP and its external manifest/inventory remain ignored build outputs.

## Publication outcome

- WordPress.org approved the plugin slug `dreamax-license-manager`.
- The `0.3.2` plugin source was published to SVN `trunk` and `tags/0.3.2` in revision `3687082`.
- Directory icons, banners, and screenshots were added in revision `3687261`.
- The listing readme was expanded in revision `3687322` and corrected in revision `3687346` so it describes only the deployed `0.3.2` feature set.
- The public directory reports version `0.3.2`, WordPress 6.9 or later, tested through WordPress 7.1, and PHP 8.0 or later.
- Git tag `v0.3.2` points to commit `bf43977f63dfddbf6c418317884dd96cd1f0919b`.
