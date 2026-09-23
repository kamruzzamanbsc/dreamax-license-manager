# Contributing

Thank you for helping improve Dreamax License Manager. Contributions should be
small enough to review, preserve published behavior by default, and include
evidence proportional to their security and compatibility risk.

## Before opening an issue

- Search existing issues and documentation for the same behavior.
- Use a private reporting path for suspected vulnerabilities; do not open a
  public security issue. See `SECURITY.md`.
- Remove license keys, credentials, master keys, customer data, private URLs,
  database dumps, and other sensitive values from examples and logs.
- Confirm that the problem is reproducible with a currently supported WordPress,
  WooCommerce, and PHP combination when possible.

## Development setup

The baseline requirements are WordPress 6.9+, WooCommerce 10.8+, PHP 8.0+ with
Sodium, HTTPS for credential-bearing requests, and InnoDB.

1. Use a disposable local WordPress/WooCommerce environment.
2. Clone the repository into `wp-content/plugins/dreamax-license-manager`.
3. Install development dependencies with `composer install`.
4. Configure a non-production `DREAMAX_LICENSE_MANAGER_MASTER_KEY` according to
   `docs/RECOVERY.md`.
5. Activate the plugin and follow `docs/SETUP-DIAGNOSTICS.md`.

Never run migrations, fixture generation, recovery drills, concurrency tests,
mail tests, or guarded live verification against production data or services.

## Coding and architecture expectations

- Follow the WordPress Coding Standards and the repository PHPCS rules.
- Keep WordPress and WooCommerce hook adapters thin; put licensing rules in the
  relevant namespaced service.
- Use documented repository/service boundaries instead of querying another
  module's tables directly.
- Preserve the public REST v1 contract, stable error meanings, audit-event
  meanings, schema history, privacy behavior, and frozen reference client.
- Treat authentication, authorization, encryption, migration, uninstall,
  customer isolation, and key recovery as protected boundaries.
- Add or update tests and documentation with behavioral changes.
- Do not weaken a test or frozen fixture merely to make a breaking change pass.

## Local checks

Run from the repository root:

```text
composer test
composer analyse
composer phpcs
composer release:validate
```

`composer migration:verify`, `composer benchmark:smoke`, and scripts named
`verify-live-*` require their documented disposable environment and cleanup
boundaries. Review `docs/BUILDING.md`, `docs/RELEASE.md`, and
`docs/FREE-V1-MUST-PASS.md` before making a release claim.

## Pull requests

Work on one bounded milestone at a time. A pull request should describe:

- the problem and intended outcome;
- relevant issue or design context;
- compatibility and public-contract impact;
- security, privacy, migration, and data-retention impact;
- tests and documentation added or changed;
- exact commands run and their results;
- work that remains unverified.

Avoid unrelated formatting or refactoring. Generated build artifacts, local
configuration, credentials, and production data must not be committed.

Maintainers may ask for a smaller change, additional failure-path tests, or a
compatibility plan before review. Approval of source changes is separate from
authorization to tag, publish, deploy, or modify WordPress.org content.

## Documentation-only changes

Documentation contributions are welcome. Keep statements tied to implemented
behavior, use current version information, distinguish recorded evidence from a
fresh test run, and avoid promises about security, support response times, or
future releases that maintainers have not adopted.

## Conduct

Participation is governed by `CODE_OF_CONDUCT.md`.
