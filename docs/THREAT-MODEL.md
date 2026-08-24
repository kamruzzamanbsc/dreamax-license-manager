# Threat model

Review date: 2026-08-24

| Threat | Control | Residual risk / verification |
| --- | --- | --- |
| Database leak | Authenticated ciphertext; independent keyed blind index; no root key in DB | A compromised WordPress runtime can still request decryption; run restore and redaction tests |
| Missing/replaced root key | Non-secret key identifier; recovery mode; no automatic replacement | Operator must preserve the external key |
| Key guessing/enumeration | 128-bit default entropy; layered keyed buckets; uniform privacy-safe failures | Network timing cannot be perfectly constant; test shapes and timing distributions |
| Activation last-slot race | Per-license InnoDB lock, unique logical activation row, same-transaction event | Requires real concurrent integration test |
| Request replay | Natural-key idempotency plus caller idempotency record | Frozen client must cover replay/conflict |
| Cross-customer disclosure/IDOR | WooCommerce verified order context/key checks; customer IDs; nonce-protected emailed single-use guest claim; opaque IDs; private responses | Live guest-session, HPOS, role, replay, timing, and concurrency evidence remains required |
| Client tampering | Treat distributed code as inspectable; enforce all rules server-side | Cannot guarantee piracy prevention |
| Plain HTTP/proxy spoof | HTTPS guard; exact trusted proxies; loopback-only explicit exception | Deployment misconfiguration remains possible; health checks/documentation required |
| Cache/CDN leakage | No-store/private headers; no secrets in URLs; conservative CORS | Must verify representative caches/CDNs |
| Admin privilege escalation | Dedicated capabilities at action boundaries | Capability integration/security tests pending |
| CSV injection/malicious import | Formula neutralization, size/row bounds, explicit profiles | Background large-import sandboxing and downloadable error artifact pending |
| Logs/debug leaks | Never log request bodies, keys, tokens, Authorization, or raw IP | Web-server logs are outside plugin control; deployment guide required |
| Reassignment/refund/quantity replay | Deterministic order item + slot identity; auditable transitions | Full reassignment/refund/quantity UI and tests are release blockers |
| Product identity cloning | New public ID on ordinary duplicate; historical ID on issued licenses | Variation and migration collision tests pending |
| Multisite cross-site access | Blog-prefixed tables/options/keys/jobs | Two-site integration test pending |
| Dependency collision/supply chain | Minimal runtime dependencies; reproducible manifest and inventory | Clean-build and vulnerability evidence pending |
| SSRF in webhooks | P1 webhooks must resolve/recheck redirects and block private targets | No webhooks are implemented in Free V1 foundation |

Security objective: protect legitimate service access, customer confidentiality, activation integrity, recovery, and administration. The product does not claim unbreakable DRM.
