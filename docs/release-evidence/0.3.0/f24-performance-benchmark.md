# F24 deterministic performance evidence — 0.3.0

- Date: 2026-08-28
- Classification: `PASS_WITH_EVIDENCE`
- Automated scope: deterministic fixture identities/counts, explicit seed and disposable guard, duplicate-run refusal, ownership-scoped cleanup, duplicate allocation, pagination, fixed memory ceilings, bounded administration/export source contracts, smoke execution, and a guarded live full-profile run.

The smoke profile used the fixed seed label `free-v1-smoke-20260825` but reports only its SHA-256 digest `41483c79f28371178c025074b439d74f26b10e7dcafd2c518b1bcbfe16bfbe80`. It produced run ID `perf_113b0f7ca5517b1a3434`, 250 licenses, 1,250 events, and 13 event pages. The observed PHP 8.2.4 synthetic run completed in 0.002233 seconds with a 2,097,152-byte absolute peak and zero additional allocator-page peak; the fixed delta ceiling was 67,108,864 bytes. Identity, order-slot, pagination, and memory checks passed.

The full profile remained fixed at 10,000 licenses, 50,000 events, 250 event rows per page, and 268,435,456-byte process/export memory ceilings. On 2026-08-28 it ran against the guarded disposable WordPress 7.1, WooCommerce 11.0.1, PHP 8.2.4, MariaDB 10.4.28, HPOS-enabled environment with Dreamax License Manager 0.3.0. No threshold was weakened.

The run completed 200 administration pages at the production 50-row bound, 100 privileged-list pages, and 200 event pages. License insertion took 11.992 seconds, event insertion 17.305 seconds, administration pagination 12.782 seconds, privileged-list pagination 0.421 seconds, streamed masked export 9.923 seconds, and total measured database work 133.616 seconds. Duplicate order-slot allocation was rejected, final-page traversal and the complete masked export passed, and both memory ceilings passed.

The verifier used only generated owned fixtures and emitted no keys or private identifiers. Outbound email remained disabled. Cleanup committed, zero owned fixture rows remained, starting aggregate counts were restored, and an unrelated sentinel remained unchanged. Supporting executable evidence is in `scripts/verify-live-performance.php`, `scripts/lib/performance-export-router.php`, and `tests/Unit/LivePerformanceSourceContractTest.php`.
