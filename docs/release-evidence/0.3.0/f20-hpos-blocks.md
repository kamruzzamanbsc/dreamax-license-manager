# F20 HPOS and Checkout Block partial evidence — 0.3.0

- Date: 2026-08-27
- Classification: `MANUAL_ENVIRONMENT_REQUIRED`
- Environment: WordPress 7.1; WooCommerce 11.0.1; PHP 8.2.4; MariaDB 10.4.28; HPOS enabled; Dreamax License Manager 0.3.0
- Scope: read-only configuration and page inspection on the disposable environment
- Sensitive-data handling: no screenshot, cart content, customer data, order reference, license value, Cookie, session value, URL, or private identifier is retained or referenced.

## Sanitized observations

- WooCommerce HPOS was enabled and the plugin reported its license table as InnoDB.
- The published Checkout page used the WooCommerce Checkout Block rather than the classic checkout shortcode.
- The same disposable HPOS environment had previously completed the storefront, cart, Checkout Block, Direct bank transfer, Processing transition, one-slot license allocation, My Account display, and activity-event path recorded as partial F03 evidence in the release audit context.
- This focused inspection made no page edit, save, cart mutation, order, email attempt, plugin-data change, or database change.

## Evidence limit

F20 is not `PASS_WITH_EVIDENCE`. Classic order storage, classic checkout, repeated parity runs, and comparison of equivalent outcomes across every supported mode remain unverified. F20 therefore remains `MANUAL_ENVIRONMENT_REQUIRED`.
