# Versioned audit event catalog

All events use payload schema version 1, UTC time, opaque event ID, actor classification, applicable license/order/product/request references, and sanitized metadata.

Published types include `license_created`, `license_assigned`, `license_delivered`, `license_activated`, `license_reactivated`, `license_deactivated`, `activation_failed`, `license_extended`, `license_activations_reset`, `license_expired`, `license_suspended`, `license_restored`, `license_revoked`, `license_imported`, `license_exported`, `license_revealed`, `license_reassigned`, `license_reassignment_notified`, `license_reassignment_notification_failed`, `license_operation_rejected`, `license_deleted`, `license_renewed`, `license_resent`, `order_allocation_failed`, `order_automatic_allocation_completed`, `order_explicit_allocation_completed`, `order_refunded`, `order_refund_policy_applied`, `order_refund_unmapped`, `order_cancellation_policy_applied`, `order_quantity_changed_after_delivery`, `order_item_deleted_after_delivery`, `credential_created`, `credential_rotated`, and `credential_revoked`. The development foundation also uses `license_updated` and `privacy_data_anonymized`; they require final catalog schemas before release.

Guest-claim schema-version-1 types are `guest_claim_issued`, `guest_claim_succeeded`, `guest_claim_replay_failed`, `guest_claim_expired_failed`, `guest_claim_conflict_failed`, `guest_claim_released`, and `guest_claim_administrator_override`. Their metadata is limited to order/customer references, counts, lifetime, scope, and bounded failure classification. It never contains a billing email, claim code/hash, order key, or license key.

Order-policy events record configured/effective policy, before/after state, order/item/slot references, actor, and bounded request identity. The `release_unused` effective result distinguishes a safe release from `release_blocked_suspended`.

Additive metadata is compatible. Meaning changes require a new schema version and consumer/migration documentation. Events never contain clear keys, credentials, tokens, raw IPs, request bodies, or Authorization headers.
