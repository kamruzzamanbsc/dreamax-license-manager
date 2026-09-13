# 0.3.8 release verification

## Scope and provenance

- Enforces paid-order eligibility at both the WooCommerce status hook and the shared allocation service boundary.
- Release source commit: `98c3b6f7019248f74ed1889c0f0144d58094821a`.
- Candidate: `dreamax-license-manager-0.3.8.zip`, SHA-256 `0521bfd812553adb04dc1b3a33f6ade389e2561132f0fa1acfb167fc34af1f38`.
- Production inventory: 84 files; two isolated builds produced identical inventories and ZIP hashes.

## Acceptance

- PHPUnit 10.5.64 / PHP 8.2.12: 336 tests and 2,141 assertions passed.
- PHPCS, PHPStan, Composer dependency audit, and release metadata validation passed.
- Official Plugin Check 2.1.0 on the exact production files reported no errors. Two reviewed static SQL warnings remain for fixed allowlisted identifiers and separately prepared values.
- A guarded disposable WooCommerce runtime drill selected `pending` as an automatic delivery status and confirmed that both the status hook and direct allocation service rejected the unpaid order. Its product, order, and any owned plugin rows were removed, and the installed test file was restored byte-for-byte.
- Candidate-specific source review found no remaining critical or high-severity issue.

## WordPress.org publication

- SVN revision `3694210` updated `trunk` and created immutable `tags/0.3.8`; directory assets and earlier tags were unchanged.
- Staged `trunk` matched all 84 candidate file hashes with no extra files.
- The Plugin Information API reported version 0.3.8 and the versioned download returned HTTP 200 after normal CDN propagation.
- The WordPress.org-generated ZIP contains the same 84 production files with every SHA-256 file hash matching the candidate. Its container SHA-256 is `0e8331c28923e6fd5d20117714d16233bd7710261057a231e9304863f960e963`.
