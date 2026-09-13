# Import and export

The **License Manager -> Import / Export** screen moves license and activation data without requiring an external service.

## Import workflow

1. Download the UTF-8 template.
2. Keep `license_key` and `product_public_id`, or enter the corresponding source headings under **Map source columns**.
3. Select the file encoding and delimiter explicitly.
4. Keep **Preview only** selected and check the result first.
5. Choose whether existing keys should be reported as errors or skipped.
6. Clear **Preview only**, acknowledge the write, and import only after the preview is acceptable.

Required canonical fields are `license_key` and `product_public_id`. Optional fields are `activation_limit`, `expires_at`, `normalization_profile`, and `separator`. Empty activation limits mean unlimited; zero disables activation. Dates without an explicit offset are interpreted as UTC.

UTF-8, Windows-1252, and ISO-8859-1 input are supported. Invalid UTF-8 fails the affected row. Imported keys default to the exact, case-sensitive `import-exact-v1` profile. Select `generated-ascii-v1` only when the source keys were created for that normalization contract.

Files up to 5 MiB and 10,000 data rows are accepted. A committed file larger than 256 KiB is copied to the operating system's private temporary directory and processed in resumable 250-row jobs through Action Scheduler, with WP-Cron as a fallback. The original key-bearing file is deleted when processing completes or stops. Job and error-report metadata expire automatically.

Row reports contain only the row number and a bounded, sanitized problem description. They never repeat a license key, database error, filesystem path, or encrypted value.

## Export workflow

License exports support lifecycle status, product public ID, customer ID, order ID, and expiry filters. Activation exports support activation status plus the same non-secret license context filters. Both use keyset pagination and private no-store download headers.

Ordinary license exports mask keys. Full-key export requires `dreamax_lm_export_license_keys`, explicit confirmation, successful encryption-key recovery, and an audit event. Activation exports contain opaque activation/license IDs and optional labels, but never license keys or raw instance fingerprints.

All exported cells beginning with `=`, `+`, `-`, or `@` are neutralized before CSV serialization to prevent spreadsheet formula execution.

## Recovery and cleanup

If encryption is unavailable, license export fails before an audit event or partial download is produced. Activation export remains available because it does not decrypt keys. Interrupted import jobs retain only their private temporary file and bounded job state until automatic cleanup; retry by starting a new preview after reviewing system status.
