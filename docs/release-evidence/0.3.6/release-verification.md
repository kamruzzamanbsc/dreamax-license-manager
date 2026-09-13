# 0.3.6 release verification

## Scope

Version 0.3.6 hardens every Free runtime surface that directly reveals or exports encrypted license keys. Missing, changed, or invalid master-key material now degrades customer portals, order output, customer emails, resend operations, and CSV exports without exposing internal exceptions or changing stored encrypted records.

## Recorded source and artifact

- Release source commit: `983568208e28688d1502319ff1bd795a68b7c84b`.
- Artifact: `dreamax-license-manager-0.3.6.zip`.
- Artifact SHA-256: `85827db80c165d5f59e393aaf7c7d4ae42e918d6471c23819f862bd8504bf239`.
- Production inventory: 68 files.
- Reproducibility: two isolated builds produced identical ZIP hashes and inventories.
- The installed local artifact matched all 68 manifest paths and hashes with no extra files.

## Automated and local checks

- PHPUnit 10.5.64 on PHP 8.2.12: 288 tests and 1,872 assertions passed.
- PHPStan analyzed all 57 production PHP files with no errors.
- WordPress PHPCS completed with no output.
- Release metadata validation passed at version 0.3.6.
- Official Plugin Check 2.1.0 completed against the exact installed artifact with no errors.
- A non-persisted malformed-ciphertext smoke check confirmed safe standalone-dashboard, legacy-account, and order-display boundaries without printing key material.

The local fixture database contained no assigned customer/order records at verification time, so no real resend or export mutation was executed. Source contracts verify that decryption completes before reveal audit, export audit/download headers, and resend idempotency claims.

## WordPress.org publication

- WordPress.org SVN revision: `3693643`.
- Updated paths: `trunk`, immutable `tags/0.3.6`, and `assets/screenshot-7.png`.
- The tag and trunk contain the exact 68-file artifact inventory.
- Screenshot 7 is the previously approved redacted standalone customer dashboard image.
- The public Plugin Information API reports version 0.3.6 and the versioned download URL returns HTTP 200.
- The WordPress.org-generated ZIP contains 68 files and all 68 hashes match the release inventory; its container SHA-256 is `cbb55851cddf1d048cd8569dd5bdd2da033ea3e3dfd37768ebc3f974dae54a74`.
- The public screenshot 7 CDN asset returns HTTP 200.
