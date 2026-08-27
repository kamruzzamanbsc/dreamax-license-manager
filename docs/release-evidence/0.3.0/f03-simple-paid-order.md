# F03 simple paid-order evidence — 0.3.0

- Date: 2026-08-27
- Classification: `PASS_WITH_EVIDENCE`
- Environment: WordPress 7.1; WooCommerce 11.0.1; PHP 8.2.4; MariaDB 10.4.28; HPOS enabled; Checkout Block; Dreamax License Manager 0.3.0; Mailpit 1.31.0 bound to loopback only
- Scope: one paid simple virtual licensed-product order using Direct bank transfer, exact per-quantity allocation, customer surfaces, audit events, and customer processing-email delivery
- Sensitive-data handling: no order ID, customer ID, email address, license value, message ID, Cookie value, nonce, credential, URL, database value, or private path is retained.

## Existing live workflow observations

The same disposable order had already demonstrated:

- a published simple virtual product with licensing enabled, generated-key source, per-quantity issuance, activation limit 2, 30-day validity, and retain-unchanged refund/cancellation policies;
- storefront, cart, Checkout Block, Direct bank transfer, and the transition to Processing;
- one generated, assigned, and delivered license for purchased quantity 1;
- Order tools showing one current license and zero missing slots;
- authenticated My Account masked display plus reveal persistence; and
- corresponding created, assigned, delivered, and automatic-allocation activity entries.

The original PHP mail transport could not instantiate mail delivery, so that partial observation alone was not treated as a pass.

## Sanitized live mail-catcher verification

The guarded verifier selected exactly one qualifying existing order without printing its identifiers. It independently confirmed:

- status Processing and payment method Direct bank transfer;
- one simple virtual product line with purchased quantity 1;
- licensing enabled with generated source and per-quantity issuance;
- one assigned license in slot 1, target slots 1, and zero missing slots; and
- exactly one `license_created`, one `license_assigned`, and one `license_delivered` event for that license.

WooCommerce's enabled customer Processing-order notification was then sent through Mailpit bound only to the loopback interface. Exactly one message was captured. The verifier decrypted the already-assigned license only in memory, confirmed that the captured message contained that exact value, and zeroed the plaintext variable without printing or retaining it.

The captured message was deleted immediately. Final checks found zero Mailpit messages, unchanged aggregate plugin license/event rows, and unchanged order-note count for the passing run. An earlier diagnostic send created WooCommerce's normal private successful-email order note before the verifier began suppressing test-run email logging; it contains no retained recipient or license value and remains only on the disposable order as an accurate audit record. No email left the local machine.

## Result

The previously observed paid-order, allocation, customer-display, and activity path now has concrete local delivery evidence. The configured quantity was delivered exactly once and the processing email contained the assigned license without exposing it. F03 is `PASS_WITH_EVIDENCE`.
