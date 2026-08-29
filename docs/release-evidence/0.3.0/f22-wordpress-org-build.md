# F22 WordPress.org validation and build evidence — 0.3.0

- Date: 2026-08-25
- Classification: `PASS_WITH_EVIDENCE`
- Artifact status: unshipped release-candidate verification only; not production-ready.

Official sources checked on 2026-08-25 are recorded in `docs/WORDPRESS-ORG-PACKAGING.md`. WordPress 7.1 and WooCommerce 11.0.1 were current; later guarded compatibility and gate matrices tested that runtime, so final release-candidate metadata accurately advances the tested versions while retaining the 6.9/10.8 minimum requirements.

The official hosted WordPress.org readme validator accepted `readme.txt` without errors. It emitted only optional/advisory notes: one tag was not widely used, and no Upgrade Notice, Screenshots, or Donate Link was present. No placeholder content was added to silence optional notes. The local validator confirms slug, main file, complete header, literal text domain, WooCommerce dependency slug, numeric version/stable tag, GPL metadata, and required readme sections.

The deterministic build exports a recorded commit twice, installs locked production Composer dependencies in each isolated snapshot, uses an explicit production allowlist, lints every included PHP file, verifies the one-root ZIP structure and exact inventory, and refuses prohibited/sensitive paths. The final ignored `build/release-manifest.json` and `build/release-inventory.json` contain the exact clean-commit artifact evidence. The ZIP includes no development dependency because the locked production dependency set is empty.

Official Plugin Check runtime execution still belongs to the wider manual WordPress acceptance matrix; it does not invalidate the completed F22 readme/header/clean-build automation or promote the overall plugin to production-ready.
