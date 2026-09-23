## Problem and outcome

Describe the bounded problem and the result of this change.

## Compatibility and contracts

- Public REST v1 impact:
- Database or migration impact:
- WordPress/WooCommerce compatibility impact:
- Backward-compatibility plan:

## Security and privacy

Describe authentication, authorization, encryption, customer isolation,
privacy, retention, uninstall, audit-event, or secret-handling impact. Write
`None identified` only after checking each applicable boundary.

## Validation

List the exact commands and results. Distinguish checks run locally from
historical or unverified evidence.

```text
composer test
composer analyse
composer phpcs
composer release:validate
```

## Documentation and follow-up

- Documentation changed:
- Work not verified:
- Follow-up issue or milestone:

## Submission checklist

- [ ] The change is focused and excludes unrelated formatting or refactoring.
- [ ] Tests cover the changed behavior and relevant failure paths.
- [ ] Public contracts and frozen fixtures remain compatible or have an approved compatibility plan.
- [ ] No secrets, license keys, customer data, private URLs, local configuration, or generated artifacts are included.
- [ ] No release, deployment, or production-readiness claim is made without the required evidence.
