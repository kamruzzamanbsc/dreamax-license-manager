# F31 privileged credential lifecycle evidence — 0.3.0

- Date: 2026-08-25
- Classification: `MANUAL_ENVIRONMENT_REQUIRED`
- Scope: source, local unit/static contract, coding-standard, static-analysis, syntax, portable-autoload, and secret-scan evidence
- Secret handling: this artifact contains no Bearer value, secret, stored credential verifier, Authorization header, private key, license key, customer data, or request body.

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
- Transactional immediate, idempotent, terminal revocation that replaces the old verifier and creates no duplicate event.
- Version-1 created/use/rotation-success/rotation-failure/revoked/expired/scope audit events and recursive credential/verifier/body redaction.
- Additive schema v3 migration and confirmed permanent-uninstall coverage.

## Local automated evidence

The final branch run passed: optimized Composer autoload generated 1,601 classes without ambiguity; Composer validation and WordPress PHPCS passed; PHPStan passed 48/48 files; PHPUnit passed 98/98 tests with 221 assertions; PHP syntax passed 50/50 production files; portable autoload passed 48/48 production classes; the rename verifier passed all 41 main-baseline identities and seven F25/F31 additions; `diff --check` passed; and a content-free scan of 30 intended files found no high-confidence secret or prohibited artifact path. Focused tests cover exact entropy-derived lengths and syntax, correct/wrong verifier behavior, malformed/multiple/control/overlong headers, body credential detection, uniform failure policy, scope catalog, expiration boundaries, zero-overlap/stale-version behavior, repeated revocation, last-used coalescing, per-credential rate policy, capability isolation, shared use/mutation locking, audit/redaction, site-local storage/locks, uninstall, and frozen-v1 source compatibility.

## Remaining live evidence

F31 is not PASS. A disposable integration environment must still demonstrate:

- schema v2-to-v3 migration twice against real MySQL/InnoDB with existing credentials;
- real WordPress REST handling for missing, duplicate raw, proxy-forwarded, malformed, body, and query credentials;
- exact administrator/Shop Manager/custom-role page and nonce boundaries;
- successful calls for every allowed scope and denials for every wrong scope;
- concurrent in-flight API use versus rotation/revocation and concurrent double rotation across workers;
- transaction/audit failure injection proving the old verifier survives failed rotation;
- expiration boundary, per-credential throttling, and five-minute last-used write behavior under HTTP load;
- two-site credential, lock, rate-bucket, audit, and uninstall isolation;
- audit/support/log inspection confirming no secret, verifier, Authorization header, or sensitive body.

F32's central published event contract and consumer compatibility work is implemented separately; its remaining live-environment evidence is recorded in `f32-audit-event-contract.md`.
