# REST API v1

Base path: `/wp-json/dreamax-license-manager/v1`. Production requests carrying keys or credentials require HTTPS and JSON bodies. Never send a license, Bearer credential, token, or idempotency value in a URL.

## Public routes

- `POST /licenses/activate`
- `POST /licenses/deactivate`
- `POST /licenses/validate`
- `POST /licenses/status`
- `GET /system/ping`

Public body:

```json
{
  "license_key": "DLM-…",
  "product_public_id": "prd_…",
  "instance_id": "opaque-stable-installation-id",
  "instance_label": "Optional human-readable label"
}
```

Activation and deactivation clients should send a unique `Idempotency-Key` of 8–128 visible ASCII characters for each logical mutation and reuse it only for an exact retry. `instance_id` must remain stable when a URL or label changes.

## Management routes

- `GET|POST /licenses`
- `GET|PATCH /licenses/{public_id}`
- `POST /licenses/{public_id}/revoke`
- `GET /activations`
- `GET /generators`

Authenticate with exactly one `Authorization: Bearer dlm_v1_<22-character-public-id>.<43-character-secret>` header. Credentials in URLs or bodies, multiple/comma-joined headers, malformed syntax, controls, and values over 256 bytes are rejected before privileged business processing. Unknown, incorrect, expired, and revoked credentials share the same authentication failure envelope. The exact required scope depends on the route and is checked only after authentication. Creation and rotation responses are the only times a credential secret is returned. See `API-CREDENTIALS.md` for lifecycle and recovery operations.

## Envelope and errors

Every response includes `success`, stable `code`, a human message, opaque `request_id`, authoritative RFC3339 server `timestamp`, and `data`. Stable errors include `invalid_request`, `invalid_license`, `product_mismatch`, `license_expired`, `license_suspended`, `license_revoked`, `activation_limit_reached`, `activation_not_found`, `rate_limited`, `authentication_required`, `insufficient_scope`, `idempotency_conflict`, and `server_unavailable`.

Required no-store headers apply to all licensing responses. There is no wildcard CORS policy; administrators must explicitly allow exact origins.


## Public lifecycle error handling

Clients should branch on the stable `code`, `success`, and HTTP status fields.
The human-readable `message` may be displayed, but clients should not parse it
to make decisions. The opaque `request_id` may be retained for support without
retaining the license key or raw request body.

| Operation | Documented failure codes |
| --- | --- |
| Activate | `invalid_request`, `invalid_license`, `product_mismatch`, `license_expired`, `license_suspended`, `license_revoked`, `activation_limit_reached`, `rate_limited`, `idempotency_conflict`, `server_unavailable` |
| Deactivate | `invalid_request`, `invalid_license`, `product_mismatch`, `activation_not_found`, `rate_limited`, `idempotency_conflict`, `server_unavailable` |
| Validate or status | `invalid_request`, `invalid_license`, `product_mismatch`, `license_expired`, `license_suspended`, `license_revoked`, `rate_limited`, `server_unavailable` |

Recommended handling:

- Correct `invalid_request` before retrying. Do not resend an unchanged invalid
  payload.
- Treat `invalid_license`, `product_mismatch`, `license_expired`,
  `license_suspended`, `license_revoked`, `activation_limit_reached`, and
  `activation_not_found` as authoritative responses requiring user or
  administrator action rather than blind retries.
- Back off after `rate_limited`. Use bounded retries and avoid synchronized
  retry loops.
- An `idempotency_conflict` means that the supplied key was already associated
  with different request data. Never reuse that key for a changed operation.
- For `server_unavailable`, first verify HTTPS and service readiness, then use
  bounded retries if the failure is temporary. An exact activation or
  deactivation retry must reuse the original idempotency key and payload.
- Never log or attach license keys, Bearer credentials, idempotency values, or
  raw request bodies when reporting an error.
