# F25 guest-order claim evidence — 0.3.0

- Date: 2026-08-25
- Classification: `MANUAL_ENVIRONMENT_REQUIRED`
- Scope: source, local unit/static contract, coding-standard, static-analysis, syntax, and secret-scan evidence
- Secret handling: this artifact contains no claim code/hash, license key, credential, customer email, private key, or request payload.

## Implemented controls

- WooCommerce verified order hook context plus `WC_Order::key_is_valid()` gates guest display; registered display requires the matching authenticated account.
- Authenticated nonce-protected POST issuance and verification; no claim proof is accepted from a URL.
- 32 random bytes per code; purpose-separated keyed hash only; 30-minute default; five-minute minimum; 24-hour hard maximum.
- Unique active claim per order, unique claimed owner per order, claim/owner/license row locks, guarded one-row consumption, and proof-hash clearing.
- Separate keyed user/network/order rate-limit buckets for issuance and verification.
- Uniform public issuance response and uniform verification failure shape.
- Authoritative ownership-change, success, expiry, reissue, privacy, release, and override invalidation paths.
- WooCommerce CRUD-only order ownership changes for HPOS compatibility.
- Versioned claim audit events with recursive secret-field removal.
- Claim privacy export and erasure/anonymization handling.

## Local automated evidence

On 2026-08-25, the required branch QA produced:

- optimized Composer autoload: exit 0, 1,596 generated classes, no ambiguity;
- Composer validation: exit 0;
- full WordPress PHPCS: exit 0, no errors or warnings;
- PHPStan: exit 0, 46/46 source files, no errors;
- PHPUnit: exit 0, 66/66 tests and 122 assertions;
- production PHP syntax: exit 0, 48/48 files;
- portable Composer/runtime autoload verification: exit 0, 46/46 production classes;
- main-baseline rename/addition verification: exit 0, 41 baseline identities plus 5 F25 classes;
- high-confidence secret-signature scan: exit 0, 38/38 intended changed files, no finding and no prohibited changed path;
- `git diff --check`: exit 0.

The configured tests cover guest display authorization, email-only insufficiency, authenticated account-email binding, authenticated success policy, wrong-account/order conflict, expiry, replay/single-use state, token entropy/format, keyed-hash-only schema, lifetime bounds, rate policies, uniform failure shapes, ownership invalidation, administrator action policy, database concurrency constraints, HPOS CRUD source contract, privacy handling, and audit redaction.

## Remaining live evidence

F25 is not PASS. A disposable integration environment must still demonstrate:

- schema v1→v2 migration twice against real MySQL/InnoDB;
- guest checkout/order-received display with current WooCommerce session, order-key, and email-verification behavior;
- mail capture showing one code, no URL proof, correct recipient, and expiry messaging;
- successful, wrong-account, wrong-order, expired, replay, ownership-change, release, and override requests through real nonces/roles;
- simultaneous two-account verification against the same order under classic storage and HPOS;
- issuance/verification rate limits through HTTP and trusted/untrusted proxy cases;
- privacy export/erasure ordering with WooCommerce's erasers;
- audit rows confirming no proof, billing email, raw network, or full license key.
