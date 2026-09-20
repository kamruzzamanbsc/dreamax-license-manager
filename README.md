# Dreamax License Manager

Dreamax License Manager is a self-hosted WooCommerce licensing plugin developed and maintained by [Dreamax Soft](https://dreamaxsoft.com).

The plugin is publicly available on WordPress.org:

https://wordpress.org/plugins/dreamax-license-manager/

Version `0.4.1` is currently published on WordPress.org. The project provides license generation, activation management, customer license access, import/export tools, diagnostics, administrative controls, and a versioned developer boundary for separately distributed extensions.

This repository contains the actively maintained development source for the plugin.

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

Never test migrations, fixture generation, destructive actions, or recovery drills against production data.

Review `docs/FREE-V1-MUST-PASS.md` before making any release claim.

## Customer license portal

Open **License Manager -> Customer Portal** and choose **Create dashboard page**.

The plugin publishes a login-protected, responsive license dashboard without requiring the WordPress block editor. Repeat submissions reuse the configured page instead of creating a duplicate.

The WooCommerce **My Account -> Licenses** link opens this standalone dashboard.

The same dashboard can also be placed manually with:

`[dreamax_license_dashboard]`

Customers can review the installations attached to each owned license and, when the merchant setting allows it, activate a replacement installation or deactivate an old installation without administrator intervention.

## Data portability

The Import / Export screen provides:

- Downloadable CSV templates
- Source-column mapping
- UTF-8 and legacy Western encoding choices
- Delimiter selection
- Dry-run validation
- Explicit duplicate handling

Larger committed imports run in resumable 250-row background batches.

License exports can be filtered and are masked by default. Full keys require the dedicated export capability.

Activation inventory exports never contain instance fingerprints or license keys.

See `docs/IMPORT-EXPORT.md` for additional details.

The license inventory also offers a masked CSV export of up to 100 selected records. This action never includes full keys, even if an unauthorized client submits the full-key option.

## Setup and diagnostics

The System Status screen checks:

- Runtime requirements
- Database schema and storage engines
- Encryption configuration
- Delivery policy
- First generator and test license
- Customer portal configuration
- REST cache contract
- Background queue
- Proxy and rate-limit storage
- Backup acknowledgement

Administrators can send a key-free test email or download a secret-free JSON support report.

Security settings accept only exact trusted proxy IP addresses and bind backup acknowledgement to the current non-secret master-key identity.

See `docs/SETUP-DIAGNOSTICS.md`.

## License inventory

The administrator inventory supports allowlisted column sorting, exact product-ID filters, and other read-only filters.

It links to existing product, order, customer, and installation records only when the current user has permission to access them.

The inventory highlights:

- Licenses expiring within 30 days
- Imported-key pools with five or fewer available keys

Pool warnings scan a bounded set of configured products and display at most 20 results. They are operational hints rather than a complete stock report.

License details also include administrator-only notes and up to 10 short reference fields.

These fields are not shown to customers or returned by the management API.

Notes are not intended to store secrets. Do not place license keys, credentials, or personal information in them.

Each change is recorded in the audit trail without copying note contents into the event.

Administrators can override the effective activation limit and expiry for an individual license.

Explicit modes preserve the distinction between:

- Unlimited
- Disabled
- Never-expiring
- Fixed UTC values

Updates reject stale forms and positive limits below current active use, preserve unrelated policy metadata, and record the operator's reason.

## Security model

License keys use XChaCha20-Poly1305 authenticated encryption and a separate keyed HMAC-SHA-256 lookup fingerprint.

The root key must be a dedicated 32-byte unpadded base64url value stored in:

`DREAMAX_LICENSE_MANAGER_MASTER_KEY`

The root key is never stored in the database.

See `docs/RECOVERY.md` for recovery and key-management guidance.

## Development and maintenance

Dreamax License Manager is actively maintained.

Ongoing development focuses on:

- WordPress compatibility
- WooCommerce compatibility
- PHP compatibility
- Bug fixes
- Security improvements
- Reliability
- Automated testing
- Developer-facing interfaces
- Documentation
- Future plugin improvements

Changes are developed and reviewed in this public repository before release where applicable.

## Project status

Version `0.4.1` is currently published on WordPress.org.

The project remains under active maintenance and development while preserving the plugin's established public behavior and compatibility expectations.

WordPress.org plugin page:

https://wordpress.org/plugins/dreamax-license-manager/

GitHub repository:

https://github.com/kamruzzamanbsc/dreamax-license-manager
