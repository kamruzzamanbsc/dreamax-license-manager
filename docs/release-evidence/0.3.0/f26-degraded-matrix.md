# F26 degraded-mode matrix evidence - 0.3.0

- Date: 2026-08-29
- Classification: `PASS_WITH_EVIDENCE`
- Environment: WordPress 7.1; WooCommerce 11.0.1; PHP 8.2.4; MariaDB 10.4.28; single-site disposable runtime; Dreamax License Manager 0.3.0
- Scope: plugin-storage outage; audit-storage outage; WordPress cron outage; upload temporary-storage failure; WooCommerce absence; missing/wrong master key; recovery signals; fail-closed API and mutation behavior; exact source preservation and cleanup
- Sensitive-data handling: no key material, license value, credential, Cookie value, nonce, request payload, private identifier, URL, database name, configuration content, or private path is retained.

## Guarded preflight and isolation

The verifier required the explicit disposable-environment marker, a local test database, active WordPress/WooCommerce/Dreamax components, all nine authoritative plugin tables on InnoDB, a scheduled bounded cleanup job, and zero earlier F26 database or clone residue. It recorded only value-free database/configuration digests and fixed booleans.

Only after that diagnosis passed, the verifier created one randomly named private file clone and one randomly named verifier-owned database clone. Fault injection was confined to those targets. The original WordPress files, configuration, and database were read-only inputs.

## Live fault matrix

The clone-only run demonstrated:

- complete plugin-storage loss produced a critical generic Site Health signal, made ping and public validation return `503 server_unavailable`, and blocked mutation;
- audit-table loss produced the same critical storage signal, blocked a required-audit mutation, rolled its license insert back, and made the public API unavailable;
- removing the WordPress cron cleanup event produced a recommended recovery signal while ping and synchronous core license creation remained functional; the exact temporary fixture was removed and the schedule was restored;
- an unavailable upload temporary file rejected import before any license row was written while ping remained functional and streamed export architecture was unaffected;
- removing WooCommerce from clone-only active-plugin resolution failed the dependency check closed, displayed the generic dependency notice, and left Dreamax public routes unregistered; and
- restoring every held table, the cleanup schedule, dependency configuration, and normal clone configuration returned encryption, storage, cleanup, and dependency checks to Ready.

F11 already provides the remaining missing-key and wrong-key fault states: protected operations were blocked, public requests returned a uniform 503 before key-dependent work, authenticated recovery signals appeared, ciphertext and key metadata remained unchanged, and restoring the exact key returned the clone to Ready. F12 independently verifies complete database-plus-key recovery.

## Implementation corrections

The drill added generic Site Health checks for authoritative plugin storage and the bounded cleanup schedule. Public ping now uses the same encryption-and-storage readiness boundary as licensing operations. The operational matrix was corrected to describe the shipped WordPress Cron and streamed-export architecture rather than Action Scheduler or file-backed export.

## Cleanup and source preservation

Each held table was restored before the recovery baseline. The final run removed the exact random database and private clone. Value-free before/after digests confirmed that the source database and source configuration remained unchanged, and a post-run diagnosis found zero F26 database or clone residue. No outbound email was sent.

## Regression verification

- PHPUnit: 241 tests and 1,492 assertions passed.
- WordPress coding standards passed.
- PHPStan completed without errors.
- Composer validation and release metadata validation passed.
- `git diff --check` passed.
- The gate ledger recalculated to 29 `PASS_WITH_EVIDENCE`, 4 `MANUAL_ENVIRONMENT_REQUIRED`, 0 `AUTOMATABLE_PENDING`, and 0 `IMPLEMENTATION_BLOCKER`.

## Result

All seven published degraded states now have concrete guarded evidence, every tested state failed closed with an appropriate recovery signal, normal service recovered, and exact cleanup completed. F26 is `PASS_WITH_EVIDENCE`.
