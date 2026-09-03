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

## Deferred external evidence

- The current official Plugin Check and hosted WordPress.org readme validator were not available in this local workspace for the `0.3.2` artifact.
- Live REST/WooCommerce checks require an explicitly marked disposable environment and were not run.
- A deterministic build manifest and inventory are generated outside Git from the final recorded commit.
