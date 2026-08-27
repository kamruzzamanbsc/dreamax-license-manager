# F14 cache-header evidence — 0.3.0

- Dates: 2026-08-27 and 2026-08-28
- Classification: `PASS_WITH_EVIDENCE`
- Environment: WordPress 7.1; WooCommerce 11.0.1; PHP 8.2.4; MariaDB 10.4.28; HPOS enabled; Dreamax License Manager 0.3.0
- Scope: authenticated WooCommerce My Account licenses document response on the disposable direct application environment
- Sensitive-data handling: no screenshot, Cookie header, session value, URL, customer data, license value, credential, nonce, token, or private identifier is retained or referenced.

## Sanitized observation

The authenticated My Account licenses document response returned:

- `Cache-Control: no-cache, must-revalidate, max-age=0, no-store, private`
- `Expires: Wed, 11 Jan 1984 05:00:00 GMT`
- no observed `Pragma` response header

The response therefore demonstrated private, no-store application behavior for this direct authenticated page. No setting, repository file, plugin data, order, license, or database row was changed during the observation.

## Correction and sanitized re-test

The missing application-level `Pragma` guarantee was corrected on 2026-08-27. Both the authenticated My Account licenses document and its reveal response now use one explicit private-cache helper that sends:

- `Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0`
- `Pragma: no-cache`
- `Expires: Wed, 11 Jan 1984 05:00:00 GMT`

Source-contract regression coverage verifies both response paths and all three required headers. After that correction, a sanitized authenticated live re-test observed:

- `Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0`
- `Pragma: no-cache`
- `Expires: Wed, 11 Jan 1984 05:00:00 GMT`

No screenshot containing a Cookie header was retained or used as evidence.

## Guarded direct and intermediary verification

On 2026-08-28, a guarded verifier required the explicit disposable marker, active WordPress and WooCommerce plugins, exactly one existing synthetic registered-customer paid order with one assigned license, and an authenticated owner fixture. It created a short-lived session token without displaying or logging it and exercised four sensitive HTTP responses:

- the direct authenticated My Account licenses document;
- the direct successful reveal response;
- the same document through a loopback-only reverse intermediary; and
- the same successful reveal through that intermediary.

All four responses preserved the complete Cache-Control, Pragma, and Expires contract. The direct and intermediary documents contained the expected masked license row without the full key. Both successful reveal payloads matched the expected protected value only through an in-memory hash comparison.

The intermediary forwarded only the response headers required for this verification and did not forward or emit Set-Cookie. Its process was terminated after the bounded run.

## Restoration

The verifier restored the exact pre-run session-token metadata, restored the previous current-user and Cookie globals, deleted only reveal audit rows created inside its controlled window, confirmed the total audit count returned to its starting value, and zeroed the in-memory key when supported. Final output contained only sanitized booleans and counts.

## Result

The original partial observation, corrected manual re-test, source-contract coverage, and guarded authenticated direct/intermediary HTTP run jointly verify the F14 requirement. F14 is `PASS_WITH_EVIDENCE`.
