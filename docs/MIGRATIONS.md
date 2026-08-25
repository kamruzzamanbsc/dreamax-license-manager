# Installation and migration verification

## Supported history

The stored plugin schema has three additive snapshots:

| Version | Historical source | Change |
| --- | --- | --- |
| 1 | `c7701de` | Seven original tables: licenses, activations, events, generators, API credentials, idempotency, and rate limits. |
| 2 | `3e0739f` | Adds site-local guest claims and order ownership. |
| 3 | `8e47d74` | Adds API credential secret version and rotation/revocation timestamps. |

Fresh installation now executes v1, v2, then v3. Existing installations execute every later snapshot in order. Each snapshot is a complete additive `dbDelta` definition, and the stored checkpoint advances only after that snapshot returns without a WordPress database error. A partial DDL failure can therefore leave already-added columns or tables, but the recorded version stays at the last completed checkpoint and rerunning is safe because every step is idempotent. MySQL DDL is not described as transactionally rollback-safe.

If a newer plugin has stored a schema version above 3, this version returns without applying older definitions or lowering the checkpoint. Downgrade is therefore non-destructive, but running older application code against newer data remains unsupported.

## Local source-contract verifier

```text
composer migration:verify
```

The verifier requires the exact disposable marker, uses only the committed schema-history fixture, renders each version twice, checks deterministic/idempotent statements and a multisite-style prefix, and reports `database_touched: false`. It never reads database credentials or connects to a database.

## Required live disposable acceptance procedure

Use a newly created WordPress test installation and MySQL database whose name contains `test`, `testing`, `tmp`, or `disposable`; use a `.test`, `.invalid`, or loopback site URL; define the unmistakable marker `DREAMAX_LM_DISPOSABLE_TEST`; and take a disposable snapshot before each scenario. Never point the procedure at production or reuse production credentials.

For fresh v3 and starting versions 1 and 2:

1. Install the exact historical schema fixture and synthetic rows containing opaque public IDs, encrypted ciphertext/fingerprints, activations, credential hashes and scopes, guest ownership records where supported, and audit rows including legacy/unsupported event versions.
2. Record row counts, non-secret field digests, table definitions, indexes, and the site prefix.
3. Activate the current plugin and invoke the upgrade twice.
4. Confirm the option is v3; all nine current InnoDB tables, columns, unique keys, and indexes exist; and the before/after non-secret digests and event payload bytes match.
5. Inject a failure during each version, confirm the checkpoint did not advance past the failed step, remove the fault, rerun, and confirm convergence without duplicate or lost data.
6. Repeat on a second multisite blog prefix and confirm no cross-prefix reads or writes.
7. Set a synthetic future version above 3, load this code without writes, and confirm neither schema nor stored version is lowered.

Production data must never be copied into or touched by this procedure. Until this matrix runs on real WordPress/MySQL, F02 remains `MANUAL_ENVIRONMENT_REQUIRED`.
