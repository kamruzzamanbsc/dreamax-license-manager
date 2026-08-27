# F19 CSV safety evidence — 0.3.0

- Date: 2026-08-28
- Classification: `PASS_WITH_EVIDENCE`
- Environment: WordPress 7.1; WooCommerce 11.0.1; PHP 8.2.4; MariaDB 10.4.28; HPOS enabled; Dreamax License Manager 0.3.0
- Scope: preserved administrator preview observations plus guarded multipart import/export verification on the disposable environment
- Sensitive-data handling: only synthetic input was used. No screenshot, license value, product identifier, customer data, order reference, Cookie, session value, URL, or private identifier is retained or referenced.

## Sanitized observations

- The Import / Export screen exposed preview-only import separately from export and full-key export controls.
- A header-only CSV with the required column names returned zero valid rows.
- A synthetic row with an invalid product identifier returned zero valid rows and identified row 2 as invalid.
- A UTF-8 synthetic, formula-prefixed value paired with a shape-valid synthetic product identifier returned one valid preview row.
- Every preview kept the preview-only control enabled. No real import or export was started.
- A database read-only transaction found zero `license_created` audit events whose source was `csv_import`, corroborating that the previews created no license.
- Read-only source inspection confirmed a 5 MiB upload ceiling, a 10,000-row synchronous limit, preview-mode suppression of the import write, 250-row keyset export batches, and spreadsheet-formula prefix escaping on export.

## Guarded live verification

- Real multipart upload handling rejected an input over 5 MiB and stopped a 10,001-row preview at the documented 10,000-row boundary.
- The boundary preview created no license. Its WordPress/WooCommerce request stayed within an explicit 128 MiB peak-memory ceiling.
- A real import accepted exactly two synthetic licenses, rejected the duplicate and invalid generated-format rows, and preserved the exact imported UTF-8 key while applying the declared generated-key normalization contract.
- An unprivileged customer was denied import and export; a shop manager received masked output even when requesting full keys; only the administrator full-key export returned the synthetic values.
- Masked and authorized exports used CSV attachment headers, keyset-batched output, and formula-prefix escaping. No protected value was written to evidence or console output.
- Import and export audit aggregates matched exactly, including the masked/full distinction and exported row count.
- The verifier removed its exact temporary users, sessions, licenses, and audit events; the cleanup transaction committed, final aggregates matched the starting snapshot, and zero owned fixture rows remained.
- No outbound email was sent.

## Reproducible artifacts

- `scripts/verify-live-csv-safety.php`
- `scripts/lib/csv-http-router.php`
- `tests/Unit/CsvSafetySourceContractTest.php`

F19 is `PASS_WITH_EVIDENCE` based on the preserved manual preview observations, source-contract coverage, and the guarded disposable WordPress/WooCommerce/InnoDB multipart verification.
