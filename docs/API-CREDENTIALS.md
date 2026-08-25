# Privileged API credential operations

Applies to development version 0.3.0. Live acceptance evidence remains open.

## Protocol

Send exactly one header:

```text
Authorization: Bearer dlm_v1_<22-character-public-id>.<43-character-secret>
```

The public ID is generated from 16 random bytes and the secret from 32 random bytes; both use unpadded base64url. Credentials are accepted only in the Authorization header. Query parameters and request bodies containing a credential are rejected. Production requests require verified HTTPS. The only HTTP exception is an explicitly enabled loopback development request.

Authentication failures for unknown, malformed, expired, revoked, and incorrect credentials use the same `401 authentication_required` response. Authentication occurs before exact scope enforcement. The frozen scopes are:

- `licenses:read`
- `licenses:write`
- `activations:read`
- `generators:read`

Successful calls consume a site-local per-credential rate bucket. `last_used_at` is updated at most once per five-minute interval; audit-use events remain independent of that write-coalescing policy.

## Administrator workflow

Only users with `dreamax_lm_manage_api_credentials` can open **License Manager -> API credentials** or submit its actions. Administrators receive this capability by default. Shop Managers do not.

Creation requires a name, one or more exact scopes, and an optional future UTC expiration. Copy the returned Bearer value immediately into a server-side secret manager. The plaintext is not stored and cannot be displayed again.

Rotation requires a nonce, explicit confirmation, and the secret version shown in the credential inventory. It preserves the public ID, name, scopes, and expiration. The database advisory lock and row/version guard permit only one current rotation; the old secret becomes invalid at commit with zero overlap. Refresh before retrying a stale or failed rotation.

Revocation requires a nonce and explicit confirmation. It is immediate, transactional, irreversible, and idempotent; the old verifier is replaced with one for an unrevealed random value. A repeated request creates no second audit/business effect. A revoked or lost credential cannot be recovered: create a new credential. An expired credential cannot authenticate. The current administrator workflow requires creation of a replacement; an authorized lifecycle caller may instead rotate it only while explicitly assigning a new future expiration.

The inventory displays only public ID, visible name, scopes, status, expiration, last-used time, secret version, and lifecycle timestamps. It never returns a password verifier, secret, or Authorization value.

## Operational verification

Use a disposable WordPress/MySQL environment to test HTTPS/proxy handling, multiple raw Authorization headers, concurrent use/rotation/revocation, exact roles/nonces, expiry boundaries, per-credential throttling, multisite isolation, and audit rows. Never paste a real credential into support tickets, logs, URLs, screenshots, or evidence artifacts.
