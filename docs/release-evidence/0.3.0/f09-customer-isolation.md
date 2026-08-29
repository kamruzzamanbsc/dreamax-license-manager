# F09 customer-isolation evidence — 0.3.0

- Date: 2026-08-27
- Classification: `PASS_WITH_EVIDENCE`
- Environment: WordPress 7.1; WooCommerce 11.0.1; PHP 8.2.4; MariaDB 10.4.28; HPOS enabled; Dreamax License Manager 0.3.0
- Scope: authenticated customer list, reveal IDOR, and registered-order license rendering
- Sensitive-data handling: no license key, customer, user, order, product, license, nonce, Cookie, URL, database, or private-path value is retained.

## Guarded live verification

The verifier required the explicit disposable marker, local/test environment guard, active WordPress and WooCommerce plugins, one existing paid synthetic registered-customer order with exactly one assigned license, and InnoDB for the WordPress user tables plus the plugin license and event tables.

One synthetic attacker customer was created inside a database transaction. The existing fixture owner and the attacker were exercised against the same protected resource without emitting either actor or resource identity.

The following six checks passed:

- the attacker My Account list contained neither the protected license identity nor its key;
- the owner My Account list contained the expected masked row without rendering the full key;
- an attacker-owned valid nonce did not authorize revealing another customer's license and the denial disclosed neither key nor private identity;
- the owner reveal path returned the expected key;
- registered-order rendering disclosed neither key nor private identity to the attacker; and
- registered-order rendering remained available to the owning customer.

This demonstrates that the rejection is an ownership decision rather than a malformed-request or CSRF rejection. Existing policy tests separately cover guest-order requirements and confirm that email knowledge alone is never an authorization input.

## Restoration

The successful owner reveal's audit event and the synthetic attacker user existed only inside the guarded transaction. The verifier restored request and actor state, rolled the transaction back, confirmed zero owned synthetic users remained, and proved the final user, user-metadata, license, and event aggregates matched the starting state.

## Result

The source contracts, order-access policy tests, and guarded live owner/attacker matrix jointly verify the F09 customer-isolation requirement. F09 is `PASS_WITH_EVIDENCE`.
