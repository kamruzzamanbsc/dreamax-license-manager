# Dreamax License Manager

[![Quality](https://github.com/kamruzzamanbsc/dreamax-license-manager/actions/workflows/quality.yml/badge.svg)](https://github.com/kamruzzamanbsc/dreamax-license-manager/actions/workflows/quality.yml)

Dreamax License Manager is an open-source, self-hosted licensing infrastructure
layer for WordPress and WooCommerce developers. It lets a store issue, deliver,
validate, activate, recover, and audit software licenses while keeping licensing
data and cryptographic control on the store owner's infrastructure.

It is developed and maintained by [Dreamax Soft](https://dreamaxsoft.com) and is
available from the
[WordPress.org Plugin Directory](https://wordpress.org/plugins/dreamax-license-manager/).
Version `0.4.1` is the current published release.

This repository contains the actively maintained development source, tests,
operational documentation, and release evidence.

## Why this project exists

Developers selling WordPress plugins or other licensed products often have to
choose between an external licensing service and building security-sensitive
license infrastructure from scratch. Dreamax License Manager provides a
self-hosted foundation integrated with WooCommerce orders while keeping the
public API, lifecycle policy, encryption boundary, customer access, and audit
history under the merchant's control.

It is infrastructure rather than unbreakable DRM. Distributed client code is
inspectable; the server protects legitimate access, activation state, delivery,
support, and future service boundaries.

## Core capabilities

- Generate cryptographically strong keys or allocate from imported key pools.
- Bind issuance policies to WooCommerce products and variations.
- Allocate and deliver exact quantity slots only for eligible paid orders.
- Validate, activate, deactivate, suspend, revoke, extend, release, and
  reassign licenses through guarded workflows.
- Provide a private customer dashboard and single-use guest-order claim flow.
- Expose versioned public lifecycle endpoints and scoped management endpoints.
- Encrypt license keys at rest and resolve exact matches through a separate
  keyed lookup fingerprint.
- Record versioned, sanitized audit events and support privacy-aware portability.
- Fail closed during storage, encryption, or dependency recovery states.

## Architecture at a glance

```text
WooCommerce order and product policy
                |
                v
     License and lifecycle services
        |          |          |
        v          v          v
   Encryption   Activations   Audit events
        |          |          |
        +----------+----------+
                   |
                   v
        Site-local InnoDB storage
                   ^
                   |
     REST API / Admin / Customer portal
```

WordPress and WooCommerce are integration boundaries. Licensing rules live in
namespaced services, and storage access is kept behind repository/service
boundaries. Read the full [architecture](docs/ARCHITECTURE.md),
[threat model](docs/THREAT-MODEL.md), and
[v1 contract decisions](docs/adr/0001-v1-contracts.md).

## Developer entry points

- [REST API v1](docs/API.md) and the machine-readable
  [OpenAPI document](docs/openapi-v1.yaml)
- [API credential lifecycle](docs/API-CREDENTIALS.md)
- [Dependency-light PHP client example](examples/php-client.php)
- [Hooks and filters](docs/HOOKS-AND-FILTERS.md)
- [Capability and role matrix](docs/CAPABILITIES.md)
- [Commercial extension contract v1](docs/COMMERCIAL-EXTENSIONS.md)
- [Audit-event producer and consumer guidance](docs/AUDIT-EVENTS.md)

The REST base path is `/wp-json/dreamax-license-manager/v1`. Production calls
that carry a license key or credential require HTTPS. Public lifecycle routes
accept license proof; management routes require narrowly scoped, expiring
Bearer credentials. Never put a license key, credential, token, or idempotency
value in a URL.

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

## Quality and release discipline

The repository includes PHPUnit tests, source-contract tests, PHPStan, WordPress
Coding Standards, deterministic release tooling, frozen fixtures, and guarded
live verifiers for disposable environments. The recorded `0.4.1` release
verification reports 354 tests and 2,247 assertions, with PHPCS, PHPStan,
release-metadata validation, deterministic packaging, and exact WordPress.org
package comparison passing.

That record describes the reviewed release, not an automatic claim about later
changes. See the
[0.4.1 verification record](docs/release-evidence/0.4.1/release-verification.md),
[build guide](docs/BUILDING.md), and [release process](docs/RELEASE.md).

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

## Contributing and security

Contributions that preserve public contracts and security boundaries are
welcome. Start with [CONTRIBUTING.md](CONTRIBUTING.md). Report suspected
vulnerabilities privately according to [SECURITY.md](SECURITY.md), and never
include real keys, credentials, customer data, database dumps, or master-key
material in an issue or test fixture.

The current open-source application-readiness work is tracked in
[docs/OPEN-SOURCE-READINESS.md](docs/OPEN-SOURCE-READINESS.md). The record is a
program-neutral maintenance plan, not a claim of eligibility or acceptance.

## Scope and limitations

- WooCommerce is required for product, order, allocation, delivery, and
  customer-ownership workflows.
- Sodium, an external master key, HTTPS, and InnoDB are operational security
  requirements, not optional production hardening.
- A database backup without the matching external master key cannot recover
  clear license keys.
- The plugin does not make client-side licensing unbreakable and does not claim
  broad ecosystem adoption or universal deployment compatibility.
- Guarded migration, recovery, concurrency, mail, and live verification scripts
  are for explicitly marked disposable environments only.

## Project status

Version `0.4.1` is currently published on WordPress.org.

The project remains under active maintenance and development while preserving the plugin's established public behavior and compatibility expectations.

- [WordPress.org plugin page](https://wordpress.org/plugins/dreamax-license-manager/)
- [GitHub repository](https://github.com/kamruzzamanbsc/dreamax-license-manager)
- [Project status](PROJECT_STATUS.md)
- [Changelog](CHANGELOG.md)
