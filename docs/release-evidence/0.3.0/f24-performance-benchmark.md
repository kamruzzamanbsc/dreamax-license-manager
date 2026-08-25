# F24 deterministic performance evidence — 0.3.0

- Date: 2026-08-25
- Classification: `MANUAL_ENVIRONMENT_REQUIRED`
- Automated scope: deterministic fixture identities/counts, explicit seed and disposable guard, duplicate-run refusal, ownership-scoped cleanup, duplicate allocation, pagination, fixed memory ceiling, bounded administration/export source contracts, and smoke execution.

The smoke profile used the fixed seed label `free-v1-smoke-20260825` but reports only its SHA-256 digest `41483c79f28371178c025074b439d74f26b10e7dcafd2c518b1bcbfe16bfbe80`. It produced run ID `perf_113b0f7ca5517b1a3434`, 250 licenses, 1,250 events, and 13 event pages. The observed PHP 8.2.4 synthetic run completed in 0.002233 seconds with a 2,097,152-byte absolute peak and zero additional allocator-page peak; the fixed delta ceiling was 67,108,864 bytes. Identity, order-slot, pagination, and memory checks passed.

The full profile is fixed at 10,000 licenses, 50,000 events, 250 rows per page, and a 268,435,456-byte memory-delta ceiling. It was not executed because no verified disposable WordPress/WooCommerce/MySQL environment exists. The exact safe live procedure is in `docs/PERFORMANCE.md`; no threshold was weakened.

The source audit also replaced the all-row license CSV query with 250-row keyset batches. The administrator list remains limited to 50 rows per page. Real database query counts, allocation behavior, final-page traversal, export memory, and cleanup sentinels remain manual.
