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

## Missing or wrong key

Recovery mode blocks key-dependent reads and all sensitive writes. Do not generate a replacement, reset metadata, or alter ciphertext. Restore the correct constant. Recovery clears only after the identifier matches and authenticated decryption/self-test succeeds. Contact the person responsible for backups if the external key is unavailable; encrypted keys cannot be reconstructed from the database alone.

## Multisite

One root may be present in network `wp-config.php`, but derived keys include the blog identity and a random per-site salt. Restore each site's options with its tables. Never copy only ciphertext into another site's namespace and expect it to decrypt.
