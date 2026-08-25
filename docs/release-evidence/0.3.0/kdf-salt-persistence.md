# KDF salt persistence evidence — 0.3.0

- Date: 2026-08-26
- Scope: focused first-time KDF salt persistence correction
- Secret handling: no root key, KDF salt, derived key, credential, customer data, configuration value, or database content is recorded in this evidence.

## Runtime root cause

The disposable WordPress runtime confirmed that PHP, native Sodium, WooCommerce, the configured root-key shape, and the non-secret stored root identifier were ready and matched. No license, credential, claim, owner, or audit rows existed. First readiness stored the ASCII root identifier but attempted to store the 32-byte KDF salt as arbitrary raw binary in the text-backed WordPress options table. The salt write did not persist, so the encrypt and decrypt halves of the readiness self-test generated different salts and recovery mode remained active.

## Corrected storage contract

- Generate exactly 32 bytes with `random_bytes()` only after the root identifier matches and protected-data checks are empty.
- Persist `v1.` plus the 43-character unpadded base64url encoding in the current site's options table with autoload disabled.
- Use one database-atomic insert whose duplicate branch cannot overwrite the existing value; all contenders then perform an authoritative read.
- Strictly validate the version, alphabet, canonical encoding, and decoded 32-byte length before deriving any key.
- Fail closed with one non-sensitive error on read, write, validation, identifier, protected-data, or read-back failure.
- Accept a historical raw 32-byte value and replace only its representation; authoritative read-back must decode to identical bytes before use.

## Automated evidence

| Gate | Result |
| --- | --- |
| `composer dump-autoload -o --strict-ambiguous` | Exit 0; 1,609 optimized classes; no ambiguity reported |
| `composer validate --no-check-publish` | Exit 0; valid configuration |
| Full WordPress PHPCS | Exit 0; no violation reported |
| PHPStan | Exit 0; 52/52 source files |
| Complete PHPUnit suite | Exit 0; 150 tests, 920 assertions |
| Production PHP syntax lint | Exit 0; 54/54 files |
| Portable autoload verification | Exit 0; 52 unique class/interface symbols resolved with exact case-sensitive paths |
| Main-baseline identity verification | Exit 0; 41 baseline identities and 11 intentional additions; no missing, duplicate, or stale path |
| Version consistency | Exit 0; plugin header, runtime constant, and stable tag are `0.3.0` |
| `git diff --check` | Exit 0 |
| Non-content-printing secret/private-path/prohibited-artifact scan | Exit 0; no finding |

## Live disposable-site replacement verification

The unshipped release-candidate ZIP built from commit `88db7b45175e939c00bd8f542cc815c98b40f941` was installed over the existing plugin with WordPress **Replace current with uploaded**. The plugin was not uninstalled or deleted. The existing root-key configuration and stored non-secret identifier were left unchanged and were not regenerated.

On the first fresh request after replacement, System Status reported Encryption **Ready**, the license table engine **InnoDB**, and the cleanup job **Scheduled**; no recovery notice was present. A second hard refresh reported the same three states and no recovery notice. This is live evidence that the corrected first-time KDF salt persistence path reached readiness and remained ready across separate requests on this disposable site.

This observation is limited to a fresh, disposable, single-site database without protected plugin data. It does not exercise an absent or wrong root key, encrypted-data or database restoration, disaster recovery, multisite separation, concurrent HTTP initialization, uninstall behavior, order processing, or mail delivery. Those gates remain open. No salt, key, identifier, credential, configuration content, private path, or screenshot is included in this record.
