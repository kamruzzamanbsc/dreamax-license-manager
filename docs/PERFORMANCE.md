# Deterministic performance fixture and benchmark

The fixture requires an explicit 8–128 character seed and the exact disposable marker. Identities derive from SHA-256 over the seed, record type, and sequence; no plaintext license key, credential, claim proof, or customer data is created or logged. Each row is tagged with a deterministic fixture run ID. A repeated run is detected, and cleanup deletes only rows carrying that exact run ID.

Profiles:

| Profile | Licenses | Audit events | Page size | Memory-delta ceiling |
| --- | ---: | ---: | ---: | ---: |
| smoke | 250 | 1,250 | 100 | 64 MiB |
| full | 10,000 | 50,000 | 250 | 256 MiB |

Run the synthetic smoke contract:

```text
composer benchmark:smoke
```

The result records the seed digest rather than the seed, deterministic run ID, counts, pages, wall time, absolute/delta peak memory, PHP version, checks, UTC time, and limitations. It checks unique fixture identities, duplicate order-slot allocation, complete pagination, and the fixed memory ceiling. The administration list uses `LIMIT 50`; CSV export now uses keyset batches of 250 rather than loading the complete table.

## Full live procedure

The full target must run only on a disposable WordPress/WooCommerce/MySQL installation with the exact marker, a clearly test-only database name, and a reserved/loopback URL. `scripts/verify-live-performance.php` inserts generated rows under its owned metadata in bounded transactions, attempts duplicate allocation, traverses every administration/API/event page through the final page, streams a masked CSV export through the real administration action, records sanitized timings and memory checks, then deletes only owned rows and proves aggregate counts and an unrelated sentinel remain unchanged.

On 2026-08-28 the guarded full profile passed on the disposable release environment: 10,000 licenses, 50,000 events, 200 administration pages, 100 privileged-list pages, 200 event pages, duplicate-slot rejection, and complete masked export. License insertion took 11.992 seconds, event insertion 17.305 seconds, administration pagination 12.782 seconds, privileged-list pagination 0.421 seconds, export 9.923 seconds, and total measured database work 133.616 seconds. Both 256 MiB memory checks passed. Exact cleanup committed with zero owned rows remaining, unchanged database aggregates, and an unchanged unrelated sentinel. No outbound email was sent and no sensitive output was produced. Thresholds were not reduced.
