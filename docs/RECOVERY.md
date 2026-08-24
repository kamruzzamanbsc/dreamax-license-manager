# Encryption setup, backup, and recovery

## Provision once

1. In License Manager → System status, generate the setup snippet over an authorized no-store response.
2. Place the constant in `wp-config.php` before the stop-editing line.
3. Reload System status and confirm the authenticated encryption self-test passes.
4. Store the root value in a separate protected secret backup. The plugin stores only a non-secret identifier and site KDF salt.

No retrievable production license may be created before readiness.

## Complete backup

A complete backup contains the WordPress database, the exact external `DREAMAX_LICENSE_MANAGER_MASTER_KEY` value, and ordinary WordPress/WooCommerce files/configuration. A database-only copy is incomplete.

## Disposable restore drill

Restore into a separate, disposable site/database; restore the same root constant; keep the copied site salt/options; confirm the non-secret key identifier; reveal and validate a pre-backup license; record environment, date, reviewer, and evidence in the release gate. Never test this by replacing the production key.

## Missing or wrong key

Recovery mode blocks key-dependent reads and all sensitive writes. Do not generate a replacement, reset metadata, or alter ciphertext. Restore the correct constant. Recovery clears only after the identifier matches and authenticated decryption/self-test succeeds. Contact the person responsible for backups if the external key is unavailable; encrypted keys cannot be reconstructed from the database alone.

## Multisite

One root may be present in network `wp-config.php`, but derived keys include the blog identity and a random per-site salt. Restore each site's options with its tables. Never copy only ciphertext into another site's namespace and expect it to decrypt.
