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

The full target must run only on a disposable WordPress/WooCommerce/MySQL installation with the exact marker, a clearly test-only database name, and a reserved/loopback URL. Insert the generated rows under their fixture run ID in bounded transactions, run duplicate allocation attempts and every admin/API page through the final page, stream a full CSV export, record query counts/timings and process/database peak memory, then delete only matching run-ID rows and prove unrelated sentinels remain.

This environment has no WordPress, WooCommerce, MySQL, Docker, or WP-CLI runtime, so only the small synthetic smoke profile is executed automatically. The real 10k/50k database benchmark remains `MANUAL_ENVIRONMENT_REQUIRED`; thresholds are not reduced.
