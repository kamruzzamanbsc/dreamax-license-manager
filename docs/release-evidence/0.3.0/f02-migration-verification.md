# F02 installation and migration evidence — 0.3.0

- Date: 2026-08-25
- Classification: `MANUAL_ENVIRONMENT_REQUIRED`
- Automated scope: version inventory, additive schema snapshots, sequential checkpoint source contract, deterministic replay, fresh-install plan, downgrade guard, required data columns/indexes, disposable guard, and multisite prefix isolation.

The executable plan supports v1 (`c7701de`, seven tables), v2 (`3e0739f`, guest claims/order owners), and v3 (`8e47d74`, credential lifecycle columns). Fresh installs and upgrades now traverse every later version in order. A checkpoint is stored only after its `dbDelta` snapshot returns without a WordPress database error; partial DDL is documented as recoverable by idempotent replay, not transactionally rolled back.

`composer migration:verify` rendered each snapshot twice under `wp_2_dreamax_lm_`, confirmed identical statements, expected table counts 7/9/9, InnoDB declarations, and no database access. PHPUnit covers required public IDs, encrypted fields, activation/audit/credential/claim/ownership structures, unique keys/indexes, sequential installer ordering, invalid versions/prefixes, and future-version downgrade refusal.

No MySQL, WordPress, Docker, or WP-CLI runtime is available. Fresh/upgrade/interrupt/data-preservation/index/multisite execution on disposable real MySQL remains required by `docs/MIGRATIONS.md`. No production data or credential was requested or touched.
