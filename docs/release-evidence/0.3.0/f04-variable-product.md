# F04 variable-product evidence — 0.3.0

- Date: 2026-08-28
- Classification: `PASS_WITH_EVIDENCE`
- Environment: WordPress 7.1; WooCommerce 11.0.1; PHP 8.2.4; MariaDB 10.4.28; HPOS enabled; Dreamax License Manager 0.3.0
- Scope: two variation line items with explicit, distinct licensing identities and policies
- Sensitive-data handling: no product, variation, order, order-item, license, customer, request, database, or private identifier; license value; Cookie value; nonce; credential; URL; or private path is retained.

## Guarded live verification

The verifier required the disposable-environment marker, active WordPress/WooCommerce/plugin runtime, and InnoDB for every WordPress, WooCommerce HPOS, and plugin table touched by fixture creation or cleanup. It also refused to start if its exact owned product or order marker already existed.

It created one temporary variable product, two temporary virtual variations, and one paid Processing order containing both variations. Outbound WooCommerce order emails were disabled for the verifier run.

Each variation carried an explicit, distinct product public identity and explicit licensing policy:

- variation A used generated, per-quantity issuance with activation limit 1 and 10-day validity;
- variation B used generated, per-item issuance with activation limit 3 and 20-day validity.

The order purchased quantity 2 of each variation. Allocation produced exactly two assigned licenses for the per-quantity variation and exactly one for the per-item variation. Every row retained the parent product relation, the correct variation relation, that variation's public identity, activation limit, and expected quantity slot. The order preview reported two licensed items, three existing licenses, and zero missing slots.

For each of the three temporary licenses, exactly one created, one assigned, and one delivered audit event was present.

## Ownership-scoped restoration

After the assertions, a dedicated InnoDB cleanup transaction removed only events and licenses linked to the exact owned order/license set, then permanently removed that temporary order, its two variations, and its parent product. The verifier confirmed that plugin license/event aggregates returned to their starting values and that no owned product, variation, order, license, or allocation-request event row remained.

## Automated regression coverage

The source-contract test confirms variation metadata is resolved before parent fallback, allocation stores the variation-specific public identity with both parent and variation relations, and the live verifier retains distinct-policy, issuance-mode, rollback, and sensitive-output guards.

## Result

Two variations in one paid order independently used their own explicit licensing identity and policy, with correct per-quantity and per-item allocation. Exact owned fixtures were removed after the live assertions. F04 is `PASS_WITH_EVIDENCE`.
