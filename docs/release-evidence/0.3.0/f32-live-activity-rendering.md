# F32 live activity-rendering partial evidence — 0.3.0

- Date: 2026-08-27
- Classification: preserved partial observation; superseded by the completed `PASS_WITH_EVIDENCE` matrix in `f32-audit-event-contract.md`
- Environment: WordPress 7.1; WooCommerce 11.0.1; PHP 8.2.4; MariaDB 10.4.28; HPOS enabled; Dreamax License Manager 0.3.0
- Scope: administrator activity rendering plus sanitized database aggregates on the disposable environment
- Sensitive-data handling: no screenshot, event metadata value, public or internal identifier, license value, credential data, Cookie, session value, URL, or private path is retained or referenced.

## Sanitized observations

- The administrator activity table rendered supported license, order-allocation, reveal, and credential lifecycle event names without a PHP warning or rendering failure.
- Events attributed to administrator, customer, and WooCommerce actor classes rendered in the same table.
- The paid-order sequence visibly included `license_created`, `license_assigned`, `license_delivered`, and `order_automatic_allocation_completed`.
- A database read-only transaction independently counted exactly one created, one assigned, and one delivered event for the most recent licensed order, with zero duplicate required-event groups.
- No activity filter, write, export, privacy operation, credential operation, or database mutation was performed during this check.

## Evidence relationship

This read-only observation remains valid historical evidence. The later guarded F32 verifier completed rejected writes, validation and persistence rollback, every compatibility status, recursive read redaction, warning-free mixed-history rendering, byte-stable storage, and exact cleanup. Accepted F08, F27, F29/F30, and F31 evidence supplies the REST, privacy, multisite, and credential producer contracts. The completed result is recorded in `f32-audit-event-contract.md`.
