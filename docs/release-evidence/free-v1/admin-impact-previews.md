# Admin impact preview preflight (not release acceptance)

Date: 2026-09-13. Unreleased `feature/free-v1-completion` branch. Published 0.3.6 is unchanged.

- Bulk actions, single-license lifecycle and reassignment show the current public ID, product, customer/order IDs, exact active-installation count, lifecycle state and UTC expiry. They display the intended effect, reversibility and audit reason before confirmation. Reassignment shows target IDs, installation reset and notification choices; extension estimates the new UTC expiry from the later of current expiry or displayed server time. Any edited choice clears prior confirmation. Server-side authorization, state transitions, locking and audit remain authoritative; the client preview is informational and can become stale.
- Headless Chrome loaded `tests/fixtures/admin-impact-preview.html` and executed `assets/js/admin.js`: 10 of 10 interaction checks passed (selection facts, destructive effect, confirmation, editing resets, UTC extension estimate, masked export, lifecycle, target ownership and reassignment options). The fixture is an isolated DOM contract, not a full WordPress visual or accessibility test.
- `php -d extension=zip vendor/bin/phpunit --configuration phpunit.xml.dist`: 332 tests, 2101 assertions, no failures.
- `php vendor/bin/phpcs --standard=phpcs.xml.dist`: no errors. `php vendor/bin/phpstan analyse -c phpstan.neon --memory-limit=2G`: 72 files, no errors.
- Guarded disposable WordPress site `affiliates-test`: `wp eval-file scripts/verify-live-admin-inventory.php` passed sorting, filters, links, expiry/low-stock indicators, rollback and runtime. `wp eval-file scripts/verify-live-license-policy.php` passed stale rejection, audit/rollback, exact policy and rendered editor.

Remaining: exact release-candidate authenticated browser walkthrough and accessibility/role checks. This evidence does not authorize an SVN or GitHub release.
