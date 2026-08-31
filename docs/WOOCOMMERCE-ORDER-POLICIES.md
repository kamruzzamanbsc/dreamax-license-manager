# WooCommerce order policies

Review date: 2026-08-28

Applies to development version: 0.3.1

This document defines the implemented order behavior. Guarded runtime acceptance for the F13 refund, cancellation, quantity, backfill, resend, pool, lifecycle, replay, audit, and cleanup matrix is recorded in `release-evidence/0.3.0/f13-order-policy-matrix.md`.

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

## Guest-order account claims

Guest order license output is shown only inside WooCommerce's verified order context with a valid order key. A billing email address by itself never proves access. An authenticated customer can request a one-time claim code for a paid guest order whose normalized billing email exactly matches the account email. The code is delivered to that billing address, expires after 30 minutes by default, and is accepted only through a nonce-protected POST over HTTPS.

The database stores only a purpose-separated keyed hash of the code. Claim and ownership rows are locked during verification, the active-order and owner constraints prevent concurrent account claims, and successful consumption clears the hash. A successful claim updates the WooCommerce customer through order CRUD and reassigns the order's licenses to the same account. Reissue, expiry, ownership changes, privacy erasure, administrator release, and administrator override invalidate outstanding proofs.

Administrators can release or override a claim from **License Manager -> Order tools** only with the management capability, nonce verification, and explicit confirmation. See `GUEST-ORDER-CLAIMS.md` for customer and merchant instructions and `adr/0002-secure-guest-order-claims.md` for the security decision.

## Runtime acceptance status

The guarded F13 matrix passed every refund and cancellation policy, partial/full/unmapped refund handling, repeated operations, before/after-delivery quantity boundaries, pool exhaustion and release safety, lifecycle edits, confirmed backfill, and resend failure recovery on the recorded HPOS/InnoDB environment. It intercepted outbound mail and removed only exact owned fixtures with unchanged final aggregates.

Broader release gates still require the guest-order claim mail/replay/expiry/concurrency matrix and classic-storage parity. Those remain separate from the completed F13 evidence and must not be inferred as passed.
