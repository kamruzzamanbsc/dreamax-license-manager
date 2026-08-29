# F29/F30 multisite isolation evidence - 0.3.0

- Date: 2026-08-29
- Classification: `PASS_WITH_EVIDENCE`
- Environment: WordPress 7.1; WooCommerce 11.0.1; PHP 8.2.4; MariaDB 10.4.28; private disposable multisite clone; Dreamax License Manager 0.3.0
- Scope: existing-site and future-site initialization; per-site key derivation and restore; blog-local storage, credentials, API selection, exports, jobs, and permanent uninstall; network activation/deactivation; exact source preservation and cleanup
- Sensitive-data handling: no root key, KDF salt, license value, credential, Cookie value, nonce, private identifier, database name, configuration content, URL, or private path is retained.

## Guarded isolation

The verifier required the explicit disposable-environment marker, a readable single-site source database, active Dreamax plugin, encryption readiness, and zero earlier F29/F30 clone or database residue. It used the source only to create one randomly named private file clone and one randomly named database clone. The current production plugin files were overlaid into that clone before verification.

The cloned database was converted deterministically into a subdirectory multisite network. Multisite-only global tables were reset only in the private clone before WordPress populated a fresh network. One site was created before network activation and another after network activation. All destructive operations were restricted to exact verifier-owned targets.

## Live contracts

The final run passed 26 fixed contracts:

- network activation initialized the existing main and secondary sites independently;
- the `wp_initialize_site` lifecycle initialized the site created after network activation;
- all three sites received the site-local schema, administrator capabilities, and one bounded cleanup schedule;
- distinct site salts and blog-bound derived keys prevented cross-site ciphertext decryption;
- removing one site's salt with protected data present failed closed, restoring that exact site's option returned it to Ready, and another site remained unaffected;
- license rows, credentials, API reads, export selection, and audit data remained inside the active blog prefix;
- network-wide deactivation cleared each site's cleanup schedule and reactivation restored each schedule;
- confirmed permanent uninstall removed only the selected site's tables/options while the other sites and their data remained ready; and
- no outbound email was sent.

The earlier F29 single-site observation remains valid: first initialization reached Encryption Ready and the persisted KDF salt remained stable across refresh and in-place replacement. F11 separately proves that protected creation and public licensing fail closed before encryption readiness.

## Implementation corrections

The run exposed and corrected three lifecycle gaps:

- network-active installations now initialize sites created later through `wp_initialize_site`;
- network-wide deactivation now clears the cleanup schedule independently on every existing site; and
- permanent-uninstall confirmation now accepts the exact WordPress-persisted enabled representation while retaining data for every other value.

Regression contracts keep permanent uninstall current-site scoped and prohibit network enumeration from the uninstall handler.

## Cleanup and source preservation

The verifier compared value-free source database and configuration digests after the matrix. The original disposable site remained unchanged. The exact temporary database and private file clone were removed, a post-run diagnosis found no stale F29/F30 residue, and no sensitive output was produced.

## Result

F29 and F30 are `PASS_WITH_EVIDENCE`. The gate ledger is 31 `PASS_WITH_EVIDENCE`, 2 `MANUAL_ENVIRONMENT_REQUIRED`, 0 `AUTOMATABLE_PENDING`, and 0 `IMPLEMENTATION_BLOCKER`.
