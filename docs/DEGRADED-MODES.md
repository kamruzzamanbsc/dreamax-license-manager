# Operational degraded-mode matrix

| State | Allowed | Blocked | Public API | Administrator signal | Recovery |
| --- | --- | --- | --- | --- | --- |
| Plugin database storage unavailable after WordPress boot | Static plugin bootstrap and non-sensitive diagnostics only | All authoritative licensing reads/writes | `503 server_unavailable` | Critical Site Health storage signal without table names or secrets | Restore the database service or exact plugin tables, then retry boundedly |
| Master key missing | Non-sensitive diagnostics and record counts | Create, assign, reveal, export, validate, activate, deactivate | `503 server_unavailable` | Persistent capability-protected recovery notice | Restore exact constant and verify ID + authenticated decrypt |
| Wrong master key | Same as missing key; ciphertext untouched | Same | `503 server_unavailable` | Wrong-key recovery notice | Restore correct key; never reset while encrypted rows exist |
| WooCommerce unavailable | Read-only licensing storage diagnostics | Commerce configuration/allocation/delivery | Existing public API remains disabled by dependency bootstrap | Dependency notice | Restore compatible WooCommerce; data unchanged |
| WordPress cron absent/backlogged | Bounded synchronous core order allocation and manual retry | Deferred bounded cleanup | Core API unaffected unless its own storage fails | Recommended Site Health cleanup signal | Restore WordPress cron and resume idempotently |
| Upload temporary storage unavailable | Authoritative records, streamed export, and normal API | File-backed import operation | Unaffected | Atomic import error before any row is written | Fix PHP upload temporary storage and retry; clean owned files only |
| Audit storage unavailable | Non-mutating diagnostics | Security/business mutation requiring audit | `503 server_unavailable` | Explicit audit failure | Restore event table, rerun whole transaction |

No degraded state may guess that a license is valid. No recovery action may overwrite ciphertext, key identifiers, or unrelated files.
