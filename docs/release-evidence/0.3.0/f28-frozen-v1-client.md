# F28 frozen v1 client evidence — 0.3.0

- Date: 2026-08-27
- Classification: `PASS_WITH_EVIDENCE`
- Environment: WordPress 7.1; WooCommerce 11.0.1; PHP 8.2.4; MariaDB 10.4.28; HPOS enabled; Dreamax License Manager 0.3.0
- Scope: execute the committed unchanged v1 reference client against the disposable live REST service
- Sensitive-data handling: no license, product, installation, activation, request, rate-bucket, idempotency, customer, Cookie, nonce, credential, URL, database, or private-path value is retained.

## Live reference-client execution

The guarded runner hashed the committed frozen client before execution, created one owned temporary assigned license, and passed its required values only through the child process environment. It never printed or retained those values.

The disposable Apache configuration uses plain permalinks and does not route `/wp-json` paths. Rather than editing the frozen client or permanently changing site configuration, an ephemeral PHP server bound only to loopback adapted the client's fixed pretty REST path to WordPress's supported `rest_route` query form. Request bodies, content type, idempotency header, response status, and JSON body were relayed without semantic changes. The adapter was closed after the run.

The unchanged client passed its complete required flow:

- initial activation and exact idempotent replay;
- same idempotency key with a changed payload returning `idempotency_conflict`;
- live validation returning `license_valid`; and
- live deactivation returning `license_deactivated`.

The runner restored the previous loopback-HTTP option, restored every known shared rate-limit bucket to its exact pre-run state, removed the two idempotency rows by the owned activation result identity, removed only activation/events/license rows linked to the owned temporary license, zeroed the plaintext variable, and proved aggregate plugin rows matched their starting counts. The frozen client hash was unchanged after execution.

## Result

The committed unchanged v1 client passed all required contracts against the live disposable v1 service with exact cleanup and no sensitive output. F28 is `PASS_WITH_EVIDENCE`.
