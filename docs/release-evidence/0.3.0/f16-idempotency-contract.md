# F16 idempotency evidence — 0.3.0

- Date: 2026-08-27
- Classification: `PASS_WITH_EVIDENCE`
- Environment: WordPress 7.1; WooCommerce 11.0.1; PHP 8.2.4; MariaDB 10.4.28; HPOS enabled; Dreamax License Manager 0.3.0
- Scope: public v1 replay/conflict behavior, bounded secret-free persistence, scheduled expiry cleanup, and exact fixture removal
- Sensitive-data handling: no license, idempotency, scope, digest, product, installation, activation, request, customer, Cookie, nonce, credential, URL, database, or private-path value is retained.

## Existing live HTTP evidence

The unchanged committed frozen v1 client previously exercised the disposable public REST service over loopback HTTP. Its activation request completed, the same request and idempotency value replayed successfully, and the same idempotency value with changed request data returned `idempotency_conflict`. The client then validated and deactivated the same owned fixture. The pre/post client hash matched and exact fixture, idempotency-result, and known rate-bucket cleanup restored the initial aggregate state.

## Guarded live repository and cleanup verification

The F16 verifier required the explicit disposable marker, test database and local site guards, the active plugin, the scheduled cleanup event, and InnoDB for every table touched by the cleanup callback. Before invoking cleanup it required zero unrelated expired idempotency, rate-limit, or pending guest-claim candidates, so the callback could not remove or invalidate pre-existing data.

Using generated synthetic inputs retained only in memory, the live repository verifier established that:

- the first reservation was new and the completed reservation replayed the exact status, code, and bounded result;
- the same caller value and scope with changed canonical request data returned HTTP 409 `idempotency_conflict`;
- the persisted row contained only API/operation state, binary scope and payload hashes, and allowlisted result fields—not the raw caller value, synthetic license input, or excluded synthetic sentinel;
- the scheduled bounded cleanup callback deleted the exact expired owned row while preserving an exact future owned row; and
- the future owned row was removed by exact keyed scope during final cleanup, restoring the starting idempotency aggregate.

The verifier zeroed the in-memory synthetic caller/license/sentinel strings when supported and emitted only fixed labels, booleans, and environment classifications.

## Automated regression coverage

Source-contract coverage confirms the visible-ASCII length boundary, keyed license/scope hashing, digest conflict comparison, bounded result allowlist, bounded 500-row cleanup query, scheduled callback, pre-existing-candidate guard, exact owned-scope cleanup, and sensitive-output contract.

## Result

The public HTTP flow and guarded live repository drill jointly verify exact replay, changed-payload conflict, secret-free bounded persistence, expiry cleanup, future-row preservation, and exact restoration. F16 is `PASS_WITH_EVIDENCE`.
