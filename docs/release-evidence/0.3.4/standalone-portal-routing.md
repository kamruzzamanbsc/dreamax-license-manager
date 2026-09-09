# Standalone Portal Navigation 0.3.4 release evidence

## Scope

- Keep the standalone License Dashboard markup, styling, and script behavior identical to the approved reference package.
- Route the WooCommerce My Account Licenses destination to the configured published standalone dashboard.
- Retain the original WooCommerce licenses endpoint when no usable standalone portal page is configured.
- Preserve the published 0.3.3 security, customer ownership, key reveal, guest claim, and cache-control behavior.

## Review and runtime evidence

- The approved reference ZIP and the candidate source matched byte-for-byte for `assets/css/dashboard.css`, `assets/js/account.js`, and `src/CustomerPortal/class-licensedashboard.php`.
- The local configured portal page was published and contained `[dreamax_license_dashboard]`.
- The WooCommerce licenses URL filter resolved to the standalone portal while unrelated account endpoints remained unchanged.
- An authenticated local shortcode render contained the application shell, sidebar, Overview, My Licenses, Claim Order, and Account panels.
- The public portal request returned HTTP 200 with the login card and standalone dashboard stylesheet.
- The user manually reviewed the installable test package and approved the standalone dashboard behavior on 2026-09-10.

## Automated verification

- PHPUnit: 284 tests and 1839 assertions passed with ZIP support enabled.
- PHPStan: all 57 analyzed files passed with no errors.
- PHPCS: passed with no findings.
- Release metadata validation: passed at version `0.3.4`.
- Deterministic packaging, final artifact hash, inventory, and source commit are recorded in the external `build/release-manifest.json` generated from the final clean commit.

## Publication state

This evidence does not authorize or claim a WordPress.org SVN deployment. Git publication, release artifact generation, and WordPress.org deployment remain separately recorded operations.
