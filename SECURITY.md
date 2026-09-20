# Security policy

Dreamax License Manager is an actively maintained open-source WordPress plugin published on WordPress.org.

If you discover a suspected security vulnerability, please do not disclose it publicly before the maintainers have had a reasonable opportunity to investigate and respond.

When reporting a vulnerability, include the following where possible:

- Affected plugin version
- Description of the issue
- Potential impact
- Minimal reproduction steps
- Relevant environment details
- Suggested remediation, if available

Please report security issues through the contact channel published by [Dreamax Soft](https://dreamaxsoft.com/).

Do not include sensitive or production data in a security report. In particular, never share:

- Real license keys
- API credentials
- Customer information
- Production database dumps
- Authentication secrets
- The `DREAMAX_LICENSE_MANAGER_MASTER_KEY`
- Any other private credentials or personal data

This repository contains the actively maintained development source for the published WordPress.org plugin.

Before making security-sensitive changes or release claims, review:

- `docs/THREAT-MODEL.md`
- `docs/FREE-V1-MUST-PASS.md`

Security-related changes should be tested in a disposable development environment and should not be validated against production customer data.
