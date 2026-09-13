# Queued CSV import acceptance

Date: 2026-09-14. Unreleased `feature/free-v1-completion` branch. Disposable local WordPress 7.1 / WooCommerce 11.0.1 / PHP 8.2.12 / MariaDB 10.4.28.

Command: `wp eval-file scripts/verify-live-queued-csv-import.php` against the guarded `affiliates-test` site.

Observed result: a private 501-row fixture exceeded the 256 KiB asynchronous threshold and processed in exact 250, 250 and 1-row batches. Persisted byte offsets and row counters advanced between batches; the opaque status remained accessible only to its administrator owner. A pre-owned token lock prevented a second worker from changing offset or count. Completion deleted the private input file, produced exactly 501 licenses and 501 `license_created` audit events, and reported no skipped/error rows. The verifier removed its job option, lock, scheduled hooks, licenses and events; license/event aggregate counts returned to the exact baseline.

Implementation hardening: `CsvImportJob` now acquires an atomic, non-autoloaded per-token option lock. An expired lock is claimed with a single conditional database update; a contended worker schedules a delayed retry. `finally` releases normal locks, while the bounded TTL and cleanup path cover interrupted workers.

Follow-up source review found that an expired worker could otherwise delete a successor's lock in `finally`. The lease now carries a random owner token, and release uses a conditional `option_name` plus exact `option_value` delete. The guarded drill covers expired-lock handoff, stale-owner release refusal, and exact-owner release before processing the 501 rows.

The guarded F19 real multipart matrix also uploaded a file above the asynchronous threshold through the actual admin action. Its queued job pointed to a restricted private temporary file outside the web uploads directory; the copied file hash matched the source, and the verifier removed the job and file. The source now refuses WordPress's web-root temporary fallback for queued uploads, reports, and exports.

Result: PASS for the explicit queued-job drill. No key, cookie, database credential or filesystem path was printed or retained. This is Free V1 branch evidence, not an SVN release authorization.
