# Dreamax License Manager

Dreamax License Manager is a self-hosted WooCommerce licensing plugin published by [Dreamax Soft](https://dreamaxsoft.com/). Version `0.3.6` applies a fail-safe recovery boundary to every customer, order, email, resend, and export surface that reads encrypted license keys.

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

## Customer license portal

Open **License Manager -> Customer Portal** and choose **Create dashboard page**. The plugin publishes a login-protected, responsive license dashboard without requiring the WordPress block editor. Repeat submissions reuse the configured page instead of creating a duplicate. The WooCommerce My Account Licenses link then opens this standalone dashboard. The same dashboard can be placed manually with `[dreamax_license_dashboard]` when needed.

Customers can review the installations attached to each owned license and, when the merchant setting allows it, activate a replacement installation or deactivate an old one without administrator intervention.

## Data portability

The Import / Export screen provides a downloadable CSV template, source-column mapping, UTF-8 and legacy Western encoding choices, delimiter selection, dry-run validation, and explicit duplicate handling. Larger committed imports run in resumable 250-row background batches. License exports can be filtered and masked by default; full keys require the dedicated export capability. Activation inventory exports never contain instance fingerprints or license keys. See `docs/IMPORT-EXPORT.md`.

## Security model

License keys use XChaCha20-Poly1305 authenticated encryption and a separate keyed HMAC-SHA-256 lookup fingerprint. The root key must be a dedicated 32-byte unpadded base64url value in `DREAMAX_LICENSE_MANAGER_MASTER_KEY`; it is never stored in the database. See `docs/RECOVERY.md`.

## Project status

The source prepares the `0.3.6` Recovery Boundary Hardening patch. It prevents missing, changed, or invalid master-key material from breaking customer and order views, records reveal/export/resend state only after key access succeeds, and leaves encrypted license records unchanged. See `docs/BUILDING.md`, `docs/RELEASE.md`, and the coverage ledger.
