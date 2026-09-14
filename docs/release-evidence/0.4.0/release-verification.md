# 0.4.0 release verification

## Scope and provenance

- Adds the versioned `Dreamax\LicenseManager\Contracts\Commercial\V1` compatibility surface while Free remains the authority for license, activation, storage, and encryption decisions.
- Adds exact installation proof verification and a bounded provider registry that locks after commercial bootstrap.
- Release source commit: `6537aaf7215ae0cba1162ba30650ad605ca90ad6`.
- Candidate: `dreamax-license-manager-0.4.0.zip`, SHA-256 `c450621e919a9cc4aec8b0545abf698d17ea5fb1a0c6819477e9f0b6ca7d5748`.
- Production inventory: 114 files; two isolated builds produced identical inventories and ZIP hashes.

## Acceptance

- PHPUnit 10.5.64 / PHP 8.2.12: 348 tests and 2,205 assertions passed.
- PHPCS, PHPStan, Composer dependency audit, release metadata validation, and ZIP structure validation passed.
- Official Plugin Check 2.1.0 update-mode scans passed against the exact package in both inactive and active states with no errors.
- Plugin Check retained two reviewed static SQL warnings: the admin inventory query restricts dynamic identifiers to fixed allowlists and prepares values, while the CSV export query assembles fixed fragments and prepares all values.
- The local Free-Pro runtime verifier confirmed contract version 1, six advertised capabilities, ready storage and encryption, a locked registry, operational Pro compatibility, and rejection of an invalid installation proof.
- The installed local 0.3.6 plugin and its original activation state were restored after exact-package checks.

## Publication

- WordPress.org SVN revision `3694299` updated `trunk` and created immutable `tags/0.4.0`; directory assets and earlier tags were unchanged.
- A fresh remote export of `tags/0.4.0` matched all 114 candidate file hashes.
- The Plugin Information API reported version 0.4.0 and the versioned download returned HTTP 200.
- The WordPress.org-generated ZIP contains the same 114 production files with every SHA-256 file hash matching the candidate. Its container SHA-256 is `08e15dbd169604affc0bae19b3a346330dc9e2c8cd26b96b1ad04211e7c4682d`.
