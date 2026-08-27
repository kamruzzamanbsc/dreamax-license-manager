# F10 capability-isolation evidence — 0.3.0

- Date: 2026-08-27
- Classification: `PASS_WITH_EVIDENCE`
- Environment: WordPress 7.1; WooCommerce 11.0.1; PHP 8.2.4; MariaDB 10.4.28; HPOS enabled; Dreamax License Manager 0.3.0
- Scope: narrow WordPress role/capability, nonce ownership, administrative menu, mutation-handler, transfer, order-tool, and sensitive-control enforcement
- Sensitive-data handling: no user ID, login, email, password, nonce, Cookie value, license value, credential, database value, URL, or private path is retained.

## Disposable-environment guard

The repeatable live verifier refuses to run unless all of these conditions are true:

- the explicit disposable-test marker is supplied;
- WordPress reports a local or reserved test URL and an unmistakably test-named database;
- Dreamax License Manager is active; and
- the WordPress user and user-metadata tables are both transactional InnoDB tables.

## Live WordPress result

The verifier exercised an existing administrator plus transaction-scoped synthetic shop-manager and customer users. It observed:

- all seven Dreamax capabilities were granted to the administrator;
- the shop manager had only license management and diagnostics among the seven Dreamax capabilities;
- the customer had none of the seven Dreamax capabilities;
- granting each Dreamax capability individually to the synthetic customer enabled only that capability and no sibling capability;
- a nonce created for the synthetic shop manager was accepted for its owner and rejected after switching to the synthetic customer; and
- all six live assertions passed.

The two synthetic users, their internally generated passwords, nonce material, and temporary capability grants existed only inside one database transaction. The verifier rolled the transaction back, confirmed zero synthetic users remained, and confirmed the total user count returned to its original value.

## Action/source contract result

The automated regression suite verifies:

- the seven published capability names remain distinct;
- primary, credential, diagnostic, transfer, activity, detail, and order-tool menus retain their intended capability boundaries;
- license creation, bulk lifecycle, reassignment, encryption setup, and credential create/rotate/revoke handlers authorize before nonce or request-input handling;
- CSV import/export requires license management while full-key export separately requires the export capability; and
- permanent deletion, security controls, health output, order tools, and order resend retain their narrow guards.

The focused suite passed four tests with 58 assertions. This source/action coverage combined with the live WordPress role, grant, and nonce matrix satisfies F10 without retaining any credential or private fixture identifier.
