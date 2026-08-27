# F19 CSV preview partial evidence — 0.3.0

- Date: 2026-08-27
- Classification: `MANUAL_ENVIRONMENT_REQUIRED`
- Environment: WordPress 7.1; WooCommerce 11.0.1; PHP 8.2.4; MariaDB 10.4.28; HPOS enabled; Dreamax License Manager 0.3.0
- Scope: administrator CSV import preview and read-only source/database checks on the disposable environment
- Sensitive-data handling: only synthetic input was used. No screenshot, license value, product identifier, customer data, order reference, Cookie, session value, URL, or private identifier is retained or referenced.

## Sanitized observations

- The Import / Export screen exposed preview-only import separately from export and full-key export controls.
- A header-only CSV with the required column names returned zero valid rows.
- A synthetic row with an invalid product identifier returned zero valid rows and identified row 2 as invalid.
- A UTF-8 synthetic, formula-prefixed value paired with a shape-valid synthetic product identifier returned one valid preview row.
- Every preview kept the preview-only control enabled. No real import or export was started.
- A database read-only transaction found zero `license_created` audit events whose source was `csv_import`, corroborating that the previews created no license.
- Read-only source inspection confirmed a 5 MiB upload ceiling, a 10,000-row synchronous limit, preview-mode suppression of the import write, 250-row keyset export batches, and spreadsheet-formula prefix escaping on export.

## Evidence limit

F19 is not `PASS_WITH_EVIDENCE`. Live valid import, duplicate handling, normalization boundaries, actual export contents and download headers, capability separation, audit behavior, 5 MiB and 10,000-row boundaries, memory behavior, and downloadable error handling remain unverified. F19 therefore remains `MANUAL_ENVIRONMENT_REQUIRED`.
