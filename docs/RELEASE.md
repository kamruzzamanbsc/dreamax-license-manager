# Release and provenance procedure

1. Resolve official author/product/support/source URLs; do not ship `example.invalid` headers.
2. Complete every Free V1 evidence row and supported-version matrix.
3. Run Composer tests, static analysis, coding standards, dependency audit, official WordPress readme validation, the deterministic smoke fixture, disposable full performance/migration/restore matrices, cache/CDN, and frozen reference-client tests.
4. Run `php scripts/build-release.php --commit=<recorded-commit> --output=build`; it performs and compares two isolated clean builds using the commit timestamp.
5. Keep `build/release-manifest.json` outside the ZIP it hashes. Inspect it for secrets, credentials, usernames, and private paths.
6. Inspect the ZIP: exactly one `dreamax-license-manager/` root; numeric matching version/stable tag; no tests, fixtures, caches, local config, credentials, or unrelated artifacts.
7. Record WordPress, WooCommerce, PHP, HPOS, classic checkout, Checkout Block, multisite, object cache, reverse proxy, and CDN versions actually tested.

The artifact is an unshipped release-candidate verification build. Empty WordPress/WooCommerce `tested_versions` arrays and remaining manual gates intentionally block release.
