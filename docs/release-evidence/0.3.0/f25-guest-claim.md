# F25 guest-order claim evidence - 0.3.0

- Date: 2026-08-29
- Classification: `PASS_WITH_EVIDENCE`
- Environment: WordPress 7.1; WooCommerce 11.0.1; PHP 8.2.4; MariaDB 10.4.28; HPOS enabled; Dreamax License Manager 0.3.0; loopback-only Mailpit
- Scope: guest-order eligibility; local email delivery; proof storage and disclosure; uniform failure behavior; replay and parallel-worker consumption; expiry and ownership invalidation; administrator release/override; audit redaction; exact cleanup
- Sensitive-data handling: no claim proof/hash, license value, customer email, credential, Cookie value, nonce, request payload, private identifier, URL, database name, or private path is retained.

## Implemented controls

- Authenticated nonce-protected POST issuance and verification; no claim proof is accepted from a URL.
- The billing email supplied by the signed-in customer must match both the account and the authoritative paid guest order; email knowledge alone is insufficient.
- Each proof contains 32 random bytes and is stored only as a purpose-separated keyed hash, with bounded lifetime, unique active-claim storage, row locking, guarded one-row consumption, and hash clearing.
- Separate keyed user, network, and order rate-limit buckets protect issuance and verification.
- Verification failures use one stable public shape while versioned audit events retain only sanitized classifications.
- Ownership changes invalidate outstanding proofs; administrator release and override use WooCommerce CRUD for HPOS compatibility.
- Privacy export and erasure handling covers claim and owner records.

## Guarded live verification

The verifier required the explicit disposable-environment marker, the active plugin, InnoDB for every touched table, a clean owned-fixture baseline, and a loopback-only mail catcher. It created temporary customers, one licensed virtual product, and three paid guest orders.

The live run demonstrated:

- a request based only on another account's matching billing email disclosed nothing and sent no message;
- three eligible requests each produced exactly one locally captured message for the authoritative recipient;
- the message contained one valid one-time proof and no URL-based proof;
- wrong-account, wrong-order, and replay attempts returned the same public failure shape;
- two worker processes were simultaneously in flight against one claim and exactly one completed it;
- the successful claim consumed the proof once, cleared its stored hash, linked the owner row, order, and assigned license, and rejected replay;
- an expired proof failed closed and cleared its stored hash;
- an authoritative billing-email change invalidated the outstanding proof;
- administrator release followed by override produced the expected final ownership; and
- all claim audit payloads excluded proof, billing email, license value, and authorization material.

The run removed every verifier-owned claim, owner, order, product, license, event, rate-limit change, user, barrier, and captured message. Plugin-table aggregates matched the starting snapshot and zero owned fixture rows remained. No external email was sent.

## Related release evidence

The complete acceptance picture also includes the real migration/replay matrix in F02, proxy and rate-limit abuse checks in F15, HPOS/classic-storage parity in F20, and repeated privacy export/erasure behavior in F27. Source contracts cover the authenticated nonce-protected actions, proof transport boundary, guarded worker inputs, cleanup ownership, and sanitized output.

## Regression verification

- PHPUnit: 235 tests and 1,457 assertions passed.
- WordPress coding standards passed.
- PHPStan completed without errors.
- Composer validation and release metadata validation passed.
- `git diff --check` passed.

## Result

The guarded live WordPress/WooCommerce/InnoDB/mail/parallel-worker contract passed with exact cleanup. F25 is `PASS_WITH_EVIDENCE`.
