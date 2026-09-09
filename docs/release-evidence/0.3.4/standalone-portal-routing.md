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
- Official Plugin Check 2.1.0 update-mode scans passed with no errors against the exact package in both active and inactive states.
- Deterministic packaging produced 68 files from source commit `8f27e5ca400fa97c1cfebd21d4eb7a463e597801`; both clean builds were identical.
- Final artifact: `dreamax-license-manager-0.3.4.zip` with SHA-256 `12daf26318ee0e71d3b58fe1d9a19f1595061fa95dca9b35dd37fcdec8466974`.

## Publication state

- The release source and evidence were pushed to `origin/feature/customer-license-portal`.
- WordPress.org SVN `trunk` and `tags/0.3.4` each matched all 68 artifact files byte-for-byte; directory `assets` remained unchanged.
- The authorized SVN commit completed as revision `3688887`.
- The public WordPress.org listing and versioned download both resolved to version `0.3.4` after publication.
