# F31 privileged credential lifecycle evidence — 0.3.0

- Date: 2026-08-25
- Updated: 2026-08-29
- Classification: `PASS_WITH_EVIDENCE`
- Scope: source, unit/static contracts, live administrator POST replay, real WordPress REST/HTTP/InnoDB, parallel lifecycle workers, migration, capability, and multisite evidence
- Secret handling: this artifact contains no Bearer value, secret, stored credential verifier, Authorization header, operation token, nonce, public credential ID, private key, license key, customer data, or request body.

## Implemented controls

- Frozen `Bearer dlm_v1_<22-character-public-id>.<43-character-secret>` format using 16-byte and 32-byte random inputs.
- Modern password verifier only; plaintext returned only by creation/rotation and absent from inventory, audit, exceptions, logs, and persisted schema.
- Uniform unknown/malformed/wrong/expired/revoked authentication envelope and dummy verification for absent records.
- Credential proof rejected from query parameters and request bodies; strict header count/syntax/control/length handling.
- Production HTTPS with trusted-proxy validation and only the explicit loopback development exception.
- Exact frozen scope enforcement after authentication, dedicated credential capability, and Shop Manager default denial.
- Site-local per-credential rate bucket and conditional five-minute `last_used_at` write coalescing.
- Site-local MySQL advisory lock shared by API use, rotation, and revocation, with a fresh status/expiry/version recheck before business processing.
- Transactional row/version-guarded rotation with zero overlap, preserved attributes by default, one returned replacement secret, and rollback-safe failure.
- User/action/context-bound one-time administrator operation tokens plus an atomic site-local reservation prevent refreshed or concurrent create/rotation POSTs from repeating the mutation; replay responses contain no mutation result.
- Transactional immediate, idempotent, terminal revocation that replaces the old verifier and creates no duplicate event.
- Version-1 created/use/rotation-success/rotation-failure/revoked/expired/scope audit events and recursive credential/verifier/body redaction.
- Additive schema v3 migration and confirmed permanent-uninstall coverage.

## Local automated evidence

The final branch run passed: optimized Composer autoload generated 1,601 classes without ambiguity; Composer validation and WordPress PHPCS passed; PHPStan passed 48/48 files; PHPUnit passed 98/98 tests with 221 assertions; PHP syntax passed 50/50 production files; portable autoload passed 48/48 production classes; the rename verifier passed all 41 main-baseline identities and seven F25/F31 additions; `diff --check` passed; and a content-free scan of 30 intended files found no high-confidence secret or prohibited artifact path. Focused tests cover exact entropy-derived lengths and syntax, correct/wrong verifier behavior, malformed/multiple/control/overlong headers, body credential detection, uniform failure policy, scope catalog, expiration boundaries, zero-overlap/stale-version behavior, repeated revocation, last-used coalescing, per-credential rate policy, capability isolation, shared use/mutation locking, audit/redaction, site-local storage/locks, uninstall, and frozen-v1 source compatibility.

## Completed live integration evidence

The remaining F31 matrix was completed without exposing or retaining any credential value, verifier, Authorization value, private identifier, or private path:

- F02's guarded MariaDB migration matrix supplies fresh/replayed v3 plus v2-to-v3 preservation and replay for existing credential rows.
- F10 supplies administrator, Shop Manager, customer, dedicated-capability, and actor-bound nonce isolation.
- The guarded F31 runner exercised 23 real loopback REST/HTTP requests. All four allowed scopes succeeded and four wrong-scope combinations were denied.
- Missing, malformed, unknown, revoked, and expired authentication produced the uniform public failure contract. Two raw Authorization fields, credential-shaped query/body input, and an untrusted forwarded-protocol claim were rejected; the explicitly trusted loopback proxy path succeeded.
- One normal per-credential request succeeded, a verifier-owned bucket was moved to a controlled depleted state, and the next request returned the exact throttling response. Last-used writes were suppressed inside the five-minute window and resumed outside it.
- Injected audit persistence failure preserved the old verifier and version exactly. Two simultaneous rotations, in-flight use versus rotation, and in-flight use versus revocation were serialized across six parallel workers.
- Thirty-six verifier-owned audit rows matched their catalog types and contained no credential, verifier, Authorization, request-body, or protected fixture value.
- F29/F30 supplies independent multisite credential creation/use, lock/rate/audit ownership, API selection, and permanent-uninstall isolation.
- The runner restored the temporary local Authorization-forwarding configuration byte-for-byte, restored proxy and loopback options, removed every owned credential/license/event/rate fixture, left aggregate counts unchanged, and sent no outbound email.

## Completion branch verification

The completed F31 branch passed 252 PHPUnit tests with 1,557 assertions, full WordPress PHPCS, PHPStan across 55 source files, Composer validation, and release metadata validation. The added source contract covers the live verifier's HTTP scope/transport, rate/coalescing, audit rollback, parallel lifecycle, cleanup, and sensitive-output boundaries. Whitespace and non-content secret/private-path checks are required again immediately before commit.

F32's independent central event contract and consumer compatibility matrix subsequently completed with guarded live evidence recorded in `f32-audit-event-contract.md`.

## Disposable runtime defect evidence

Before this correction, refreshing one successful credential-creation POST response repeatedly created seven active credential rows from the same submitted form. Fresh navigation to the credential inventory revealed no stored plaintext, confirming duplicate creation rather than secret recovery. All seven disposable test credentials were later revoked through the existing idempotent UI workflow. This evidence contains counts and state only; it contains no credential, verifier, nonce, operation value, identifier, or request content.

## Focused replay correction automated evidence

The final focused branch run generated 1,615 optimized Composer classes without ambiguity; Composer validation and full WordPress PHPCS passed; PHPStan passed 55/55 source files; PHPUnit passed 162 tests with 1,008 assertions; production syntax passed 57/57 PHP files; portable autoload passed 55 production symbols; and the rename verifier passed all 41 main-baseline identities plus 14 intentional additions. Version metadata remains `0.3.0`. Diff checks and the non-content secret/private-path/prohibited-artifact scan passed with no finding. These local results do not replace the live retest or promote F31 to PASS.

## Live administrator POST replay verification

- Environment: WordPress 7.1; WooCommerce 11.0.1; PHP 8.2.4; MariaDB 10.4.28; HPOS order datastore enabled; Dreamax License Manager 0.3.0.
- Replacement artifact: source commit `8fde24d16069b22be01ee5bbc3e6761b3413bbc5`; ZIP SHA-256 `ae80876ba7dceda29c0c2c8a3bef0da9e19113c8b0dfc9967a760574ba18b84f`.
- Creation: exactly one disposable credential named `Replay Fix Test` was created with only `licenses:read` and no expiration. Its plaintext appeared only on the first successful response. Three refresh/resubmission attempts each returned only “This credential operation was already processed or expired. Return to API credentials and start a new operation.” and displayed no credential data. Fresh inventory navigation showed exactly one matching row at secret version 1.
- Rotation: the credential was rotated once with immediate invalidation selected. Its replacement plaintext appeared only on the first successful response. Three refresh/resubmission attempts returned the same generic response and displayed no credential data. Fresh inventory navigation still showed exactly one matching row, at secret version 2.
- Audit: exactly one `credential_created` and one `credential_rotation_succeeded` event belonged to this test. Rotation metadata recorded `previous_version` 1 and `new_version` 2. No plaintext credential or other sensitive value appeared in audit metadata.
- Cleanup: the disposable credential was permanently revoked, ending in status `revoked` and displayed secret version 3. Previously revoked `Local Read Test` rows were unrelated earlier disposable records and were excluded from the replay-fix counts.

The administrator create/rotation POST replay sub-gate remains valid preserved evidence. Together with the completed integration, migration, capability, and multisite matrices above, F31 is `PASS_WITH_EVIDENCE`.
