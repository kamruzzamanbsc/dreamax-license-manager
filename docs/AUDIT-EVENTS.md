# Versioned audit-event catalog

This is the published consumer catalog for development version 0.3.2. The authoritative executable definition is `AuditEventCatalog`; every production emitter names a catalog constant and schema version explicitly, and `EventRepository` validates the complete envelope before persistence.

## Stable envelope

Every newly persisted event contains:

- `public_id`: opaque `evt_` identifier generated from 128 random bits;
- `event_type`: exact stable catalog name;
- `schema_version`: positive supported payload version;
- `occurred_at`: server-generated UTC `YYYY-MM-DD HH:MM:SS`;
- `actor_type`: one of `administrator`, `api_credential`, `customer`, `privacy_tool`, `public_api`, `system`, or `woocommerce`;
- optional positive internal `license_id` and `actor_id` references according to the event contract;
- optional bounded opaque `request_id`/operation reference according to the event contract;
- UTF-8 JSON metadata that passes recursive redaction and the exact required/optional field contract.

Reference notation below is `L` license, `A` actor, and `R` request/operation; each is `required`, `optional`, or `none`. `int+` is a positive integer, `int0+` is a non-negative integer, `?` is nullable, and `enum(...)` lists the only values.

## Credential events — schema v1

| Event type | Actors | References | Required metadata | Optional metadata |
| --- | --- | --- | --- | --- |
| `credential_created` | administrator | L:none A:required R:optional | `credential_public_id:string(22)`, `scopes:list<string>`, `expires:bool` | none |
| `credential_authentication_used` | api_credential | L:none A:required R:required | `credential_public_id`, `required_scope:string` | none |
| `credential_rotation_succeeded` | administrator | L:none A:required R:optional | `credential_public_id`, `previous_version:int+`, `new_version:int+` | none |
| `credential_rotation_failed` | administrator | L:none A:required R:optional | `credential_public_id`, `expected_version:int+`, `failure:enum(not_completed)` | none |
| `credential_revoked` | administrator | L:none A:required R:optional | `credential_public_id` | none |
| `credential_expired_authentication_failed` | api_credential | L:none A:required R:required | `credential_public_id` | none |
| `credential_insufficient_scope` | api_credential | L:none A:required R:required | `credential_public_id`, `required_scope:string` | none |

## Guest-claim events — schema v1

| Event type | Actors | References | Required metadata | Optional metadata |
| --- | --- | --- | --- | --- |
| `guest_claim_issued` | customer | L:none A:required R:required claim ID | `order_id:int+`, `lifetime_seconds:int+` | none |
| `guest_claim_succeeded` | customer | L:none A:required R:required claim ID | `order_id:int+`, `license_count:int+` | none |
| `guest_claim_replay_failed` | customer | L:none A:required R:required claim ID | `order_id:int+`, `failure:enum(replay)` | none |
| `guest_claim_expired_failed` | customer | L:none A:required R:required claim ID | `order_id:int+`, `failure:enum(expired)` | none |
| `guest_claim_conflict_failed` | customer, woocommerce | L:none A:optional R:required claim ID | `order_id:int+`, `failure:enum(conflict,ownership_changed)` | none |
| `guest_claim_released` | administrator | L:none A:required R:optional | `order_id:int+`, `license_count:int+` | none |
| `guest_claim_administrator_override` | administrator | L:optional A:required R:optional | `order_id:int+`, `customer_id:int+`, exactly one of `license_count:int+` or `scope:enum(single_license_reassignment)` | the alternate variant field only |

## License events — schema v1

| Event type | Actors | References | Required metadata | Optional metadata |
| --- | --- | --- | --- | --- |
| `license_created` | administrator, api_credential, system, woocommerce | L:required A:optional R:optional | `source:string`, `public_id:lic_*` | `normalization_profile:string` |
| `license_assigned` | administrator, api_credential, system, woocommerce | L:required A:optional R:optional | `order_id:int+`, `order_item_id:?int+` | none |
| `license_delivered` | administrator, api_credential, system, woocommerce | L:required A:optional R:optional | `order_id:int+`, `order_item_id:?int+` | none |
| `license_activated` | public_api | L:required A:none R:required | `activation_public_id:act_*` | none |
| `license_reactivated` | public_api | L:required A:none R:required | `activation_public_id:act_*` | none |
| `license_deactivated` | public_api | L:required A:none R:required | `activation_public_id:act_*` | none |
| `license_revealed` | customer | L:required A:required R:optional | `channel:enum(my_account)` | none |
| `license_exported` | administrator | L:none A:required R:optional | `full_keys:bool`, `row_count:int0+` | none |
| `license_suspended` | administrator | L:required A:required R:required | `from:string`, `to:string`, `reason:string` | none |
| `license_revoked` | administrator, api_credential | L:required A:optional R:optional | exactly one variant: lifecycle `from`, `to`, `reason`; or management API `changed_fields:list<string>` | none |
| `license_restored` | administrator | L:required A:required R:required | `from:string`, `to:string`, `reason:string` | none |
| `license_updated` | api_credential | L:required A:optional R:optional | `changed_fields:list<string>` | none |
| `license_extended` | administrator | L:required A:required R:required | `old_expiry:?UTC`, `new_expiry:UTC`, `extension_days:int+`, `reason:string` | none |
| `license_activations_reset` | administrator | L:required A:required R:required | `reset_count:int0+`, `reason:string` | none |
| `license_reassigned` | administrator | L:required A:required R:required | `before` and `after` exact license snapshots, `activation_reset:bool`, `reset_count:int0+`, `reason:string` | none |
| `license_reassignment_notified` | administrator | L:required A:required R:required | `customer_id:int+` | none |
| `license_reassignment_notification_failed` | administrator | L:required A:required R:required | `customer_id:int+` | none |
| `license_deleted` | administrator | L:required A:required R:required | `license_public_id:lic_*`, `reason:string` | none |
| `license_resent` | administrator | L:required A:required R:required | `order_id:int+`, `channel:enum(billing_email)` | none |
| `license_operation_rejected` | administrator | L:required A:required R:required | `operation:string` | none |

The `license_reassigned` snapshot has exactly `customer_id:?int+`, `order_id:?int+`, `product_public_id:?string`, and `lifecycle_status:string`.

## WooCommerce order events — schema v1

| Event type | Actors | References | Required metadata | Optional metadata |
| --- | --- | --- | --- | --- |
| `order_allocation_failed` | administrator, woocommerce | L:none A:optional R:required | `order_id:int+`, `order_item_id:int+`, `quantity_slot:int+`, `source:string` | none |
| `order_automatic_allocation_completed` | woocommerce | L:none A:none R:required | `order_id:int+`, `allocated:int+`, `existing:int0+`, `failed:int0+` | none |
| `order_explicit_allocation_completed` | administrator | L:none A:required R:required | same count fields as automatic allocation | none |
| `order_refunded` | woocommerce | L:none A:none R:required | `order_id:int+`, `refund_id:int+`, `licenses_affected:int0+`, `licenses_replayed:int0+`, `unmapped_items:int0+` | none |
| `order_quantity_changed_after_delivery` | woocommerce | L:required A:none R:optional | `order_id:int+`, `order_item_id:int+`, `delivery_quantity:int0+`, `requested_quantity:int0+`, `policy_result:enum(increase_requires_allocation,decrease_retains_licenses)` | none |
| `order_item_deleted_after_delivery` | woocommerce | L:required A:none R:optional | `order_id:int+`, `order_item_id:int+`, `license_history_retained:bool` | none |
| `order_refund_unmapped` | woocommerce | L:none A:none R:required | `order_id:int+`, `refund_id:int+`, `reason:string` | none |
| `order_refund_policy_applied` | woocommerce | L:required A:none R:required | `order_id:int+`, `refund_id:int0+`, `order_item_id:?int+`, `quantity_slot:?int+`, `configured_policy`, `effective_policy`, `before_status`, `after_status` strings | none |
| `order_cancellation_policy_applied` | woocommerce | L:required A:none R:required | same fields as refund policy application | none |

## Privacy event — schema v1

| Event type | Actors | References | Required metadata | Optional metadata |
| --- | --- | --- | --- | --- |
| `privacy_data_anonymized` | privacy_tool | L:none A:none R:optional | `license_count:int0+`, `claim_count:int0+`, `owner_count:int0+` | none |

## Legacy published names

Four names appeared in earlier documentation without a production emitter or frozen payload shape: `activation_failed`, `license_expired`, `license_imported`, and `license_renewed`. They remain recognizable as schema-v1 legacy catalog entries so stored history can be displayed and consumers can report `legacy_catalog_entry`. New persistence under these names is rejected. Imports use `license_created` with `source`; expiry is computed; renewal remains outside the implemented Free V1 surface.

## Compatibility and redaction

Consumers select on the pair `(event_type, schema_version)`, process required fields, and ignore documented optional fields they do not understand. Adding an optional field without changing existing meaning is compatible within v1. Removing/renaming a field, changing a type or meaning, changing actor/reference rules, or making an optional field required needs a new positive schema version plus migration and consumer documentation. Unsupported versions and unknown types remain readable with a compatibility status but must not be interpreted as a known payload.

Metadata is a closed allowlist at write time. Recursive redaction removes secret-bearing keys, nested objects/arrays, and high-confidence secret values before contract validation. This includes plaintext license keys, guest-claim proofs/hashes, credential secrets/verifiers, Authorization values, idempotency secrets, master keys, passwords, cookies, nonces, session tokens, sensitive bodies, secret-bearing URLs, raw network identity, and unnecessary email data. Reads redact metadata again, including legacy rows.

The event table is append-oriented operational evidence, not a cryptographic ledger. It does not claim tamper-proofing against a fully compromised WordPress installation or database administrator.
