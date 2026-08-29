# Release and provenance procedure

1. Confirm the resolved official author metadata and retain no release-facing placeholder URL.
2. Confirm all 33 Free V1 evidence rows and the supported-version matrix remain complete.
3. Run Composer tests, static analysis, coding standards, dependency audit, official WordPress readme validation, the deterministic smoke fixture, disposable full performance/migration/restore matrices, cache/CDN, and frozen reference-client tests.
4. Run `php scripts/build-release.php --commit=<recorded-commit> --output=build`; it performs and compares two isolated clean builds using the commit timestamp.
5. Keep `build/release-manifest.json` outside the ZIP it hashes. Inspect it for secrets, credentials, usernames, and private paths.
6. Inspect the ZIP: exactly one `dreamax-license-manager/` root; numeric matching version/stable tag; no tests, fixtures, caches, local config, credentials, or unrelated artifacts.
7. Record WordPress, WooCommerce, PHP, HPOS, classic checkout, Checkout Block, multisite, object cache, reverse proxy, and CDN versions actually tested.

The artifact remains an unshipped release-candidate verification build. Its manifest records WordPress 7.1 and WooCommerce 11.0.1 from the accepted runtime evidence while clearly stating that the build command itself does not execute those runtimes. Tagging, GitHub Release creation, WordPress.org submission, and production deployment are separate authorized operations.
