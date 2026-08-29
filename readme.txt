=== Dreamax License Manager ===
Contributors: dreamaxsoft
Tags: woocommerce, license manager, software licensing
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Self-hosted software licensing, activation, delivery, and migration for WooCommerce.

== Description ==

This package is an unshipped 0.3.0 release candidate. It includes encrypted license storage, generator and imported-pool foundations, WooCommerce allocation, secure guest-order account claims, scoped and rotatable privileged API credentials, a versioned REST API, customer license display, and data portability. It does not depend on a paid API or remote SaaS.

All 33 Free V1 acceptance gates have recorded evidence. Tagging, publication, and production deployment remain separate release decisions; validate backups, HTTPS, InnoDB, mail, cache/proxy behavior, and the external master key in the target environment before deployment.

== Installation ==

1. Confirm WordPress 6.9+, WooCommerce 10.8+, PHP 8.0+, Sodium, HTTPS, and InnoDB.
2. Upload the `dreamax-license-manager` directory and activate it.
3. Open License Manager → System status.
4. Generate the one-time `wp-config.php` master-key snippet and back it up separately.
5. Confirm encryption readiness before creating a license.

== Frequently Asked Questions ==

= Does Free core need a paid service? =

No. Core licensing is self-hosted.

= Can a database backup alone recover clear license keys? =

No. Back up the dedicated external master key separately and verify a disposable restore.

= Is client-side licensing unbreakable DRM? =

No. Distributed client code is inspectable. The server protects legitimate access, activation state, support, and future service boundaries.

== Changelog ==

= 0.3.0 =

* Added secure one-time-code guest-order claims, atomic privileged credential rotation/revocation, a central validated versioned audit-event contract, snapshotted refund/cancellation policies, deterministic partial-refund mapping, guarded quantity edits, explicit post-delivery allocation, resend, and preview-confirmed historical-order backfill.
* Added sequential migration verification, deterministic packaging/provenance, seeded performance fixtures, and guarded live evidence for all 33 Free V1 acceptance gates. This remains an unshipped release candidate.

= 0.2.0 =

* Added transactional, retry-safe lifecycle operations, bulk administration, reassignment, activation reset, guarded deletion, filters, details, and recent activity. Still not a production Free V1 release.

= 0.1.0 =

* Development foundation. Not a production Free V1 release.
