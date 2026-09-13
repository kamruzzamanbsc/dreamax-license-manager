# Free V1 branch disaster-recovery acceptance

Date: 2026-09-14. Unreleased `feature/free-v1-completion` branch. Disposable local WordPress 7.1 / WooCommerce 11.0.1 / PHP 8.2.12 / MariaDB 10.4.28.

The read-only diagnosis passed the disposable guard, 74-table source readability and clean verifier-residue checks. Because the database contained no eligible license, the confirmed run used `--create-fixture`. It generated one encrypted assigned license without printing its key, then treated that state as the backup baseline.

The guarded run passed eight contracts: backup equals source, independent restore equals backup, restored plugin state remains unchanged, database-only restore signals recovery and blocks decryption without mutation, restoring the exact external key returns Ready, and the pre-backup license both decrypts and validates. The original configuration remained unchanged. Cleanup removed both randomly named verifier databases, the private filesystem clone and the verifier license/audit row; the source database returned to its pre-fixture digest. No email was sent and output contained no sensitive value.

Command: `php scripts/verify-live-disaster-recovery.php --wp-root=<disposable-site> --environment-marker=DREAMAX_LM_DISPOSABLE_TEST --confirm-restore=DREAMAX_LM_F12_DISASTER_RECOVERY_CONFIRMED --create-fixture`.

Result: PASS for current-branch storage/recovery behavior. The earlier false coverage phrase "full backup wizard pending" is retired: the product records a tested database-plus-external-key backup acknowledgement but deliberately does not create backup archives.
