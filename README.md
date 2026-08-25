# Dreamax License Manager

Dreamax License Manager is a self-hosted WooCommerce licensing plugin under staged development. The current tree is a development foundation, not a production release.

## Current baseline

- WordPress 6.9 or later
- WooCommerce 10.8 or later
- PHP 8.0 or later with `ext-sodium`
- HTTPS for every credential-bearing API call
- InnoDB for transactional activation enforcement

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

The source implements the initial schema, key protection, generator/normalization rules, atomic activation core, public and scoped management REST foundations, WooCommerce allocation/delivery, a customer endpoint, CSV transfer, privacy hooks, and health diagnostics. Version `0.3.0` adds snapshotted order policies, secure guest-order account claims, the complete privileged API credential lifecycle, a central versioned audit-event contract, sequential migration verification, deterministic packaging/provenance, and seeded performance fixtures. Live WordPress/WooCommerce/MySQL and other manual-environment evidence remains open. See `docs/BUILDING.md`, `docs/RELEASE.md`, and the coverage ledger; do not treat this development version as Free V1.
