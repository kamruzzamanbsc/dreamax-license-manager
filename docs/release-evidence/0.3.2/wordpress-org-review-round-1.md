# WordPress.org review round one

Date assessed: 2026-09-03 (Asia/Dhaka)

## Scope

This evidence records the bounded local correction prepared after the first human review of the submitted `0.3.1` plugin. It does not record a WordPress.org upload, reviewer reply, merge, tag, release, SVN publication, or deployment.

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
- Artifact: unshipped `dreamax-license-manager-0.3.2.zip`; 64 files; SHA-256 `d58f3e7ed3d3b7219037f11d5219812a91ab686ac6c1b41def4972e4336379fb`.
- Reproducibility: two isolated builds had identical ZIP hashes and inventories.
- Official Plugin Check 2.1.0 static run: exit 0, no errors found.
- Official Plugin Check 2.1.0 runtime-enabled run: exit 0, no errors found.
- Hosted WordPress.org readme validator: submitted content matched the local readme after line-ending normalization; zero errors and zero warnings. Its only optional notes were the absent Upgrade Notice and Screenshots sections and donate link.

The primary disposable check used WordPress 7.1, WooCommerce 11.0.1, PHP 8.2.4, MariaDB 10.4.28, and InnoDB. The exact artifact activated successfully, installed schema version 3, and created nine plugin tables.

## Additional guarded REST evidence

The unchanged guarded F31 verifier reached the combined header/transport stage after all allowed-scope and wrong-scope checks. It then stopped at its duplicate raw Authorization-header assertion because the PHP built-in server collapsed two raw fields before PHP/WordPress received them. This is a local web-server limitation, not a promoted PASS.

An ignored diagnostic copy marked only that one case as skipped and false, then completed the remaining matrix:

- 11 credential contracts, 22 loopback HTTP requests, and six parallel workers verified;
- all four exact scopes allowed and all wrong scopes denied;
- uniform missing, malformed, unknown, expired, and revoked authentication failures;
- query/body credential rejection and trusted-proxy boundaries;
- expiration, per-credential rate limiting, last-used coalescing, audit rollback, and concurrency serialization;
- 37 audit rows checked for private payloads;
- exact web configuration and database aggregates restored, with zero owned fixture rows and no outbound email.

The duplicate raw-header case remains to be rerun for `0.3.2` under a server such as Apache that preserves or combines duplicate Authorization fields. Prior Apache evidence exists for the frozen F31 contract, but it does not replace this version-specific rerun.

The disposable WordPress, SQLite/MariaDB data, temporary configuration, test key, diagnostic scripts, and local servers were removed after verification. No production data, service, or credential was used. The release ZIP and its external manifest/inventory remain ignored build outputs.
