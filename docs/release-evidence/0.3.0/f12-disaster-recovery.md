# F12 disaster-recovery evidence - 0.3.0

- Date: 2026-08-29
- Classification: `PASS_WITH_EVIDENCE`
- Environment: WordPress 7.1; PHP 8.2.4; MariaDB 10.4.28; single-site disposable runtime; Dreamax License Manager 0.3.0
- Scope: complete database backup, independent database restore, database-only recovery behavior, exact external-key restoration, pre-backup license decryption and validation, source preservation, and exact cleanup
- Sensitive-data handling: no root key, KDF salt, ciphertext, license value, key identifier, database credential, configuration value, Cookie value, nonce, private identifier, URL, screenshot, or private path is retained.

## Guarded backup and restore matrix

The verifier required the explicit disposable-environment marker, a local test database name, one eligible encrypted pre-backup license, zero earlier F12 database or clone residue, and a separate destructive-action confirmation. The original WordPress installation and database were treated as read-only inputs. A private file clone, a randomly named verifier-owned backup database, and a distinct randomly named verifier-owned restore database were created only after the guarded preflight passed.

All eight recorded contracts passed:

- the complete backup schema, row counts, and table checksums matched the disposable source;
- the independent restored database matched the complete backup;
- plugin-owned tables and server-side hashes of key-identifier/KDF-salt metadata remained unchanged through both restore boots;
- booting the restored database without the external key entered recovery mode and produced the critical recovery signal;
- the database-only restore blocked authenticated decryption of the pre-backup encrypted row;
- restoring the exact backed-up configuration returned encryption health to Ready;
- the pre-backup license decrypted and validated successfully after complete restoration; and
- the original database and configuration retained their starting digests.

The first diagnostic runs safely exposed two verifier-only comparison defects: copied `AUTO_INCREMENT` display metadata was normalized for schema equivalence, and expected WordPress boot metadata was excluded from the protected plugin-state boundary. Neither run promoted the gate. Both runs removed their exact temporary databases and private clone before the corrected matrix was executed.

## Cleanup proof

The final run dropped only the two exact random verifier-owned databases and removed only the exact private temporary clone. A post-run guarded diagnosis found zero F12 database or clone residue. No outbound email was sent, no original site/configuration/database mutation was retained, and all output consisted of fixed sanitized booleans and counts.

## Result

A complete database-plus-external-key backup restores the existing encrypted license and its validation behavior, while a database-only restore fails closed with the documented recovery signal. F12 is `PASS_WITH_EVIDENCE`. Multisite restore separation remains under F29/F30.
