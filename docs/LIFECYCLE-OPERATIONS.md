# Lifecycle and administration operations

Development version 0.2.0 introduced centralized administrator lifecycle changes in `LifecycleService`. Browser actions require the narrow capability, a WordPress nonce, a written reason, a generated operation ID, and an explicit confirmation checkbox. Each selected license is processed in its own database transaction so one invalid row does not roll back unrelated valid rows.

## State operations

- Suspend is allowed from `available` or `assigned`.
- Restore is allowed only from `suspended`; it returns to `assigned` when ownership/order history exists and otherwise returns to `available`.
- Revoke is terminal.
- Repeating the same operation ID returns the stored result without applying the transition or extension again.
- Reusing a new operation ID against an already-equal or otherwise invalid state is rejected and audited.

## Extension

An extension accepts 1–3650 whole days. It adds time to the later of the existing UTC expiry and current server time, so an expired license receives the full requested period. A lifetime license is rejected rather than accidentally changed into an expiring license. The audit record contains old expiry, requested days, resulting expiry, actor, reason, and operation ID.

## Activation reset

Reset keeps every activation row for history and changes only currently active rows to `inactive`, with a server-time deactivation timestamp. The license key, fingerprint, public ID, and installation fingerprints are not regenerated. The audit event records the affected-row count.

## Reassignment

Reassignment requires an existing customer. An optional target order must belong to that customer. Changing the product public ID requires an active WooCommerce product or variation carrying that stable ID.

The update clears the former order-item and quantity-slot links before the new owner can view the license. When no target order is supplied, the old order association is removed. The event stores complete non-secret before/after ownership fields, reason, and activation-reset choice. Optional email contains only the license public ID and account direction, never the key.

A suspended license remains suspended after reassignment. An available license becomes assigned. A permanently revoked license cannot be reassigned or restored.

## Permanent deletion

Permanent deletion requires `dreamax_lm_delete_license_records` in addition to the ordinary management capability. It is accepted only for `available` pool records that have no customer, order, or activation history. Assigned or historical licenses remain available for accounting, privacy, and audit integrity.

## Bulk behavior

The administration list supports up to 100 selected public IDs per request, filters by lifecycle status, order, customer, expiry, or public/product ID, and offers suspend, restore, revoke, extend, reset, and capability-gated delete actions. Success and rejection counts are reported without exposing secrets. Rejected operations append `license_operation_rejected` when the license still exists.

These source-level contracts still require WordPress/WooCommerce integration, authorization, concurrency, mail, and retry evidence before the related Free V1 gates may pass.
