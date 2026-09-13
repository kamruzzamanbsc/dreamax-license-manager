# Setup and diagnostics regression acceptance

Date: 2026-09-14. Unreleased `feature/free-v1-completion` branch. Guarded local WordPress 7.1 / WooCommerce 11.1.0 / PHP 8.2.12 / MariaDB 10.4.28.

Command: `wp eval-file scripts/verify-live-setup-diagnostics.php --path=<guarded-disposable-site>`.

Observed: 10 runtime contracts passed. An administrator rendered the System Status screen with nonce-bearing report and email-test forms. The complete 12-check diagnostic and report shapes were present; encryption, schema/InnoDB, and private no-store REST ping were ready. Neither the rendered screen nor JSON report contained the configured master key; the report excluded the database name and administrator email. No email was sent, and the script restored the prior user context.

The test checks the configured environment rather than changing backup acknowledgement or sending mail. Existing F10/F11/F12/F29 evidence covers capabilities, recovery, database-plus-key restoration, and site-bound key derivation. An authenticated browser walkthrough and exact-package Plugin Check remain release gates.

Result: PASS for the current branch setup/diagnostics runtime contract, not yet an SVN release authorization.
