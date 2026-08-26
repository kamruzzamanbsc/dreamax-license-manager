# F31 privileged credential lifecycle evidence — 0.3.0

- Date: 2026-08-25
- Updated: 2026-08-26
- Classification: `MANUAL_ENVIRONMENT_REQUIRED`
- Scope: source, local unit/static contract, coding-standard, static-analysis, syntax, portable-autoload, secret-scan, and live administrator POST-replay evidence
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

The administrator create/rotation POST replay sub-gate is verified by this limited live test. Overall F31 remains `MANUAL_ENVIRONMENT_REQUIRED`; the separate checks listed under Remaining live evidence are still open.
