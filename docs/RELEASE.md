# Release and provenance procedure

1. Resolve official author/product/support/source URLs; do not ship `example.invalid` headers.
2. Complete every Free V1 evidence row and supported-version matrix.
3. Run Composer tests, static analysis, coding standards, dependency audit, WordPress readme validator, full performance fixture, disposable restore, cache/CDN, and frozen reference-client tests.
4. Build twice from the same clean commit with the same `SOURCE_DATE_EPOCH`; compare ZIP SHA-256 values.
5. Keep `build/release-manifest.json` outside the ZIP it hashes. Inspect it for secrets, credentials, usernames, and private paths.
6. Inspect the ZIP: exactly one `dreamax-license-manager/` root; numeric matching version/stable tag; no tests, fixtures, caches, local config, credentials, or unrelated artifacts.
7. Record WordPress, WooCommerce, PHP, HPOS, classic checkout, Checkout Block, multisite, object cache, reverse proxy, and CDN versions actually tested.

The current build profile is a development distribution and its empty `tested_versions` arrays intentionally block release.
