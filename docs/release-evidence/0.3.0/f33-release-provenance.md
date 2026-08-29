# F33 release provenance evidence — 0.3.0

- Date: 2026-08-25
- Classification: `PASS_WITH_EVIDENCE`
- Artifact status: unshipped release-candidate verification only.

The machine-readable schema is `docs/release-manifest.schema.json`. The final sidecar is generated outside the ZIP at ignored `build/release-manifest.json`; the exact inventory is `build/release-inventory.json`. Keeping commit-specific hashes outside Git avoids a self-referential tracked artifact.

The builder requires a recorded 40-character Git commit, exports it twice into separate isolated directories, runs locked `composer install --no-dev`, applies the production allowlist, lints included PHP, normalizes ZIP order/timestamps/permissions, and compares both SHA-256 and exact per-file inventories. A mismatch aborts without copying a candidate. PHPUnit independently creates two deterministic ZIPs and compares their hashes and inventories.

The final generated sidecar records all required schema fields, records WordPress 7.1 and WooCommerce 11.0.1 from the accepted runtime evidence, distinguishes those results from the packaging command itself, and records the PHP version actually used for lint/build. Non-content-printing secret/private-path checks cover the schema, builder, committed files, inventory, and sidecar. Dependency licenses are in `docs/DEPENDENCIES.md`; the locked advisory audit result is recorded by the final QA.
