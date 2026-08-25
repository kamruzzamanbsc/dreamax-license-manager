# Free V1 must-pass evidence

No row is a pass until observed evidence and an artifact link are filled. The development scaffold begins with every release gate open.

| ID | Requirement | Command or procedure | Expected result | Observed result | Artifact/log link | Environment/versions | Date | Reviewer | Pass/Fail |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| F01 | Fresh install/schema | Activate on clean WordPress | Site-local InnoDB tables and roles created without edits | Not run | — | Runtime unavailable | — | — | BLOCKED |
| F02 | Upgrade path | Run every released schema migration twice | Repeatable and data-preserving | No released prior schema | — | — | — | — | BLOCKED |
| F03 | Simple paid order | Complete paid simple-product order | Exact configured license quantity delivered once | Not run | — | — | — | — | BLOCKED |
| F04 | Variable product | Buy two variations | Each uses its own explicit policy/public ID | Not run | — | — | — | — | BLOCKED |
| F05 | Duplicate hooks | Replay payment/status hooks | No duplicate order slot or delivery event | Not run | — | — | — | — | BLOCKED |
| F06 | Pool concurrency | Race two orders for last imported key | One assignment only; other recoverable failure | Not run | — | — | — | — | BLOCKED |
| F07 | Activation concurrency | Race different and same installations | Last slot used once; same instance converges | Not run | — | — | — | — | BLOCKED |
| F08 | Lifecycle/API | PHPUnit + REST suite | All allowed/rejected transitions/codes pass | Complete matrix and expiry-policy test sources added; not run | `tests/Unit/StateMachineTest.php`, `tests/Unit/ExpiryPolicyTest.php` | PHP unavailable | — | — | BLOCKED |
| F09 | Customer isolation | IDOR/reveal/order tests | No cross-customer data/key access | Not run | — | — | — | — | BLOCKED |
| F10 | Capability isolation | Role/action security suite | Each narrow capability enforced | Not run | — | — | — | — | BLOCKED |
| F11 | Missing/wrong key | Boot with absent/wrong constant | Safe recovery mode; no ciphertext/key metadata changes | Not run | — | — | — | — | BLOCKED |
| F12 | Disaster recovery | Restore disposable DB + root key | Existing key decrypts and validates; DB-only restore warns | Not run | — | — | — | — | BLOCKED |
| F13 | Refund/cancel/reassign/edit | Acceptance matrix | Configured policy is deterministic and audited | Reassign/extend/reset/refund/cancel/quantity/resend/backfill sources complete; acceptance execution not run | `src/Licenses/class-lifecycleservice.php`, `src/Integrations/WooCommerce/class-orderpolicyservice.php`, `tests/Unit/OrderSlotPolicyTest.php`, `docs/WOOCOMMERCE-ORDER-POLICIES.md` | PHP/WordPress/WooCommerce runtime unavailable | 2026-08-24 | Codex source review | BLOCKED |
| F14 | Cache headers | REST/My Account proxy checks | Sensitive responses never publicly cached | Not run | — | — | — | — | BLOCKED |
| F15 | Enumeration/proxy abuse | Response/timing/rate-limit suite | No customer/order/secret leak or spoof bypass | Not run | — | — | — | — | BLOCKED |
| F16 | Idempotency | Replay/conflict/cleanup suite | Same result replay; conflict 409; no secret stored | Not run | — | — | — | — | BLOCKED |
| F17 | Generator entropy | Unit boundary suite | Default ≥128 bits; <96 rejected | Source test added, not executed | — | PHP unavailable | — | — | BLOCKED |
| F18 | Stable instance/product IDs | Lifecycle integration suite | Labels/edits do not change identity; duplicates do | Not run | — | — | — | — | BLOCKED |
| F19 | CSV safety | Invalid/Unicode/formula/large import suite | Validated, escaped, bounded, auditable | Not run | — | — | — | — | BLOCKED |
| F20 | HPOS/Blocks | Compatibility matrix | Same outcomes under supported modes | Not run | — | — | — | — | BLOCKED |
| F21 | Static/security analysis | PHPCS, PHPStan, audit | No unresolved critical/high issue | Not run | — | PHP/Composer unavailable | — | — | BLOCKED |
| F22 | Docs/packaging | Readme validator + clean build | Consistent slug/version/tag, no dev/secrets | Release-header URL audit passed: Author URI is `https://dreamaxsoft.com/`; optional Plugin URI was removed because the public site/sitemap has no plugin-specific page; no Update URI or other release-facing invalid URL exists. WordPress readme validator and clean production build remain unexecuted. | `dreamax-license-manager.php`, `docs/release-evidence/0.3.0/f22-release-header-urls.md` | Dreamax public site/sitemap; Windows 10 x64; PHP 8.2.4; Composer 2.8.5 | 2026-08-25 | Codex automated release-gate audit | BLOCKED |
| F23 | Dependencies | Inventory/license/vulnerability checks | GPL-compatible, isolated, recorded | No runtime package deps | — | — | — | — | BLOCKED |
| F24 | Performance | 10k licenses/50k events fixture | Bounded memory, queries, pagination, completion | Fixture incomplete | — | — | — | — | BLOCKED |
| F25 | Guest claim | Ownership/replay/concurrency suite | Single-use 256-bit proof; no email-only disclosure | Source implementation and local policy/security contract tests complete; live WordPress/WooCommerce/InnoDB/mail/HPOS concurrency evidence not run | `docs/release-evidence/0.3.0/f25-guest-claim.md`, `tests/Unit/GuestClaimPolicyTest.php`, `tests/Unit/GuestClaimSourceContractTest.php` | Windows 10 x64; PHP 8.2.4; Composer 2.8.5; no live WooCommerce runtime | 2026-08-25 | Codex source/automated review | BLOCKED |
| F26 | Degraded matrix | Fault-injection drill | Every state fails closed with recovery signal | Not run | — | — | — | — | BLOCKED |
| F27 | Privacy exporter/eraser | Repeat/cross-customer/partial tests | Personal data minimized; business integrity retained | Not run | — | — | — | — | BLOCKED |
| F28 | Frozen client | Run unchanged v1 fixture | All required v1 contracts pass | Not run | — | — | — | — | BLOCKED |
| F29 | Master-key provisioning | Setup/multisite/restore suite | No license before readiness; per-site separation | Not run | — | — | — | — | BLOCKED |
| F30 | Multisite isolation | Two-site jobs/data/API/export/uninstall | No cross-site access or deletion | Not run | — | — | — | — | BLOCKED |
| F31 | Privileged Bearer | Parser/scope/rotation/redaction suite | Exact protocol and uniform failures | Source implementation and local policy/security contract tests complete; live WordPress REST/MySQL/proxy/multisite concurrency evidence not run | `docs/release-evidence/0.3.0/f31-credential-lifecycle.md`, `tests/Unit/CredentialTokenTest.php`, `tests/Unit/CredentialPolicyTest.php`, `tests/Unit/CredentialLifecycleSourceContractTest.php` | Windows 10 x64; PHP 8.2.4; Composer 2.8.5; no live WordPress/MySQL runtime | 2026-08-25 | Codex source/automated review | BLOCKED |
| F32 | Audit compatibility | Event catalog/consumer tests | Versioned stable meanings and sanitized payloads | Not run | — | — | — | — | BLOCKED |
| F33 | Release provenance | Two clean deterministic builds | Matching ZIP hash and secret-free manifest | Not run | — | — | — | — | BLOCKED |
