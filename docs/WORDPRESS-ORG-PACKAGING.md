# WordPress.org packaging checklist

Official requirements were checked on 2026-08-25:

- https://developer.wordpress.org/plugins/plugin-basics/header-requirements/
- https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/
- https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/
- https://wordpress.org/plugins/developers/readme-validator/
- https://wordpress.org/plugins/plugin-check/
- https://developer.woocommerce.com/docs/contribution/contributing/version-support-policy/
- https://developer.woocommerce.com/releases/

Current official release pages identified WordPress 7.1 and WooCommerce 11.0.1. Subsequent guarded release-gate runs tested that matrix, including HPOS/Checkout Block and classic-storage/classic-checkout compatibility. Release metadata now retains WordPress `Requires at least: 6.9` and WooCommerce `WC requires at least: 10.8`, while accurately advancing `Tested up to: 7.1` and `WC tested up to: 11.0.1`.

## Verified locally

- Directory/slug: `dreamax-license-manager`.
- Main file: `dreamax-license-manager.php`.
- ZIP location: `dreamax-license-manager/dreamax-license-manager.php`.
- Version/header/constant/readme Stable Tag: numeric `0.3.2`.
- Text domain: literal `dreamax-license-manager`.
- WordPress.org dependency slug: `Requires Plugins: woocommerce`.
- Author: Dreamax Soft; Author URI: `https://dreamaxsoft.com/`.
- Publisher website is linked once in the public readme and recorded as the Composer project homepage.
- No Plugin URI is published because no public plugin-specific page exists.
- GPL-2.0-or-later code and compatible dependency inventory are documented.
- No paid service requirement, telemetry, trialware, remote licensing dependency, or fake Pro interface is included.
- The deterministic allowlist excludes repository/development/local/secret/build artifacts and lints every shipped PHP file.

Run `composer release:validate` for the reproducible local header/readme structure check. Before the first review round, the hosted WordPress.org readme validator accepted the 0.3.1 candidate with only optional notes for upgrade notices, screenshots, and donations, and the official Plugin Check passed with no findings. The 0.3.2 candidate additionally addresses the directory review notice dated 2026-09-03: privileged endpoints use scope-bound permission callbacks, plugin-owned browser assets use enqueue APIs, REST protocol headers are read through `WP_REST_Request`, and the public readme states that the plugin itself has no license gate, payment, subscription, trial, quota, locked feature, or external-service dependency. A corrected ZIP remains unshipped until separately authorized publication.
