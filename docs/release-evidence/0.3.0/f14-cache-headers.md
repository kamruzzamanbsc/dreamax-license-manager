# F14 cache-header partial evidence — 0.3.0

- Date: 2026-08-27
- Classification: `MANUAL_ENVIRONMENT_REQUIRED`
- Environment: WordPress 7.1; WooCommerce 11.0.1; PHP 8.2.4; MariaDB 10.4.28; HPOS enabled; Dreamax License Manager 0.3.0
- Scope: authenticated WooCommerce My Account licenses document response on the disposable direct application environment
- Sensitive-data handling: no screenshot, Cookie header, session value, URL, customer data, license value, credential, nonce, token, or private identifier is retained or referenced.

## Sanitized observation

The authenticated My Account licenses document response returned:

- `Cache-Control: no-cache, must-revalidate, max-age=0, no-store, private`
- `Expires: Wed, 11 Jan 1984 05:00:00 GMT`
- no observed `Pragma` response header

The response therefore demonstrated private, no-store application behavior for this direct authenticated page. No setting, repository file, plugin data, order, license, or database row was changed during the observation.

## Evidence limit

The missing application-level `Pragma` guarantee was corrected on 2026-08-27. Both the authenticated My Account licenses document and its reveal response now use one explicit private-cache helper that sends:

- `Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0`
- `Pragma: no-cache`
- `Expires: Wed, 11 Jan 1984 05:00:00 GMT`

Source-contract regression coverage verifies both response paths and all three required headers. This implementation evidence does not replace a live response check.

F14 is not `PASS_WITH_EVIDENCE`. A sanitized live re-test must confirm the corrected My Account and reveal headers, and page-cache, reverse-proxy, or CDN preservation remains unverified. Those checks remain open, so F14 remains `MANUAL_ENVIRONMENT_REQUIRED`.
