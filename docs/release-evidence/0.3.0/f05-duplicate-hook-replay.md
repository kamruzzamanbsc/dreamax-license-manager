# F05 duplicate-hook replay evidence — 0.3.0

- Date: 2026-08-27
- Classification: `PASS_WITH_EVIDENCE`
- Environment: WordPress 7.1; WooCommerce 11.0.1; PHP 8.2.4; MariaDB 10.4.28; HPOS enabled; Dreamax License Manager 0.3.0
- Scope: repeated Dreamax allocation callbacks for the WooCommerce Processing and Completed status hooks
- Sensitive-data handling: no order, item, customer, product, license, request, or database identifier; license value; email address; Cookie value; nonce; credential; URL; or private path is retained.

## Guarded live verification

The verifier required the explicit disposable marker, a reserved or loopback site URL, a test-named database, the active plugin, and InnoDB for every table that could hold the asserted license, event, or order-note state. It selected exactly one qualifying existing paid synthetic order internally without printing any identifier or private value.

The selected order already had the exact F03 baseline: one simple virtual generated-license item with quantity 1, one assigned slot, and exactly one created, assigned, delivered, and automatic-allocation event.

The live WordPress hook registry contained exactly one Dreamax `OrderLicensing::allocate` callback on each of the Processing and Completed status hooks. The verifier isolated those registered Dreamax callbacks in memory so unrelated WooCommerce listeners, notifications, or external actions could not run, then used WordPress `do_action()` to replay each hook twice.

During and after the replay:

- the order and item each still had exactly one license;
- created, assigned, delivered, and automatic-allocation event counts each remained exactly one;
- no duplicate quantity slot, audit event, or order note was created;
- aggregate plugin license and event counts and the complete selected-license row digest were unchanged; and
- the guarded transaction rolled back successfully, with the post-rollback snapshot exactly matching the starting snapshot.

The verifier emitted only fixed labels, counts, booleans, and environment classifications. No email was sent and no sensitive value was decrypted or printed.

## Automated regression coverage

The source-contract test confirms that:

- both paid status hooks use the same allocation handler;
- the handler checks the existing in-memory and database order slot before license creation;
- automatic-allocation audit and order-note writes require a positive new-allocation count; and
- the live verifier replays both registered hooks inside a rollback-protected transaction.

## Result

Repeated Processing and Completed allocation-hook delivery converged on the existing order slot without adding a license, delivery event, allocation event, or order note. F05 is `PASS_WITH_EVIDENCE`.
