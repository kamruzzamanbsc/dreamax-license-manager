# Free V1 automated evidence — 0.3.0

- Date: 2026-08-25
- Reviewer: Automated release-gate audit
- Environment: Windows 10.0.19045 x64; PHP 8.2.4 with JSON and Sodium; Composer 2.8.5; PHPUnit 10.5.64; PHPStan 2.2.9; PHP_CodeSniffer 3.13.6; WordPress stubs 6.9.4; WooCommerce stubs 11.0.0
- Runtime limitation: no live WordPress, WooCommerce, web server, database, proxy/CDN, multisite, mail, or browser environment was available.
- Secret handling: no license key, credential, master secret, token, customer data, or request payload is present in this evidence.

## Required automated audit

| Command/procedure | Expected | Observed |
| --- | --- | --- |
| `composer dump-autoload -o --strict-ambiguous` | Optimized classmap; no ambiguous production class | Exit 0; 1,603 classes generated; no ambiguity reported |
| `composer validate --no-check-publish` | Valid Composer configuration | Exit 0; `./composer.json is valid` |
| `composer lint` | WordPress PHPCS clean | Exit 0; no violation reported |
| `composer analyse` | PHPStan clean | Exit 0; `[OK] No errors`, 49/49 source files |
| `composer test` | Complete configured suite passes | Exit 0; 114/114 tests, 667 assertions |
| PHP-lint `dreamax-license-manager.php`, `uninstall.php`, and every `src/**/*.php` file | No syntax error in every production PHP file | Exit 0; 51/51 production PHP files clean |
| `php docs/release-evidence/0.3.0/verify-autoload.php` | All production classes resolve through Composer and the runtime mapping using exact portable case | Exit 0; 49 unique classes resolved through both mappings; no case/path collision |
| `powershell -NoProfile -ExecutionPolicy Bypass -File docs/release-evidence/0.3.0/verify-renames.ps1` | Main-baseline and intentional F25/F31/F32 identities resolve and no old path reference remains | Exit 0; all 41 main-baseline identities and 8 F25/F31/F32 additions present; no missing/duplicate class or old path reference |
| Compare plugin header, `DREAMAX_LM_VERSION`, and readme stable tag | All versions equal `0.3.0` | Exit 0; all three equal `0.3.0` |
| High-confidence secret-signature scan of all intended changed files, excluding dependency/cache/log/environment/key/build paths | No embedded credential or private-key material | Exit 0; 38/38 intended files scanned; no finding and no prohibited changed path |
| `git diff --check` | No whitespace errors | Exit 0; no output |

Composer emitted a sandbox-only Git ownership warning and defaulted root-package discovery to `1.0.0`. It did not affect any command exit status, classmap path, plugin version, or the independently verified `0.3.0` header/constant/stable-tag values.

## PASS_WITH_EVIDENCE gates

### F17 — Generator entropy

- Command: `.\vendor\bin\phpunit tests/Unit/KeyGeneratorTest.php`
- Expected: default entropy is at least 128 bits and configurations below 96 bits are rejected.
- Observed: exit 0; 3/3 tests and 4 assertions passed, including the default-entropy and sub-floor rejection tests.
- Evidence: this section and `tests/Unit/KeyGeneratorTest.php`.
- Environment/date/reviewer: environment above; 2026-08-25; automated release-gate audit.

### F21 — Static/security analysis

- Commands: `composer lint`; `composer analyse`; `composer audit --locked --format=summary`.
- Expected: no WordPress coding-standard violation, no PHPStan error, and no known dependency vulnerability advisory.
- Observed: all exited 0; PHPCS reported no violation; PHPStan reported `[OK] No errors` for 49/49 files; Composer reported `No security vulnerability advisories found.`
- Evidence: Required automated audit above and this section.
- Environment/date/reviewer: environment above; 2026-08-25; automated release-gate audit.

### F23 — Dependencies

- Procedure: run `composer licenses --format=json` and `composer audit --locked --format=summary`; inspect `composer.json` runtime requirements and `docs/DEPENDENCIES.md`.
- Expected: runtime dependencies are isolated and recorded, dependency licenses are compatible with the GPL-2.0-or-later project, and no known vulnerability advisory remains.
- Observed: both commands exited 0; runtime Composer requirements contain only PHP, JSON, and Sodium; WordPress/WooCommerce are host-provided; all installed third-party packages are development tooling under MIT, BSD-3-Clause, or LGPL-3.0-or-later terms; no advisory was found; the runtime inventory is recorded in `docs/DEPENDENCIES.md`.
- Evidence: this section, `composer.json`, `composer.lock`, and `docs/DEPENDENCIES.md`.
- Environment/date/reviewer: environment above; 2026-08-25; automated release-gate audit.

## F01–F33 classification

| ID | Classification | Evidence-based reason |
| --- | --- | --- |
| F01 | MANUAL_ENVIRONMENT_REQUIRED | Clean WordPress activation, database tables, and roles require a disposable WordPress/database runtime. |
| F02 | MANUAL_ENVIRONMENT_REQUIRED | Sequential v1/v2/v3 schema snapshots, replay, guards, and source/unit evidence are complete; real disposable WordPress/MySQL preservation, interruption, and multisite execution remains. |
| F03 | MANUAL_ENVIRONMENT_REQUIRED | Paid simple-product checkout and delivery require WooCommerce, payment/status hooks, database, and mail. |
| F04 | MANUAL_ENVIRONMENT_REQUIRED | Variation-specific policy/identity requires a live WooCommerce catalog and checkout. |
| F05 | MANUAL_ENVIRONMENT_REQUIRED | Hook replay/idempotent delivery requires the WordPress/WooCommerce/database integration runtime. |
| F06 | MANUAL_ENVIRONMENT_REQUIRED | A real concurrent InnoDB allocation race is required. |
| F07 | MANUAL_ENVIRONMENT_REQUIRED | A real concurrent activation/API/database race is required. |
| F08 | MANUAL_ENVIRONMENT_REQUIRED | State/expiry unit subset passed (24 tests, 29 assertions), but the required REST suite and live lifecycle matrix were not available. |
| F09 | MANUAL_ENVIRONMENT_REQUIRED | Cross-customer IDOR/reveal/order checks require authenticated WordPress/WooCommerce fixtures. |
| F10 | MANUAL_ENVIRONMENT_REQUIRED | Role and capability enforcement requires real WordPress users, roles, nonces, and hooks. |
| F11 | MANUAL_ENVIRONMENT_REQUIRED | Missing/wrong-key boot and database non-mutation checks require isolated WordPress/database instances. |
| F12 | MANUAL_ENVIRONMENT_REQUIRED | Database/root-key restore and DB-only recovery require disposable backups and runtime instances. |
| F13 | MANUAL_ENVIRONMENT_REQUIRED | Order-slot unit subset passed (4 tests, 8 assertions), but the full refund/cancel/reassign/edit acceptance matrix requires WooCommerce/database/mail. |
| F14 | MANUAL_ENVIRONMENT_REQUIRED | Application, reverse-proxy, page-cache, and CDN header behavior requires deployed HTTP infrastructure. |
| F15 | MANUAL_ENVIRONMENT_REQUIRED | Enumeration timing, proxy spoofing, and rate-limit behavior require live HTTP/proxy and database tests. |
| F16 | MANUAL_ENVIRONMENT_REQUIRED | Replay/conflict/cleanup behavior requires the REST and transactional database runtime. |
| F17 | PASS_WITH_EVIDENCE | Exact entropy boundary suite passed; see F17 evidence above. |
| F18 | MANUAL_ENVIRONMENT_REQUIRED | Stable instance/product identity needs WooCommerce duplication/edit and REST lifecycle integration. |
| F19 | MANUAL_ENVIRONMENT_REQUIRED | CSV upload/import/export, Unicode, formula, size, filesystem, and audit behavior require WordPress/database fixtures. |
| F20 | MANUAL_ENVIRONMENT_REQUIRED | HPOS/classic storage and Blocks/classic checkout require the supported WooCommerce compatibility matrix. |
| F21 | PASS_WITH_EVIDENCE | PHPCS, PHPStan, and locked dependency audit passed; see F21 evidence above. |
| F22 | PASS_WITH_EVIDENCE | Official hosted readme validation has no errors; header/version/policy checks and the isolated deterministic production build pass. The artifact remains an unshipped candidate. |
| F23 | PASS_WITH_EVIDENCE | Dependency inventory, licenses, isolation, and advisory scan passed; see F23 evidence above. |
| F24 | MANUAL_ENVIRONMENT_REQUIRED | Seeded smoke fixture and full 10k/50k profile, cleanup guards, pagination/allocation/memory checks, and bounded CSV export are implemented; full database execution remains. |
| F25 | MANUAL_ENVIRONMENT_REQUIRED | The secure guest-claim workflow, unit/source-contract coverage, and security documentation are implemented; real WordPress/WooCommerce/database/mail/concurrency acceptance evidence remains required. See `f25-guest-claim.md`. |
| F26 | MANUAL_ENVIRONMENT_REQUIRED | Fault injection across database, key, cache, mail, and WooCommerce states requires a disposable integrated runtime. |
| F27 | MANUAL_ENVIRONMENT_REQUIRED | Repeated/cross-customer/partial exporter and eraser checks require WordPress privacy-tool and database fixtures. |
| F28 | MANUAL_ENVIRONMENT_REQUIRED | The frozen client exists, but executing it requires a live configured v1 server and scenario fixtures. |
| F29 | MANUAL_ENVIRONMENT_REQUIRED | Key provisioning, multisite separation, and restore behavior require disposable WordPress/database/key environments. |
| F30 | MANUAL_ENVIRONMENT_REQUIRED | Two-site data/API/export/uninstall isolation requires a real WordPress multisite network. |
| F31 | MANUAL_ENVIRONMENT_REQUIRED | Credential creation/authentication/scope/expiration/rotation/revocation, local unit/source-contract coverage, and security documentation are complete; live WordPress REST/MySQL/proxy/multisite concurrency evidence remains required. See `f31-credential-lifecycle.md`. |
| F32 | MANUAL_ENVIRONMENT_REQUIRED | The authoritative catalog validates all 44 production event types, preserves four non-persistable legacy names, and has emitter/consumer/redaction/compatibility coverage; live WordPress/MySQL/privacy/multisite transaction evidence remains required. See `f32-audit-event-contract.md`. |
| F33 | PASS_WITH_EVIDENCE | Two clean snapshots of one recorded commit produce matching ZIP SHA-256 and exact inventories; the secret-free provenance manifest remains outside the artifact. |

Classification totals: 5 PASS_WITH_EVIDENCE; 0 AUTOMATABLE_PENDING; 28 MANUAL_ENVIRONMENT_REQUIRED; 0 IMPLEMENTATION_BLOCKER.
