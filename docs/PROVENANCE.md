# Reproducible build and provenance

`docs/release-manifest.schema.json` is the machine-readable v1 sidecar schema. `scripts/build-release.php` accepts only an explicit recorded commit and output below ignored `build/`. It exports that commit twice, installs production Composer dependencies from `composer.lock` in each isolated snapshot, applies the same allowlist, lints included PHP, and writes deterministic ZIP entries in lexical order with the commit timestamp and normalized Unix permissions.

Both ZIP SHA-256 values, per-file SHA-256 inventories, and exact path lists must match. Any mismatch aborts without publishing an artifact. The output manifest remains outside the ZIP it hashes and records the source commit/state, stable command/profile, artifact and lock digests, dependency inventory, actually tested versions, UTC test time, evidence reference, tool versions, inventory digest, source epoch, and comparison result. It contains no absolute build path, local username, database value, credential, token, key, or other secret.

The build includes no WordPress or WooCommerce runtime and therefore leaves those tested-version arrays empty. PHP is recorded because the build lints every included PHP file. This limitation does not weaken the reproducibility result, but it prevents interpreting the artifact as production-ready.
