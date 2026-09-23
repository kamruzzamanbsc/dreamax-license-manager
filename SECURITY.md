# Security policy

Dreamax License Manager is an actively maintained open-source WordPress plugin published on WordPress.org.

## Supported versions

Security fixes target the current stable release published on WordPress.org.
Users of an older release should upgrade before reporting a problem unless the
upgrade itself is blocked by the suspected vulnerability.

| Version | Security support |
| --- | --- |
| Current WordPress.org stable release | Supported |
| Older releases | Upgrade required; no backport commitment |

The current version is stated in `readme.txt` and the main plugin header. This
policy does not promise a response-time or patch-time service level.

## Reporting a vulnerability

If you discover a suspected security vulnerability, please do not disclose it publicly before the maintainers have had a reasonable opportunity to investigate and respond.

When reporting a vulnerability, include the following where possible:

- Affected plugin version
- Description of the issue
- Potential impact
- Minimal reproduction steps
- Relevant environment details
- Suggested remediation, if available

Please email security reports to
[support@dreamaxsoft.com](mailto:support@dreamaxsoft.com). If that mailbox is
unavailable, use [info@dreamaxsoft.com](mailto:info@dreamaxsoft.com). Do not
open a public GitHub issue containing vulnerability details, exploit steps, or
sensitive data. A public issue may only ask the maintainers to confirm a private
contact channel.

Do not include sensitive or production data in a security report. In particular, never share:

- Real license keys
- API credentials
- Customer information
- Production database dumps
- Authentication secrets
- The `DREAMAX_LICENSE_MANAGER_MASTER_KEY`
- Any other private credentials or personal data

## In scope

Reports are especially useful when they concern:

- authentication or authorization bypass;
- cross-customer or cross-site data access;
- exposure of license keys, credentials, tokens, or encryption material;
- unsafe key recovery or cryptographic misuse;
- SQL injection, XSS, CSRF, SSRF, path traversal, or unsafe file handling;
- REST API replay, rate-limit, cache, proxy-trust, or idempotency failures;
- privilege escalation, unsafe uninstall, privacy erasure, or migration behavior.

WordPress, WooCommerce, PHP, web-server, hosting, and third-party extension
vulnerabilities that do not originate in this plugin should be reported to the
responsible upstream project. Deployment misconfiguration may still be reported
when the plugin could reasonably detect or document it more clearly.

## Coordinated handling

The maintainers will make a reasonable effort to acknowledge, reproduce,
triage, and remediate valid reports. Timing depends on impact, reproducibility,
compatibility risk, and maintainer availability. Please allow coordinated
investigation and a reasonable patch window before public disclosure.

Security fixes may require private validation, a new release, WordPress.org
publication, and downstream notification. Credit will be discussed with the
reporter and will not be published when anonymity is requested.

## Project security practices

The repository documents authenticated encryption, keyed lookup fingerprints,
external master-key handling, scoped credentials, transport requirements,
transactional activation enforcement, rate limiting, private-cache controls,
audit-event redaction, recovery behavior, and guarded release verification.

These controls reduce risk but are not a guarantee that the software is free of
vulnerabilities. Distributed client code is inspectable, and the project does
not claim unbreakable DRM.

This repository contains the actively maintained development source for the
published WordPress.org plugin.

Before making security-sensitive changes or release claims, review:

- `docs/THREAT-MODEL.md`
- `docs/FREE-V1-MUST-PASS.md`
- `docs/RECOVERY.md`
- `docs/API-CREDENTIALS.md`

Security-related changes should be tested in a disposable development environment and should not be validated against production customer data.
