# 0.3.7 release verification

## Scope and provenance

- Free-tier merchant operations, setup diagnostics, inventory and activation views, customer portal and REST regressions, and queued CSV import hardening.
- Release source commit: `7b7755ce6b81854dc32448c98acd6031a6baf21f`.
- Candidate: `dreamax-license-manager-0.3.7.zip`, SHA-256 `76d36eecfb883f688bcaec1b8aaf59fcd6dfd0945a92e16dc6c0ac3f247af31e`.
- Production inventory: 84 files; two isolated builds had identical inventories and ZIP hashes.

## Acceptance

- PHPUnit 10.5.64 / PHP 8.2.12: 336 tests and 2,138 assertions passed.
- PHPCS, PHPStan, Composer dependency audit, and release metadata validation passed.
- Official Plugin Check 2.1.0 on the exact production files reported no errors. Two remaining static SQL warnings were reviewed: admin sorting uses fixed allowlists, and CSV export fragments contain only fixed identifiers and placeholders whose values are prepared.
- Guarded local runtime verifiers covered customer isolation, frozen public client behavior, public lifecycle and idempotency, capability isolation, inventory filters, setup diagnostics, and a real multipart queued CSV import. Their fixture records and private files were cleaned up.

## WordPress.org

- SVN revision `3694187` updated `trunk` and created `tags/0.3.7`; existing directory assets and earlier tags were unchanged.
- Staged `trunk` matched all 84 candidate file hashes with no extra files.
- The Plugin Information API reported version 0.3.7 and its versioned download URL. The public download returned HTTP 200.
- The WordPress.org-generated ZIP contains the same 84 production files with all SHA-256 file hashes matching the candidate. Its container SHA-256 is `e132cb6cdd6ebd43e307eba8f4ef5f0d323bd1157a3beb2ab74f9dfb03af19c4`.
