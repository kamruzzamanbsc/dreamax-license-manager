# Encryption setup, backup, and recovery

## Provision once

1. In License Manager → System status, generate the setup snippet over an authorized no-store response.
2. Place the constant in `wp-config.php` before the stop-editing line.
3. Reload System status and confirm the authenticated encryption self-test passes.
4. Store the root value in a separate protected secret backup. The plugin stores only a non-secret identifier and site KDF salt.

No retrievable production license may be created before readiness.

The site-local KDF salt is exactly 32 random bytes stored in the current site's WordPress options table as `v1.` followed by 43 characters of unpadded base64url. First initialization uses a database-atomic insert that never replaces a concurrent winner, then reads the authoritative stored value back before deriving a key. Missing, malformed, incorrectly sized, unverified, or unpersisted values fail closed. A missing salt is initialized only when the configured root identifier matches and no salt-dependent records exist.

Historical raw 32-byte option values remain readable. On first use they are rewritten to the versioned ASCII representation, read back, decoded, and accepted only when the bytes are identical, so derived keys and existing ciphertext do not change.

## Complete backup

A complete backup contains the WordPress database, the exact external `DREAMAX_LICENSE_MANAGER_MASTER_KEY` value, and ordinary WordPress/WooCommerce files/configuration. A database-only copy is incomplete.

## Disposable restore drill

Restore into a separate, disposable site/database; restore the same root constant; keep the copied site salt/options; confirm the non-secret key identifier; reveal and validate a pre-backup license; record environment, date, reviewer, and evidence in the release gate. Never test this by replacing the production key.

### Recorded disaster-recovery drill

On 2026-08-29, a guarded private-clone drill copied the complete disposable database into a verifier-owned backup database and then into a distinct restore database. The schema, row counts, and table checksums matched across the source, backup, and pre-boot restore. A database-only restore entered recovery mode, emitted the critical recovery signal, and blocked decryption. Restoring the exact backed-up external-key configuration returned encryption to Ready and allowed a pre-backup license to decrypt and validate. Plugin-owned rows and server-side hashes of the key identifier and KDF salt remained unchanged, the original configuration and database retained their starting digests, and exact cleanup removed both temporary databases and the private clone. No protected value or private path was retained.

The 2026-09-14 Free V1 branch rerun used the verifier's explicit `--create-fixture` mode because the disposable source had no eligible license. Only after the exact destructive confirmation did it create one encrypted assigned fixture, include it in the backup and independent restore, prove the same database-only and complete-key outcomes, then remove the fixture and restore the source database digest. All eight recovery contracts and cleanup passed. See `docs/release-evidence/free-v1/disaster-recovery.md`.

Free V1 does not rotate the encryption root automatically. A future rotation implementation must be versioned, resumable and rollback-safe; until that separately tested migration exists, replace neither the configured root nor stored key identity. API credential rotation is independent and is documented in ADR 0003.

## Missing or wrong key

Recovery mode blocks key-dependent reads and all sensitive writes. Do not generate a replacement, reset metadata, or alter ciphertext. Restore the correct constant. Recovery clears only after the identifier matches and authenticated decryption/self-test succeeds. Contact the person responsible for backups if the external key is unavailable; encrypted keys cannot be reconstructed from the database alone.

### Recorded disposable recovery drill

On 2026-08-29, a guarded private-clone matrix booted the recorded disposable WordPress/MariaDB runtime with the correct configuration, without the constant, with deterministic synthetic wrong-key material, and with the exact correct configuration restored. Missing and wrong modes showed recovery health, blocked creation/import/assignment/reveal work, and returned the uniform public `503 server_unavailable` response before key-dependent rate, idempotency, or license work. An internal digest covering every plugin row and the key-identifier/KDF-salt options remained unchanged. The original configuration was never modified, the clone was restored byte-for-byte before cleanup, authenticated decryption succeeded again after restoration, and zero clone residue remained. No key, salt, ciphertext, fingerprint, identifier value, private path, or configuration content was retained.

This drill closes only the missing/wrong-key gate. The separate database-plus-key restore drill under F12 is also recorded above; F29/F30 independently verifies site-local multisite key separation and restore behavior.

## Multisite

One root may be present in network `wp-config.php`, but derived keys include the blog identity and a random per-site salt. Restore each site's options with its tables. Never copy only ciphertext into another site's namespace and expect it to decrypt.

The guarded F29/F30 private-clone drill proved that distinct site salts and blog-bound derived keys reject cross-site ciphertext, a missing salt with protected rows fails only that site closed, restoration of that exact site's salt returns it to Ready, and another site remains unaffected. The drill retained no key, salt, ciphertext, identifier value, configuration content, or private path.
