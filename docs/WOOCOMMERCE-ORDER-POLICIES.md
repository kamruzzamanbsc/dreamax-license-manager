# WooCommerce order policies

Review date: 2026-08-24

Applies to development version: 0.3.0

This document defines the order behavior implemented at source level. Runtime acceptance evidence remains open in `FREE-V1-MUST-PASS.md`.

## Product and variation policy

Each licensed product has an explicit issuance mode, source, activation limit, validity, refund policy, and cancellation policy. A non-empty variation value overrides its parent product. The resolved values are copied into each license when it is issued, so later catalog edits do not silently change an earlier order's policy.

Refund and cancellation policies are:

| Setting | Result |
| --- | --- |
| Retain | Keep the current lifecycle state and record the order-policy operation. |
| Suspend | Move a non-revoked license to `suspended`. |
| Revoke | Move the license to terminal `revoked`. |
| Release unused | Return only an imported-pool license that is assigned, has no activation history, and has no delivery evidence. Any ineligible release safely becomes suspension; generated or delivered keys are never returned to a shared pool. |

## Refund mapping

For one-license-per-quantity products, a partial refund maps to the highest original quantity slots first. A five-unit item therefore maps a two-unit first refund to slots 4 and 5; the next one-unit refund maps to slot 3. The refund ID and license ID form the retry identity, and the processed operation hash is stored with the license.

For one-license-per-order-item products, the single license is affected only when the cumulative refunded quantity reaches the original order-item quantity. A full-order refund without line-item quantities applies the configured refund policy to every order license. An unmatchable partial refund changes no license and writes `order_refund_unmapped` evidence.

## Cancellation

The cancellation hook applies the snapshotted cancellation policy to every license still associated with the order. Repeated hook delivery replays the recorded operation rather than applying a second transition.

## Quantity edits

- Before first delivery, the quantity present when the paid-order hook runs becomes the initial delivery target.
- After delivery, automatic hooks cannot allocate beyond that target.
- An authorized administrator must use **License Manager → Order tools**, preview the order, and explicitly confirm a post-delivery increase.
- A decrease never deletes, reveals, detaches, or returns an existing delivered license. It produces an order note and audit event.
- An order-item deletion after delivery retains license history and appends a warning plus audit evidence.
- Refunded quantity slots are excluded from backfill. A post-delivery increase after a quantity refund is rejected for manual review because silently remapping earlier refunds would be unsafe.

The database uniqueness constraint on `(order_item_id, quantity_slot)` is the final duplicate-allocation guard.

## Resend and historical-order tools

The WooCommerce order-action menu can resend currently `assigned` keys to the order's current valid billing email. Suspended and revoked keys are excluded. The bulk order tool accepts at most 50 order IDs, reveals no keys in preview, requires a nonce and explicit checkbox confirmation, and uses a 15-minute preview identity bound to the exact operation and order list.

Historical allocation creates only missing eligible slots for paid, non-cancelled, non-failed, non-refunded orders. Retried allocation converges on existing slots. Resend uses a database-unique, expiring operation claim to prevent concurrent duplicate email for the same confirmed request. Resend and allocation record actor, order, request identity, counts, and outcomes without placing clear keys in audit metadata.

## Required acceptance tests

The following still require a real WordPress, WooCommerce, PHP, database, and mail-capture environment:

- simple and variable products in both issuance modes;
- duplicate and concurrent paid-order hooks;
- every refund/cancellation policy, including repeated and partial refunds;
- quantity increase/decrease/delete before and after delivery;
- pool exhaustion, eligible release, and blocked release;
- HPOS order edits, preview/confirm authorization, resend delivery, and failure recovery.
