# Frozen v1 reference client

Run this dependency-light black-box client against every release candidate. It imports no plugin classes and bootstraps no WordPress internals. It verifies the required envelope, authoritative timestamp, activation, exact retry, conflicting retry, validation, and deactivation.

Do not edit the fixture merely to make a breaking server change pass. An intentional incompatible change requires a new API version/reference fixture or a documented migration. Activation-limit scenarios are supplied as separate server fixtures and must cover disabled, one, positive multi-site, and unlimited semantics.
