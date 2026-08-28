# F11 missing and wrong key recovery evidence - 0.3.0

- Date: 2026-08-29
- Classification: `PASS_WITH_EVIDENCE`
- Environment: WordPress 7.1; PHP 8.2.4; MariaDB 10.4.28; single-site disposable runtime; Dreamax License Manager 0.3.0
- Scope: correct-key baseline, missing-key boot, wrong-key boot, recovery signals, blocked sensitive operations, public failure envelope, exact data/config preservation, restored-key boot, and private-clone cleanup
- Sensitive-data handling: no key material, KDF salt, ciphertext, fingerprint, configuration value, database value, license value, Cookie value, nonce, credential, private identifier, URL, screenshot, or private path is retained.

## Guarded recovery matrix

The verifier required the explicit disposable-environment marker, an existing encrypted disposable license, a uniquely replaceable cloned configuration definition, and zero earlier private-clone residue. It copied the local disposable WordPress installation into a private temporary clone while leaving the original configuration untouched. Worker output was captured and discarded; only fixed booleans and contract names were accepted as evidence.

All six top-level contracts passed:

- the correct-key baseline reported encryption ready, a good health result, and successful authenticated decryption of the existing encrypted row without returning its value;
- booting the clone without the constant entered recovery mode and produced the critical health signal;
- booting with deterministic synthetic wrong-key material entered the same recovery mode and health state;
- generated creation, import, pool assignment, and existing-row reveal/decryption attempts failed closed in both recovery modes;
- public validation returned the uniform `503 server_unavailable` contract before rate-limit, idempotency, or license work; and
- restoring the exact cloned configuration returned encryption to ready and authenticated decryption succeeded again.

The live test exposed and corrected an ordering gap: a well-formed wrong key could reach key-dependent public rate-limit work before the route returned its failure envelope. Public mutation and read routes now assert encryption readiness first. Focused source, coding-standard, syntax, and static-analysis checks cover that boundary.

## Preservation and cleanup proof

Before and after each worker, the verifier compared an internal digest covering every plugin table row plus the stored non-secret key identifier and site KDF-salt representation. Missing-key and wrong-key attempts made no plugin-data or key-metadata change. The original configuration hash remained identical, the cloned configuration was restored byte-for-byte, the correct key remained usable, no outbound email was sent, and exact cleanup removed the private clone with zero residue. The temporary deployment backup was removed only after the installed disposable source matched the verified repository source.

## Result

Missing or mismatched master-key material fails closed without changing encrypted records or key metadata, emits the documented recovery signal and uniform public failure, and recovers when the correct configuration returns. Database-plus-key disaster restoration remains under F12, and multisite provisioning/separation remains under F29/F30. F11 is `PASS_WITH_EVIDENCE`.
