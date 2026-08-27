# F08 lifecycle/API matrix evidence — 0.3.0

- Date: 2026-08-27
- Classification: `PASS_WITH_EVIDENCE`
- Environment: WordPress 7.1; WooCommerce 11.0.1; PHP 8.2.4; MariaDB 10.4.28; HPOS enabled; Dreamax License Manager 0.3.0
- Scope: complete state/expiry unit contracts plus guarded public v1 REST lifecycle outcomes
- Sensitive-data handling: no license, product, installation, activation, request, rate-bucket, customer, Cookie, nonce, credential, URL, database, or private-path value is retained.

## Implementation gaps resolved

The source review found and corrected two public lifecycle gaps before evidence was promoted:

- an absent optional `Idempotency-Key` header could be returned as `null` by internal REST dispatch and reach the strictly typed repository, producing a generic 503 instead of the intended lifecycle result; the header is now normalized to a string before the optional reservation branch; and
- the documented `product_mismatch` result had no emitting path because the original lookup was scoped to the requested product; a bounded keyed-fingerprint lookup now distinguishes a known key assigned to another product without querying or storing the presented key.

Regression coverage fixes both contracts. Unknown keys continue to return `invalid_license`; only a keyed fingerprint match under another product produces `product_mismatch`.

## Guarded live REST verification

The verifier required the explicit disposable marker, local/test environment guard, active plugin, exactly one qualifying synthetic product, and InnoDB for every license, activation, event, idempotency, and rate-limit table involved. It created five owned generated licenses for assigned, suspended, revoked, expired, and activation-disabled states.

Seventeen WordPress REST outcomes were dispatched and verified:

- system availability;
- invalid request and unknown license rejection;
- known-license product mismatch;
- suspended, revoked, and expired rejection;
- activation-disabled and exhausted-limit rejection;
- initial activation and same-installation replay;
- active validation and status;
- missing-activation rejection;
- initial deactivation and inactive replay; and
- validation after deactivation.

Every response was checked for the stable success/code/status contract, the complete versioned envelope, a valid server timestamp, private/no-store headers, and absence of every synthetic presented key. Existing F16 evidence separately covers caller-key idempotent replay, changed-payload conflict, bounded persistence, and expiry cleanup.

The verifier snapshotted every known rate bucket before first use, restored its exact prior row or removed only its newly created row, removed activation and event rows by the exact owned license IDs, removed each owned license by ID plus public identity, zeroed in-memory synthetic keys when supported, and proved the final aggregate matched the starting database state.

## Automated matrix coverage

The complete state-machine unit matrix covers every pair across available, assigned, suspended, and revoked states. Expiry-policy tests cover active and already-expired extension bases, lifetime protection, and duration boundaries. Source-contract tests cover the keyed product-mismatch lookup, stable lifecycle codes, and optional-header normalization.

## Result

The unit and guarded live REST suites jointly verify the allowed and rejected Free V1 lifecycle/API matrix. F08 is `PASS_WITH_EVIDENCE`.
