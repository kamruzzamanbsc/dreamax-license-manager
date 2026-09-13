# Free V1 branch preflight (not a release-candidate approval)

Date: 2026-09-13. Source branch: `feature/free-v1-completion`.
Runtime: PHP 8.2.12; PHPUnit 10.5.64; Composer 2.8.5.

## F17 entropy

Command: `php -d extension=zip vendor/bin/phpunit --filter KeyGeneratorTest`
Result: 12 tests, 14 assertions, no failures. The tested cases include default entropy of at least 128 bits and rejection of a 95-bit two-character-alphabet configuration. Source: `tests/Unit/KeyGeneratorTest.php`.

## F21 preliminary checks

- Full branch PHPUnit (latest preview preflight): 332 tests, 2101 assertions, no failures.
- `composer phpcs`: passed.
- `composer analyse`: 72 files, no errors.
- `composer audit --locked --no-interaction`: no security vulnerability advisories found in the lockfile.

These checks are preliminary. A candidate-specific manual security review, full disposable-runtime regression, reproducible release build, and official plugin-directory checks are still required. F21 remains open.
