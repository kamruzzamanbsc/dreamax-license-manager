# Free V1 release security verification

Date: 2026-09-13. Source branch: `feature/free-v1-completion`.
Runtime: PHP 8.2.12; PHPUnit 10.5.64; Composer 2.8.5.

## F17 entropy

Command: `php -d extension=zip vendor/bin/phpunit --filter KeyGeneratorTest`
Result: 12 tests, 14 assertions, no failures. The tested cases include default entropy of at least 128 bits and rejection of a 95-bit two-character-alphabet configuration. Source: `tests/Unit/KeyGeneratorTest.php`.

## F21 final checks

- Final 0.3.8 PHPUnit: 336 tests, 2141 assertions, no failures.
- `composer phpcs`: passed.
- `composer analyse`: 72 files, no errors.
- `composer audit --locked --no-interaction`: no security vulnerability advisories found in the lockfile.
- Official Plugin Check 2.1.0 on the exact 84-file 0.3.8 artifact: no errors. The two dynamic-SQL static warnings were manually traced to fixed allowlisted identifiers and separately prepared values.
- Candidate-specific source review covered changed admin action capability/nonce checks, REST route validation and permission callbacks, customer ownership boundaries, recovery-safe key access, private CSV files, formula escaping, bounded resumable jobs, and exact-owner worker locks. No unresolved critical or high-severity issue remained.
- The review found one paid-order eligibility gap in the automatic allocation path. Version 0.3.8 closes it at both the status hook and core allocation boundary; a guarded disposable WooCommerce drill confirmed both paths reject an unpaid order and remove all temporary fixtures.

The reproducible candidate build, runtime regression set, and exact-package checks completed without an unresolved release blocker.
