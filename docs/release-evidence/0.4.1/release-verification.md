# 0.4.1 release verification

## Scope and provenance

- Adds the Free-owned `license_expiry_extension_command.v1` commercial capability.
- Keeps expiry mutation, locking, validation, idempotency, persistence, and audit authority inside Free.
- Release source commit: `d4cd236895a1a0901d27a515cf3ef27f5675be43`.
- Candidate: `dreamax-license-manager-0.4.1.zip`, SHA-256 `182a017ee409ad141ab8507d9992a9651d8dd63e8282956c746fad69b74f4fad`.
- Production inventory: 118 files; two isolated builds produced identical inventories and ZIP hashes.

## Acceptance

- PHPUnit 10.5.64 / PHP 8.2.12: 354 tests and 2,247 assertions passed.
- PHPCS, PHPStan, release metadata validation, and ZIP structure validation passed.
- The live command probe confirmed bounded operation markers, exact replay, conflict denial, product binding, future-time rejection, one expiry mutation, one audit event, and fixture cleanup.
- The exact public WordPress.org package reported Free 0.4.1, contract version 1, seven capabilities, ready storage and encryption, a locked registry, and operational Pro compatibility.
- The installed local 0.3.6 plugin was restored byte-for-byte after the exact-package handshake.

## Publication

- WordPress.org SVN revision `3695488` updated `trunk` and created history-preserving `tags/0.4.1`; directory assets and earlier tags were unchanged.
- A fresh remote export of `tags/0.4.1` matched all 118 candidate file hashes.
- The Plugin Information API reported version 0.4.1 and the versioned package downloaded successfully.
- The WordPress.org-generated ZIP contains the same 118 production files with every SHA-256 file hash matching the candidate. Its container SHA-256 is `01ea4cb5ef735d9e8f7631490a1df613e9e2293ca19365d69a4f292488253aa2`.
