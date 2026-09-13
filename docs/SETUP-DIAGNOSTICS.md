# Setup and diagnostics

Open **License Manager -> System status** after activation and after infrastructure, key, database, proxy, mail, or scheduler changes. The checklist is advisory; licensing services still enforce their own fail-closed runtime checks.

## Readiness checks

- Environment confirms the supported PHP version, Sodium, WordPress, and WooCommerce are loaded.
- Database checks confirm the current schema checkpoint and InnoDB on every authoritative plugin table.
- Encryption validates the configured external master key and its site-bound identity.
- Delivery, generator, test-license, and customer-portal checks identify unfinished merchant setup.
- The REST smoke check executes the public ping route in memory and verifies private, no-store response headers.
- Background processing checks the cleanup schedule and reports a bounded Dreamax Action Scheduler backlog when that scheduler is available.
- Proxy and rate-limit storage confirms plugin storage is available and reports only the number of configured trusted proxy addresses.
- Backup readiness is complete only after a security administrator acknowledges a tested database-plus-key backup for the current master-key identity.

## Trusted proxies

Configure an address only when that exact reverse proxy connects directly to WordPress. Use one IPv4 or IPv6 address per line. Forwarded client and protocol headers remain ignored when the direct source is not allowlisted. Invalid, duplicate, and excess entries are discarded; at most 100 exact addresses are stored.

Changing this list changes a security boundary. Re-run the public API proxy and rate-limit checks after any change.

## Backup acknowledgement

The database does not contain the external `DREAMAX_LICENSE_MANAGER_MASTER_KEY`. A recoverable backup therefore requires both the database and the exact external key. The acknowledgement stores only the current non-secret key identifier and automatically becomes invalid when the key is missing or changes.

The checkbox records an operator acknowledgement; it does not create or test a backup. Follow `RECOVERY.md` for the disposable restore drill.

## Delivery test

The email test sends a fixed message to the signed-in administrator. It contains no license key or customer data. A successful result means WordPress accepted the message; confirm actual inbox delivery separately when validating production mail.

## Support report

The JSON report is capability and nonce protected and is downloaded with private, no-store headers. It includes bounded version, HTTPS, multisite, cron, schema, and readiness facts. It excludes license keys, credentials, customer records, email addresses, IP addresses, option values, server paths, key identifiers, salts, and ciphertext.

Review the report before sharing it because surrounding infrastructure or third-party modifications may change runtime behavior.
