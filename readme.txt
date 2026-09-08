=== Dreamax License Manager ===
Contributors: dreamaxsoft
Tags: woocommerce, license manager, software licensing
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.3.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Self-hosted software licensing, activation, delivery, and migration for WooCommerce.

== Description ==

Dreamax License Manager lets WooCommerce store owners generate, import, allocate, deliver, activate, and manage license keys for software products they sell. It includes encrypted license storage, WooCommerce order allocation, guest-order account claims, scoped and rotatable management API credentials, customer license display, and data portability.

The plugin itself does not require a license key, payment, subscription, trial, quota, or external service. Every feature included in this plugin is available without an upgrade. License checks performed by the plugin apply only to license keys that the store owner issues for their own products.

Dreamax License Manager is published by [Dreamax Soft](https://dreamaxsoft.com/).

== Installation ==

1. Confirm WordPress 6.9+, WooCommerce 10.8+, PHP 8.0+, Sodium, HTTPS, and InnoDB.
2. Upload the `dreamax-license-manager` directory and activate it.
3. Open License Manager → System status.
4. Generate the one-time `wp-config.php` master-key snippet and back it up separately.
5. Confirm encryption readiness before creating a license.
6. Open License Manager > Customer Portal and create the login-protected customer dashboard page.

== Frequently Asked Questions ==

= Does the plugin need a paid service or license key? =

No. The plugin is fully self-hosted and all included functionality is available without payment, a subscription, or a license key for the plugin itself.

= Can a database backup alone recover clear license keys? =

No. Back up the dedicated external master key separately and verify a disposable restore.

= Is client-side licensing unbreakable DRM? =

No. Distributed client code is inspectable. The server protects legitimate access, activation state, support, and future service boundaries.

== Changelog ==

= 0.3.2 =

* Addressed WordPress.org review feedback for enqueued assets, privileged REST permission callbacks, request validation, and clearer fully functional self-hosted licensing documentation.

= 0.3.1 =

* Redesigned the license, activity, portability, status, API credential, and WooCommerce order-operation screens with clearer preview, confirmation, availability, and audit context.
* Hardened REST credential handling for absent request bodies and authorization headers, made order recovery and ownership workflows safer, and resolved all official Plugin Check findings with explicit prepared SQL identifiers and uninstall hygiene.

= 0.3.0 =

* Added secure one-time-code guest-order claims, atomic privileged credential rotation/revocation, a central validated versioned audit-event contract, snapshotted refund/cancellation policies, deterministic partial-refund mapping, guarded quantity edits, explicit post-delivery allocation, resend, and preview-confirmed historical-order backfill.
* Added sequential migration verification, deterministic packaging/provenance, seeded performance fixtures, and guarded live acceptance evidence.

= 0.2.0 =

* Added transactional, retry-safe lifecycle operations, bulk administration, reassignment, activation reset, guarded deletion, filters, details, and recent activity.

= 0.1.0 =

* Added the initial development foundation.
