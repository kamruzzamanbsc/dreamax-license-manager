# F18 stable-identity evidence — 0.3.0

- Date: 2026-08-27
- Classification: `PASS_WITH_EVIDENCE`
- Environment: WordPress 7.1; WooCommerce 11.0.1; PHP 8.2.4; MariaDB 10.4.28; HPOS enabled; Dreamax License Manager 0.3.0
- Scope: product public identity across edits and duplication; installation identity across replay, label edit, reactivation, and a distinct installation
- Sensitive-data handling: no product, order, license, activation, customer, request, or database identifier; product label; installation value; license value; Cookie value; nonce; credential; URL; or private path is retained.

## Product identity

The guarded verifier selected exactly one previously verified licensed simple product without printing its identifier or label. It required InnoDB for the WordPress product and plugin tables before mutation.

Inside one rollback-protected transaction, the verifier:

- changed the source product label and confirmed its existing `prd_` public identity remained byte-identical;
- created a temporary duplicate carrying the copied source metadata;
- invoked the sole registered Dreamax duplicate-product callback through WordPress's hook dispatcher; and
- confirmed that the duplicate received a valid, distinct `prd_` public identity.

The transaction rolled back successfully. Post-rollback product/postmeta counts, the source label, and the source public identity matched the starting state, and the temporary duplicate no longer existed.

## Installation identity

The verifier created one owned temporary assigned license with activation limit 2. Its plaintext value and all generated identifiers remained internal and were never printed or retained.

The live activation service then demonstrated:

- first activation of one synthetic installation;
- active replay of that same installation returning the same activation identity;
- deactivation and reactivation with a changed human-readable label retaining the same activation identity while storing the edited label; and
- activation of a distinct installation producing a different activation identity.

Exactly two activation rows and five fixture-linked audit rows existed during the check: license creation, two first activations, one deactivation, and one reactivation. The verifier deleted only rows linked to the exact owned temporary license inside a cleanup transaction, zeroed the in-memory plaintext variable, and proved that aggregate license, activation, and event counts returned to their starting values.

## Automated regression coverage

The source-contract test confirms that product saves create a public identity only when missing, product duplication explicitly replaces the copied identity, installation fingerprints are derived only from the stable installation value rather than its editable label, the database enforces one activation row per license/fingerprint, and reactivation reuses the existing public identity.

## Result

Editable labels do not redefine product or installation identity, while true product duplication and a distinct installation receive new public identities. All temporary state was rolled back or ownership-scoped and removed. F18 is `PASS_WITH_EVIDENCE`.
