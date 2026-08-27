# F27 privacy exporter/eraser evidence - 0.3.0

- Date: 2026-08-28
- Classification: `PASS_WITH_EVIDENCE`
- Environment: WordPress 7.1; WooCommerce 11.0.1; PHP 8.2.4; MariaDB 10.4.28; HPOS enabled; Dreamax License Manager 0.3.0
- Scope: WordPress privacy callback registration; bounded export; partial and repeated erasure; cross-customer isolation; business-record retention; audit; exact cleanup
- Sensitive-data handling: no user, license, activation, claim, order, event, product, request, database, or private identifier; email address; password; license value; installation value; ownership/proof hash; Cookie value; nonce; credential; URL; or private path is retained.

## Guarded live matrix

The verifier required the explicit disposable-environment marker, active plugin, and InnoDB for all WordPress user and plugin tables touched by fixtures or cleanup. It created two synthetic customers without sending mail.

Customer A received 101 temporary assigned licenses, one activation carrying temporary personal fields, one issued guest claim, and one claimed-owner row. Customer B received one equivalent license/claim/owner sentinel set.

The registered WordPress exporter callbacks demonstrated:

- page one returned the 100-license boundary plus the customer-A claim and correctly reported more license data;
- page two returned the final license and reported completion;
- customer B returned only its own license and claim;
- an unknown synthetic email returned no data; and
- no plaintext license, proof/hash, ciphertext, or customer-email field appeared in the export structure.

The registered eraser callbacks demonstrated:

- the first call anonymized the first 100 licenses and reported that another page remained;
- the second anonymized the final license and reported completion;
- the third repeated call was an idempotent no-op;
- all 101 license business records remained while their direct customer links, encrypted-email snapshot, and free-form metadata were cleared;
- the activation label, network fingerprint, and metadata were cleared;
- the claim's target-account link, active-order uniqueness slot, and proof hash were cleared and its outstanding status was invalidated;
- the direct claimed-owner row was removed; and
- the customer-B license, claim, owner, and export result remained unchanged.

Exactly two privacy-anonymization audit events were created: the first recorded the 100-license/one-claim/one-owner boundary, and the second recorded the final one-license page without another claim or owner.

## Cleanup and regression coverage

An ownership-scoped InnoDB cleanup transaction removed only rows linked to the temporary licenses, privacy events, claims, owner references, and users. User, usermeta, license, activation, event, claim, and owner aggregates matched the starting snapshot.

The source-contract test confirms bounded customer-scoped export, exclusion of plaintext/proof fields, personal-field anonymization, retained integrity fields, privacy audit emission, 101-row partial processing, repeated erasure, cross-customer isolation, and exact owned cleanup guards.

## Result

The live WordPress exporter and eraser are bounded, privacy-minimizing, repeatable, customer-isolated, and integrity-preserving. F27 is `PASS_WITH_EVIDENCE`.
