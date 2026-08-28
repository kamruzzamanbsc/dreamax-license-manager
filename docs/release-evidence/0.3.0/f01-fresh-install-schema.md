# F01 fresh-install schema evidence - 0.3.0

- Date: 2026-08-28
- Classification: `PASS_WITH_EVIDENCE`
- Environment: WordPress 7.1; WooCommerce 11.0.1; PHP 8.2.4; MariaDB 10.4.28; single site; Dreamax License Manager 0.3.0
- Scope: clean single-site WordPress table bootstrap, plugin activation, schema, default roles, scheduled cleanup, activation replay, and exact cleanup
- Sensitive-data handling: no credential, password, configuration value, database value, table name, user identifier, Cookie value, nonce, secret, URL, screenshot, or private path is retained.

## Guarded clean-prefix verification

The verifier required the explicit disposable-environment marker and the active WordPress, WooCommerce, and Dreamax runtime. It used a fixed verifier-only table prefix inside the disposable database, and it refused ambiguous cleanup targets or pre-existing residue. Outbound WordPress mail was intercepted before transport.

All 10 recorded acceptance contracts passed:

- the clean prefix received all 12 fresh single-site WordPress core tables and a complete default option/role bootstrap;
- Dreamax activation created exactly nine plugin tables at the current schema version;
- every Dreamax table used InnoDB and contained zero rows immediately after activation;
- the administrator received every Dreamax capability;
- the shop-manager received only the intended management and diagnostics defaults;
- the customer received no Dreamax administrative capability;
- the cleanup event was scheduled exactly once; and
- a second activation preserved the current schema and single cleanup schedule.

## Cleanup proof

The successful run created 21 temporary core and plugin tables under the exact verifier prefix. Deactivation and prefix-scoped cleanup removed all 21. A final diagnosis found zero verifier-owned and zero recovery tables. Existing Dreamax table aggregates matched before and after the run, and no outbound email was sent.

Development dry-run residue was never accepted as evidence. Before retrying, the verifier created and row-count-verified exact recovery copies, removed only fixed-prefix residue, and discarded the recovery copies only after the successful final run and zero-original check.

## Result

Dreamax License Manager activates cleanly on a fresh single-site WordPress schema with the expected InnoDB tables, role defaults, cleanup schedule, and replay behavior. F01 is `PASS_WITH_EVIDENCE`.
