# Operational degraded-mode matrix

| State | Allowed | Blocked | Public API | Administrator signal | Recovery |
| --- | --- | --- | --- | --- | --- |
| Database unavailable | Static plugin bootstrap only | All authoritative reads/writes | `503 server_unavailable` | Site Health/error without secrets | Restore DB service, retry boundedly |
| Master key missing | Non-sensitive diagnostics and record counts | Create, assign, reveal, export, validate, activate, deactivate | `503 server_unavailable` | Persistent capability-protected recovery notice | Restore exact constant and verify ID + authenticated decrypt |
| Wrong master key | Same as missing key; ciphertext untouched | Same | `503 server_unavailable` | Wrong-key recovery notice | Restore correct key; never reset while encrypted rows exist |
| WooCommerce unavailable | Read-only licensing storage diagnostics | Commerce configuration/allocation/delivery | Existing public API remains disabled by dependency bootstrap | Dependency notice | Restore compatible WooCommerce; data unchanged |
| Action Scheduler absent/backlogged | Bounded synchronous core order allocation; manual retry | Unbounded imports, reminders, webhooks, heavy jobs | Core API unaffected unless its own storage fails | Delayed/manual retry state | Restore scheduler and resume idempotently |
| Temporary/export storage unavailable | Authoritative records and normal API | File-backed import/export operation | Unaffected | Atomic operation error | Fix writable private temp storage; retry; clean owned files only |
| Audit storage unavailable | Non-mutating diagnostics | Security/business mutation requiring audit | `503 server_unavailable` | Explicit audit failure | Restore event table, rerun whole transaction |

No degraded state may guess that a license is valid. No recovery action may overwrite ciphertext, key identifiers, or unrelated files.
