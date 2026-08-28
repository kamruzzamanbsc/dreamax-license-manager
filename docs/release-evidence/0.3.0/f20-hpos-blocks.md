# F20 HPOS, classic storage, and checkout compatibility evidence - 0.3.0

- Date: 2026-08-29
- Classification: `PASS_WITH_EVIDENCE`
- Environment: WordPress 7.1; WooCommerce 11.0.1; PHP 8.2.4; MariaDB 10.4.28; HPOS and classic order storage; Checkout Block and classic checkout; Dreamax License Manager 0.3.0
- Scope: preserved manual HPOS/Checkout Block observation; private-clone storage/checkout matrix; repeated paid-order allocation parity; physical order placement; source preservation; exact cleanup
- Sensitive-data handling: no screenshot, cart or customer value, order or product identifier, license value, key material, database credential, configuration value, Cookie or session value, nonce, URL, private identifier, or private path is retained.

## Preserved initial observation

The earlier read-only disposable-site inspection established that WooCommerce HPOS was enabled, the Dreamax license table used InnoDB, and the published Checkout page used the WooCommerce Checkout Block rather than the classic shortcode. The same environment had already completed the storefront, cart, Checkout Block, Direct bank transfer, Processing transition, one-slot allocation, My Account display, and activity-event path preserved with F03 evidence. That inspection made no page, cart, order, email, plugin-data, or database change.

## Guarded compatibility matrix

The automated verifier required the explicit disposable marker, active WordPress/WooCommerce/Dreamax runtime, HPOS and Checkout Block on the source, InnoDB for every required table, and zero earlier F20 database or clone residue. The original WordPress tree, configuration, and database were read-only inputs.

Only after that diagnosis passed, the verifier created one random private file clone and two random verifier-owned database clones. One clone activated HPOS with the Checkout Block and the other activated classic order storage with the classic checkout shortcode. WooCommerce's authoritative feature flag and current datastore-class API confirmed each storage mode, and the WordPress parser confirmed each checkout mode.

Each mode ran the same paid Processing-order scenario twice. Every one of the four runs produced:

- exactly two assigned licenses for purchased quantity two;
- quantity slots one and two with zero missing preview slots;
- exactly two created, two assigned, and two delivered audit events;
- the expected eligible Order Tools preview; and
- physical order placement in the active datastore only.

The normalized outcomes were identical between repeats and between HPOS/Checkout Block and classic-storage/classic-checkout modes. Outbound email was disabled throughout.

Early diagnostic runs exposed two verifier-only assumptions: an unchanged option value was initially confused with a missing option, and WooCommerce 11's datastore wrapper required `get_current_class_name()` rather than a direct concrete-object check. Neither run promoted the gate. Every failed diagnostic run removed its exact databases and file clone before the corrected matrix ran.

## Cleanup and source preservation

The final run dropped both exact verifier-owned databases and removed the exact private file clone. The original database and configuration retained their starting digests. An independent post-run diagnosis again passed every source-mode, InnoDB, and residue check, including zero F20 database or clone residue.

## Result

Dreamax License Manager produced the same repeatable paid-order licensing result across all supported storage and checkout combinations. F20 is `PASS_WITH_EVIDENCE`.
