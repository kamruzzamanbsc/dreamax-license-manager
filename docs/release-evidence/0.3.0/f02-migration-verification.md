# F02 installation and migration evidence - 0.3.0

- Date: 2026-08-28
- Classification: `PASS_WITH_EVIDENCE`
- Environment: WordPress 7.1; PHP 8.2.4; MariaDB 10.4.28; single-site runtime with isolated second-site-style prefix; Dreamax License Manager 0.3.0
- Scope: historical schema inventory, fresh current installation, v1/v2 upgrades, replay, fault recovery, data/index preservation, prefix isolation, future-version refusal, and exact cleanup
- Sensitive-data handling: only opaque synthetic fixtures were used; no key, credential, configuration value, database value, private identifier, Cookie value, nonce, secret, screenshot, URL, or private path is retained.

## Source and unit contracts

The executable history contains v1 with seven tables, v2 with two additional ownership tables, and v3 with credential lifecycle columns. The source verifier rendered every complete additive snapshot twice, confirmed deterministic statements and InnoDB declarations, and matched the committed history fixture. Unit contracts cover required sensitive-data columns, public identities, unique constraints, indexes, sequential checkpoint ordering, unsafe prefix/version rejection, and future-version downgrade refusal.

## Guarded real-MariaDB matrix

The live verifier required the explicit disposable-environment marker, active Dreamax runtime, zero fixed-prefix residue, and exact safe cleanup targets. It used four isolated clean WordPress prefixes and suppressed expected database errors only while injecting controlled migration faults.

All 10 recorded live contracts passed:

- fresh v3 installation and a second replay converged on the current schema;
- v1-to-v3 and v2-to-v3 upgrades preserved exact internal digests of every column that existed at each starting checkpoint, including opaque ciphertext/fingerprints, credential hashes/scopes, ownership rows, and event payload bytes;
- every current plugin table used InnoDB and exposed the required v3 columns and unique/non-unique indexes;
- controlled failures during v1, v2, and v3 left the stored checkpoint at the last completed version;
- removing each verifier-owned fault and replaying the upgrade converged without duplicate or lost fixture rows;
- two simultaneous site-style prefixes retained distinct fixtures with zero cross-prefix reads; and
- a synthetic future version preserved both its stored version and an exact digest of current table definitions and row counts.

## Cleanup proof

The matrix created 84 temporary core/plugin tables or controlled fault views across the four fixed verifier prefixes. Exact prefix-scoped cleanup removed every object. Existing Dreamax table aggregates matched before and after the run, no outbound email was sent, and a final read-only diagnosis reported zero verifier-owned residue.

## Result

Every released schema checkpoint is repeatable, data-preserving, recoverable after partial DDL failure, prefix-isolated, and downgrade-safe on the recorded real MariaDB runtime. Actual multisite network lifecycle acceptance remains under F30. F02 is `PASS_WITH_EVIDENCE`.
