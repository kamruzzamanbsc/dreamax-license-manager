=== Dreamax License Manager ===
Contributors: dreamaxsoft
Tags: woocommerce, license manager, software licensing
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.3.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Self-hosted software licensing, activation, delivery, and migration for WooCommerce.

== Description ==

Dreamax License Manager is a self-hosted license operations plugin for WooCommerce stores that sell software and other licensed products. It keeps licensing data under the store owner's control and does not depend on an external licensing service.

The plugin itself does not require a license key, payment, subscription, trial, quota, or external service. Every feature included in this plugin is available without an upgrade. License checks performed by the plugin apply only to license keys that the store owner issues for their own products.

Dreamax License Manager is published by [Dreamax Soft](https://dreamaxsoft.com/).

= License management =

* Generate cryptographically strong license keys or import existing keys.
* Track ownership, lifecycle state, activation use, and expiry in one inventory.
* Assign, extend, suspend, revoke, release, reassign, or delete licenses through controlled workflows.
* Record creation, delivery, activation, reveal, reassignment, and lifecycle changes in an audit trail.
* Preview CSV imports before writing data and export authorized license records when needed.

= WooCommerce automation =

* Enable licensing per simple product or variation.
* Generate keys securely or allocate them from an imported key pool.
* Issue one license per purchased quantity or one per order item.
* Set activation limits, validity periods, and refund and cancellation policies.
* Allocate and deliver licenses when eligible orders are paid.
* Recover or backfill eligible historical orders through guarded order tools.
* Work with WooCommerce HPOS, classic order storage, Checkout Blocks, and classic checkout.

= Customer access =

* Show assigned licenses in the private WooCommerce My Account area.
* Publish a login-protected standalone license dashboard without depending on a theme's WooCommerce account layout.
* Keep license keys masked until an authorized customer chooses to reveal and copy one.
* Let guest purchasers claim eligible orders through a time-limited one-time-code flow.
* Prevent one customer from viewing another customer's license records.

= API and integrations =

* Validate, activate, and deactivate licenses through public REST endpoints.
* Create narrowly scoped, expiring Bearer credentials for trusted management integrations.
* Rotate or revoke management credentials without storing recoverable secrets.
* Apply request validation, rate limits, idempotency controls, and auditable privileged operations.

= Security and recovery =

License keys are protected with authenticated encryption provided by the Sodium PHP extension. The dedicated master key is stored in `wp-config.php`, not in the WordPress database. A keyed lookup fingerprint supports exact matching without storing a searchable plaintext key.

The System Status screen checks encryption, storage, scheduled cleanup, HTTPS, and required platform capabilities. If the master key is missing or does not match, the plugin enters recovery mode and pauses sensitive licensing operations without changing the stored encrypted data.

Back up the dedicated master key separately from the database. A database backup alone cannot recover clear license keys.

= Requirements =

* WordPress 6.9 or later.
* WooCommerce 10.8 or later.
* PHP 8.0 or later with the Sodium extension.
* InnoDB-compatible database tables for transactional activation enforcement.
* HTTPS for production sites and credential-bearing API requests.

== Installation ==

1. Confirm that WordPress, WooCommerce, PHP, Sodium, HTTPS, and the database meet the requirements above.
2. Install and activate WooCommerce.
3. Upload the `dreamax-license-manager` directory to `/wp-content/plugins/`, or install the plugin through the WordPress Plugins screen.
4. Activate Dreamax License Manager.
5. Open License Manager -> System status.
6. Generate the one-time `wp-config.php` master-key snippet and add it to `wp-config.php`.
7. Back up the master key separately and confirm that encryption and storage are ready.
8. Edit a WooCommerce product, enable licensing, and choose its key source, issuance mode, activation limit, validity, refund policy, and cancellation policy.
9. Complete a test order and verify delivery, customer access, and activation before using the workflow in production.
10. Open License Manager -> Customer Portal and create the login-protected customer dashboard page.

== Frequently Asked Questions ==

= Does the plugin need a paid service or license key? =

No. The plugin is fully self-hosted and all included functionality is available without payment, a subscription, or a license key for the plugin itself.

= Can a database backup alone recover clear license keys? =

No. Back up the dedicated external master key separately and verify a disposable restore.

= Does the plugin require WooCommerce? =

Yes. WooCommerce is required for product configuration, order ownership, allocation, delivery, and customer account integration.

= Can I import existing license keys? =

Yes. You can import existing keys for later allocation or create an individual license by importing its exact key. Use the CSV preview before confirming a bulk import.

= How do customers receive and view licenses? =

Eligible paid orders receive assigned licenses through the WooCommerce workflow. Signed-in customers can use the private WooCommerce My Account license area or the separately published standalone dashboard, where keys remain masked until explicitly revealed. Eligible guest orders can be claimed through the one-time-code flow.

= Can another application validate and activate licenses? =

Yes. Public REST endpoints support license validation, activation, and deactivation. Trusted management integrations can use separately scoped and expiring credentials.

= What happens if the master key is lost or changed? =

The plugin enters recovery mode and pauses sensitive operations. Stored encrypted values are not modified, but the original dedicated master key is required to decrypt existing license keys.

= Is client-side licensing unbreakable DRM? =

No. Distributed client code is inspectable. The server protects legitimate access, activation state, support, and future service boundaries.

== Screenshots ==

1. Review license inventory, ownership, lifecycle state, activation use, and expiry from one workspace.
2. Create a securely generated or imported license with clear activation and expiry rules.
3. Inspect license identity and perform controlled lifecycle and ownership operations.
4. Review active installations and the immutable audit trail for an individual license.
5. Configure WooCommerce product issuance, activation, validity, refund, and cancellation policies.
6. Create narrowly scoped, expiring API credentials for trusted integrations.
7. Give signed-in customers a focused standalone dashboard for viewing licenses and claiming eligible guest orders.

== Changelog ==

= 0.3.3 =

* Added a login-protected standalone customer license dashboard, a guarded setup screen, responsive license views, and a clearer guest-order claim flow.
* Rebuilt the WooCommerce My Account license presentation and kept key reveal, ownership, cache, nonce, and redirect boundaries intact.

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
