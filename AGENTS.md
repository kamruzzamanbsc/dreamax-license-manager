# Project agent guide

## What this project does

Dreamax License Manager is a self-hosted WordPress plugin for WooCommerce software licensing. It provides encrypted license storage, license generation and imported pools, WooCommerce allocation and delivery, activation APIs, customer license access, guest-order claims, scoped privileged credentials, audit events, and data portability. The current recorded candidate version is `0.3.2`.

## Project structure

- `dreamax-license-manager.php`: main WordPress plugin bootstrap and metadata.
- `src/`: production PHP organized by licensing, activation, API, credential, encryption, event, administration, privacy, customer portal, and WooCommerce integration concerns.
- `assets/`: administration CSS and JavaScript.
- `tests/`: PHPUnit unit and source-contract tests plus frozen fixtures.
- `scripts/`: deterministic build, release validation, benchmark, migration, and guarded live-verification tools.
- `docs/`: architecture, security, operations, API, release, packaging, ADR, and acceptance evidence.
- `readme.txt`: WordPress.org directory readme; `README.md`: repository overview.
- `uninstall.php`: confirmed permanent-uninstall behavior.
- `build/`: ignored release artifacts and provenance output; do not treat it as source.
- `vendor/`: installed development dependencies; do not edit manually.

## Required tools and commands

The documented baseline is WordPress 6.9+, WooCommerce 10.8+, PHP 8.0+ with Sodium, HTTPS for credential-bearing calls, and InnoDB. Development and release validation also use Composer, PHPUnit, PHPStan, PHPCS, Git, and ZIP support.

Run commands from the repository root:

- Install development dependencies only with approval: `composer install`.
- Unit/source-contract tests: `composer test`.
- Static analysis: `composer analyse`.
- Coding standards: `composer phpcs`.
- Release metadata validation: `composer release:validate`.
- Migration verification: `composer migration:verify` in the explicitly marked disposable environment only.
- Smoke benchmark: `composer benchmark:smoke` in the explicitly marked disposable environment only.
- Deterministic release build, only from an authorized recorded commit: `php scripts/build-release.php --commit=<40-character-commit> --output=build`.

Consult `docs/BUILDING.md`, `docs/RELEASE.md`, and `docs/FREE-V1-MUST-PASS.md` before making any release claim.

## Testing and validation rules

- Inspect the relevant implementation, contracts, documentation, and existing tests before changing anything.
- Keep public v1 contracts, security boundaries, migrations, and audit-event meanings stable unless a specifically approved change includes compatibility handling.
- Add or update proportionate tests and documentation with behavioral changes.
- Never run migrations, fixture generation, destructive verification, recovery drills, concurrency tests, mail tests, or live verification against production data or services.
- Guarded live scripts require a disposable local/private environment and their documented environment marker. Confirm cleanup boundaries before running them.
- Do not claim a test passed unless its command or recorded evidence was actually inspected. Record unknown or unverified results explicitly.
- A release candidate requires the complete documented gate set, official Plugin Check/readme checks, deterministic build verification, artifact inspection, and separate authorization for every external release action.

## Git and SVN rules

- Inspect local status first and preserve all unrelated or pre-existing changes.
- Do not reset, revert, clean, delete, rename, overwrite, stage, commit, amend, tag, merge, push, fetch, pull, open/merge a PR, create a release, deploy, or contact a remote without explicit approval for that exact action.
- Never rewrite shared history or move an existing release tag without explicit authorization and a documented recovery plan.
- WordPress.org submission, review, SVN upload, and production deployment are separate actions. Do not create or modify WordPress.org SVN content before directory approval and explicit user authorization.
- Keep generated build artifacts outside version control unless the documented release procedure and explicit approval say otherwise.
- Show only task-relevant diffs; do not expose unrelated changes or sensitive values.

## Protected files and directories

Do not change these without explicit, task-specific approval:

- `.git/`, `.svn/`, Git/SVN configuration, hooks, refs, and metadata.
- Credentials, private keys, master keys, tokens, environment files, local configuration, or production configuration.
- `vendor/`, `composer.lock`, dependency metadata, generated caches, and generated build artifacts.
- Migration/schema behavior, encryption/key handling, authentication/authorization, privacy/retention, uninstall behavior, public API contracts, release manifests, or frozen reference fixtures.
- Existing release branches/tags, `main`, WordPress.org submission state, and any production or externally hosted system.

Never place secrets, credentials, private keys, personal data, sensitive URLs, or confidential machine-specific paths in documentation, logs, diffs, commits, or responses.

## Working rule

Inspect first, state assumptions and risks, make the smallest bounded change, validate proportionately, and show the focused diff. Ask before destructive actions, external actions, dependency installation, configuration changes, or any scope expansion. Preserve useful existing documentation and do not weaken instructions already in scope.
