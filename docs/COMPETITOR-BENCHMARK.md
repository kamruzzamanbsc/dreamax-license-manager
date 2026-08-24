# Competitor benchmark

Review date: 2026-08-24. Public documentation changes; re-check before each release. This file records high-level parity only and does not copy competitor code, schemas, UI, or copywriting.

Sources reviewed:

- License Manager for WooCommerce: https://wordpress.org/plugins/license-manager-for-woocommerce/
- WooCommerce-hosted License Manager documentation: https://woocommerce.com/document/license-manager-woo/
- Digital License Manager: https://wordpress.org/plugins/digital-license-manager/
- Digital License Manager migration documentation: https://docs.codeverve.com/digital-license-manager/migration/migrate-from-license-manager-for-woocommerce/

| Publicly documented capability | Market baseline | Dreamax target | Current evidence |
| --- | --- | --- | --- |
| WooCommerce key generation/delivery | Both advertise | Free V1 | Product/order integration foundation; full acceptance tests pending |
| Imported key pools/stock | Both advertise | Free V1 | Atomic pool reservation code; stock/admin tests pending |
| Encrypted key storage | Both advertise | Free V1 with dedicated external key/recovery | XChaCha20-Poly1305 + blind index foundation |
| REST activation/validation/deactivation | Both advertise | Free V1, versioned/idempotent/rate-limited | Public v1 foundation; reference fixture pending |
| My Account | Publicly documented | Free V1 | Masked list and audited reveal; activation management pending |
| CSV import/export | Publicly documented | Free V1 | Bounded CSV path; background jobs/mapping/error file pending |
| Migration between competitors | Publicly documented | Free V1.1 | Not implemented; release plan only |
| API credentials | Publicly documented in products | Scoped Free V1 management API | Exact Bearer format and credential creation foundation |
| Past-order backfill | Publicly documented | Free V1 | Not implemented; release blocker |

Security, portability, recovery, privacy, idempotency, and stable public contracts take priority over raw feature count. Unknown competitor behavior remains unknown.
