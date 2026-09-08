# Dreamax License Manager

Dreamax License Manager is a self-hosted WooCommerce licensing plugin published by [Dreamax Soft](https://dreamaxsoft.com/). Version `0.3.2` is published in the WordPress.org Plugin Directory and tagged in Git; later work remains unreleased until it completes a separate review and release cycle.

## Current baseline

- WordPress 6.9 or later
- WooCommerce 10.8 or later
- PHP 8.0 or later with `ext-sodium`
- HTTPS for every credential-bearing API call
- InnoDB for transactional activation enforcement

The recorded acceptance matrix used WordPress 7.1, WooCommerce 11.0.1, PHP 8.2.4, MariaDB 10.4.28, HPOS and classic order storage, Checkout Block and classic checkout, Apache loopback HTTP, and a private disposable multisite clone where applicable.

## Development setup

1. Install WordPress and WooCommerce in a disposable development environment.
2. Copy this directory to `wp-content/plugins/dreamax-license-manager`.
3. Install development dependencies with `composer install`.
4. Add a non-production test master key to `wp-config.php`.
5. Run `composer test`, `composer analyse`, and `composer phpcs`.

Never test migrations, fixture generation, destructive actions, or recovery drills against production data. Review `docs/FREE-V1-MUST-PASS.md` before making any release claim.

## Security model

License keys use XChaCha20-Poly1305 authenticated encryption and a separate keyed HMAC-SHA-256 lookup fingerprint. The root key must be a dedicated 32-byte unpadded base64url value in `DREAMAX_LICENSE_MANAGER_MASTER_KEY`; it is never stored in the database. See `docs/RECOVERY.md`.

## Project status

The source implements the initial schema, key protection, generator/normalization rules, atomic activation core, public and scoped management REST foundations, WooCommerce allocation/delivery, a customer endpoint, CSV transfer, privacy hooks, and health diagnostics. Version `0.3.2` addresses WordPress.org review feedback by using enqueued assets, authenticating privileged routes in permission callbacks, validating request headers through the REST request object, and clarifying that the plugin itself has no license gate, trial, quota, paid service, or locked feature. All 33 Free V1 gates are `PASS_WITH_EVIDENCE`. See `docs/BUILDING.md`, `docs/RELEASE.md`, and the coverage ledger for future release-candidate procedures.
