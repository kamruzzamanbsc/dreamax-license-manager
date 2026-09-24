# Contributor issue drafts

These are bounded, evidence-based issue drafts for future publication. Before
opening one, confirm that it is still needed, assign a maintainer, and add exact
acceptance evidence. Publishing an issue is a separate repository action.

## Document endpoint-specific REST response data

**Problem:** `docs/openapi-v1.yaml` describes the common response envelope, but
the `data` object is intentionally generic. Integrators must inspect prose or
implementation to discover the fields returned by each public endpoint.

**Scope:** Add endpoint-specific OpenAPI response schemas for public v1 routes
without changing runtime behavior or existing field meanings.

**Acceptance criteria:**

- Activation, deactivation, validation, status, and ping responses reference
  named data schemas.
- Success and documented error envelopes remain backward compatible.
- Example payloads contain only synthetic identifiers and keys.
- OpenAPI parsing and existing source-contract tests pass.

**Out of scope:** New endpoints, authentication changes, and storage changes.

## Add contract tests for both example API clients

**Problem:** The PHP examples are syntax-checked during maintenance, but their
request paths, required fields, HTTPS guard, idempotency header, and envelope
expectations are not all covered by a focused source-contract test.

**Scope:** Add deterministic tests that inspect or isolate both example clients
without making network requests.

**Acceptance criteria:**

- Tests cover the v1 base path and all three lifecycle operations.
- Tests cover stable instance identifiers and exact-retry idempotency guidance.
- No test contacts a live site or includes a real license key.
- PHPUnit, PHPStan, and PHPCS remain green.

**Out of scope:** Live HTTP fixtures and changes to the public v1 contract.

## Expand public API error guidance by operation

**Problem:** `docs/API.md` lists stable errors globally. A new integrator still
has to determine which errors require user correction, retry, delayed retry, or
administrator intervention for each lifecycle operation.

**Scope:** Add a compact operation/error-handling matrix based only on current
runtime behavior and frozen tests.

**Acceptance criteria:**

- The matrix distinguishes authoritative rejection from transient unavailability.
- Rate-limit and idempotency-conflict handling is explicit.
- Guidance never recommends logging keys, credentials, or raw request bodies.
- Every documented meaning is traceable to implementation or an existing test.

**Out of scope:** Renaming error codes or changing HTTP status behavior.
