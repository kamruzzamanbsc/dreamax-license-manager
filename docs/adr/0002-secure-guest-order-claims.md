# ADR 0002: Secure guest-order account claims

Status: accepted for development; live integration evidence pending
Date: 2026-08-25

## Context

Guest WooCommerce orders initially have no WordPress customer owner. An email address or order number is discoverable information and cannot authorize license disclosure or ownership transfer. The claim workflow must preserve WooCommerce HPOS compatibility, the frozen Free V1 secrecy rules, transactional license ownership, privacy erasure, and versioned audit evidence.

WooCommerce remains authoritative for order identity, billing email, order key, customer ID, payment state, and its verified order-received/order-details context. Dreamax does not query WordPress posts or WooCommerce storage tables for these values.

## Decision

Schema version 2 adds two site-local InnoDB tables through the existing idempotent `dbDelta` installer:

- `dreamax_lm_guest_claims` stores claim public ID, order and target account references, status/times, an ownership snapshot, and only a 32-byte keyed token hash. `active_order_id` is nullable and unique, so at most one pending proof exists per order. Plaintext proof is never persisted.
- `dreamax_lm_order_owners` has `order_id` as its primary key. It is the final database uniqueness guard that permits only one claimed account per order. It stores no email or plaintext proof.

The flow is:

1. An authenticated customer submits an order number and billing email over HTTPS with a WordPress nonce.
2. Responses remain generic. Only a paid guest order with Dreamax licenses whose authoritative billing email exactly matches both the submitted address and authenticated account email receives mail.
3. The service generates 32 random bytes, encodes them as a 43-character base64url code, stores only a purpose-separated keyed hash, and emails the plaintext code without a claim URL.
4. The default lifetime is 30 minutes. The accepted configuration range is five minutes through the hard maximum of 24 hours.
5. Verification is an authenticated nonce-protected POST. Issuance and verification consume separate keyed user, network, and order rate-limit buckets.
6. The transaction locks the claim, unique owner, and every order license row. It rechecks target account, expiry, keyed token hash, and the authoritative order ownership snapshot; changes the WooCommerce customer through `WC_Order` CRUD; updates license owners; inserts the unique owner; clears the hash; and consumes the claim exactly once.
7. Successful claim, authoritative order ownership change, administrator release/override, privacy erasure, expiry, and reissuance invalidate applicable outstanding proofs.

Guest order display remains inside WooCommerce's verified order hooks and additionally requires `WC_Order::key_is_valid()` for guest orders. Registered orders require the matching authenticated account. Billing email is never an input to license display authorization.

Administrator release and override reuse `dreamax_lm_manage_licenses`, require a nonce and explicit confirmation, update the WooCommerce order through CRUD, update all associated license rows transactionally, invalidate proofs, and append versioned audit events.

## Privacy and audit

Privacy export includes claim public ID, order ID, status, and timestamps, never token or ownership hashes. Erasure removes the direct target-account link, clears proof material, invalidates outstanding claims, and removes the claimed-owner row while retaining minimal order/license/audit integrity.

Audit types are `guest_claim_issued`, `guest_claim_succeeded`, `guest_claim_replay_failed`, `guest_claim_expired_failed`, `guest_claim_conflict_failed`, `guest_claim_released`, and `guest_claim_administrator_override`, all at schema version 1. Recursive audit sanitization rejects token/code/key/secret fields.

## Consequences and evidence limit

The migration is additive and does not change frozen public REST, key, activation, order-slot, or lifecycle meanings. A real WordPress/WooCommerce/InnoDB/mail environment is still required to prove migration replay, HPOS/classic parity, email delivery, concurrent two-account races, hook ordering, privacy-tool orchestration, and administrator authorization. F25 therefore moves from implementation blocker to manual-environment-required, not PASS.

## Rejected alternatives

- Email or order-number knowledge as proof: enumerable and insufficient.
- Token in a URL: leaks through browser history, referrers, proxies, and server logs.
- Plaintext or reversibly encrypted claim token storage: unnecessary breach impact.
- WordPress transients as the authoritative claim store: no durable uniqueness or row-lock guarantee.
- Direct WooCommerce table updates: incompatible with HPOS and WooCommerce data-store behavior.
