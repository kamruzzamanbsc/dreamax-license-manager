# Customer portal and REST regression acceptance

Date: 2026-09-14. Unreleased `feature/free-v1-completion` branch. Disposable local WordPress 7.1 / WooCommerce 11.0.1 / PHP 8.2.12 / MariaDB 10.4.28.

The guarded release fixture created one synthetic customer, paid order, licensed product, and assigned encrypted license without external email or protected output. Current-branch live verification then passed:

- the published standalone License Dashboard shortcode page and guest login boundary;
- owner-only masked license rendering, owned activation rendering, guarded claim forms, and required dashboard assets;
- isolation from a second customer with no license, key, or activation-label disclosure;
- the unchanged frozen v1 client for activation replay, conflict, validation, and deactivation;
- the 17-outcome public lifecycle API matrix and exact idempotency replay/conflict/cleanup contract;
- untrusted and trusted proxy handling, private rejection envelopes, bounded timing shape, and failure rate limiting.

The dashboard verifier records each temporary activation event, activation, and customer with unique identifiers and deletes those exact records in `finally`. The fixture manager then removed the exact order, product, customer, license, activation, and audit records and reported `records_remaining: 0`. No key, credential, cookie, filesystem path, or database credential was printed.

Commands: `scripts/manage-live-release-fixture.php`, `scripts/verify-live-license-dashboard.php`, `scripts/verify-live-customer-isolation.php`, `scripts/verify-live-frozen-v1-client.php`, `scripts/verify-live-public-lifecycle-api.php`, `scripts/verify-live-idempotency-contract.php`, and `scripts/verify-live-enumeration-proxy-abuse.php` against the guarded `affiliates-test` site.

Result: PASS for the current Free V1 branch. This evidence is not an SVN release authorization.
