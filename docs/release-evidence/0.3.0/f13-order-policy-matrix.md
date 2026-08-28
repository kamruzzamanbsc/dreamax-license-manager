# F13 order-policy acceptance evidence - 0.3.0

- Date: 2026-08-28
- Classification: `PASS_WITH_EVIDENCE`
- Environment: WordPress 7.1; WooCommerce 11.0.1; PHP 8.2.4; MariaDB 10.4.28; HPOS enabled; Dreamax License Manager 0.3.0
- Scope: refund, cancellation, quantity, backfill, resend, pool, lifecycle, replay, audit, and exact cleanup behavior
- Sensitive-data handling: no license value, user/order/license/refund/product/event/request identifier, email address, Cookie value, nonce, credential, secret, database value, URL, screenshot, or private path is retained.

## Guarded live matrix

The verifier required the explicit disposable-environment marker, active plugin, HPOS-backed WooCommerce orders, and InnoDB for every plugin table involved. Outbound WordPress mail was intercepted before transport. Temporary products, customers, orders, refunds, licenses, activations, events, and operation claims used exact ownership markers.

All 37 recorded acceptance contracts passed:

- initial per-quantity allocation, confirmed post-delivery increase, retained decrease history, and retained deleted-item history;
- non-overlapping highest-slot partial refunds, blocked post-refund increases, and audited unmapped refunds;
- standard WooCommerce full-refund hook processing, same-operation replay, and exactly one policy audit event per exact policy fixture;
- retain, suspend, revoke, eligible `release_unused`, and safely blocked `release_unused` outcomes for both refund and cancellation;
- cancellation replay across the four rows that remain attached after eligible release;
- pre-delivery quantity edits, recoverable pool exhaustion, and explicit missing-slot backfill;
- lifecycle expiry extension, activation reset, reassignment, and operation replay behavior; and
- resend success, replay, failed-claim release, and successful recovery without outbound transport.

The cancellation policy audit contract was corrected to represent its not-applicable refund field with the catalog's accepted non-negative sentinel. Focused regression coverage locks that production boundary and the guarded verifier's standard WooCommerce refund path.

## Cleanup proof

Cleanup committed only for the exact owned fixtures. Starting and ending aggregates matched and zero owned fixture rows remained. A separate read-only diagnosis then reported zero owned products, users, orders, licenses, activations, events, and operations, plus zero fully orphaned licenses or order events.

## Result

The configured order policies are deterministic, replay-safe, audited, recoverable, and cleanup-safe in the recorded disposable WordPress/WooCommerce/HPOS/InnoDB environment. F13 is `PASS_WITH_EVIDENCE`.
