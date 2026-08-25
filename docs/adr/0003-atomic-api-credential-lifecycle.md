# ADR 0003: Atomic privileged API credential lifecycle

Status: accepted for development; live integration evidence pending

Date: 2026-08-25

## Context

ADR 0001 freezes the privileged Bearer format, entropy, scopes, HTTPS boundary, request limits, and capability separation. F31 requires creation, authentication, rotation, expiration, and revocation without changing that wire protocol or exposing secret material. Rotation and revocation must also serialize with privileged business use despite those business callbacks owning their own transactions.

## Decision

Schema version 3 additively extends each site-local `dreamax_lm_api_credentials` row with `secret_version`, `rotated_at`, and `revoked_at`. Existing rows migrate to secret version 1. The existing public ID, name, visible prefix, modern password verifier, scopes, status, expiration, and use timestamps remain authoritative. No plaintext or reversible secret is added.

Creation generates a 16-byte public ID and 32-byte secret and returns the formatted Bearer value once. Authentication strictly parses one exact header, performs a real or dummy password-verifier operation, and gives unknown, malformed, wrong, expired, and revoked credentials one public failure envelope. The safe authenticated record omits `secret_hash`.

Every privileged use, rotation, and revocation takes the same bounded MySQL advisory lock derived from the current blog ID, blog table prefix, and public credential ID. The lock is site-local and contains no secret. API use re-reads status, expiration, and authenticated secret version while holding the lock, then enforces a per-credential rate bucket and exact scope before invoking business work. Rotation or revocation therefore either waits for an earlier use to finish or commits before a later use rechecks and fails closed.

Rotation also takes `SELECT ... FOR UPDATE` inside an InnoDB transaction and requires the actor-observed `secret_version`. It replaces the verifier and increments the version in the same transaction as the success audit event. There is no grace period. Concurrent/double submissions with a stale version fail without changing the current credential; transaction failure leaves the old verifier valid. Name, scopes, and expiration are unchanged unless an authorized caller explicitly supplies those fields.

Revocation takes the advisory lock and row lock, replaces the old verifier with a verifier for an unrevealed random value, changes active state to terminal `revoked`, increments the secret version, records `revoked_at`, and appends one audit event transactionally. A repeated revocation returns without another update or event. Rotation refuses revoked rows; recovery requires creation of a new credential.

`last_used_at` is conditionally written no more than once per 300-second window. This bounds timestamp writes but does not suppress version-1 authentication-use audit events. Credential inventory queries explicitly omit verifiers.

## Security and audit

Nonce-protected WordPress POST actions use only `dreamax_lm_manage_api_credentials`, which Shop Managers do not receive by default. Credential-bearing URLs and bodies, multiple/comma-joined headers, controls, non-Bearer forms, and overlong headers are rejected before business processing. Production HTTPS and the frozen trusted-proxy/loopback policy remain unchanged.

Schema-version-1 events are `credential_created`, `credential_authentication_used`, `credential_rotation_succeeded`, `credential_rotation_failed`, `credential_revoked`, `credential_expired_authentication_failed`, and `credential_insufficient_scope`. Metadata may include the non-secret public ID, required scopes, versions, and bounded outcomes. Recursive sanitization removes credential values, Authorization, secrets, verifiers, request bodies, tokens, and keys.

## Consequences and evidence limit

The frozen REST namespace, routes, envelope, scope names, and Bearer syntax do not change. MySQL advisory locks are intentionally held across the privileged callback so revocation has an unambiguous ordering relative to business use; a five-second lock-acquisition timeout fails unavailable without processing business work.

Real WordPress REST, web-server header handling, MySQL concurrency, proxy, role/nonce, expiration, rate-limit, and multisite tests remain required. F31 therefore moves from implementation blocker to manual-environment-required, not PASS. ADR 0004 and the central audit catalog now govern the event payloads introduced here; F32 retains live-environment acceptance requirements.

## Rejected alternatives

- Parallel replacement rows with an overlap window: creates more concurrency states and conflicts with the safest zero-overlap default.
- Rotation without an expected version: concurrent submissions could return immediately stale replacement secrets.
- Transients or object-cache locks: do not provide authoritative cross-worker exclusion.
- Holding only an InnoDB transaction across API business callbacks: nested service transactions would break transaction ownership.
- Returning or decrypting stored secrets: no plaintext or reversible secret exists to retrieve.
