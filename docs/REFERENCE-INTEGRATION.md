# Reference integration

`examples/wordpress-plugin-client.php` is a small reference client for a
WordPress plugin that consumes the public REST v1 lifecycle API. It demonstrates
HTTPS enforcement, a stable opaque installation identifier, mutation
idempotency, safe WordPress HTTP requests, and response-envelope validation.

Copy and rename the example class into the consuming plugin. Treat it as a
starting point, not as an automatically loaded part of Dreamax License Manager.

## Configure the client

```php
use Dreamax\LicenseManager\Examples\WordPressPluginClient;

$client = new WordPressPluginClient(
	'https://licenses.example.com',
	'prd_1234567890123456789012',
	'my_product_license_instance_id'
);
```

The option name belongs to the consuming plugin. The generated value is opaque
and remains stable if the site URL or display label changes. A multisite
integration should decide explicitly whether each site or the whole network is
one licensed installation; the example uses a site option.

## Activate

Generate and persist one idempotency key for the logical activation before the
first request. Reuse the same key only when retrying that exact request.

```php
$idempotency_key = WordPressPluginClient::new_idempotency_key();
$result          = $client->activate( $license_key, $idempotency_key, get_bloginfo( 'name' ) );

if ( true === $result['success'] ) {
	$activation_id = $result['data']['activation_public_id'];
}
```

Never create a new idempotency key merely because the first response timed out.
A consuming plugin should store the pending key and clear it only after it has
received and processed the authoritative response.

## Validate

```php
$result = $client->validate( $license_key, get_bloginfo( 'name' ) );
$valid  = true === $result['success']
	&& 'valid' === ( $result['data']['status'] ?? null );
```

Cache successful validation only for a bounded period appropriate to the
product. Do not turn a temporary network failure into a permanent revocation.
Choose and document a bounded grace policy, while treating authoritative
revoked, suspended, expired, and product-mismatch responses as failures.

## Deactivate

```php
$idempotency_key = WordPressPluginClient::new_idempotency_key();
$result          = $client->deactivate( $license_key, $idempotency_key );
```

Activation and deactivation keys have the same exact-retry rule.

## Update checks

The public v1 API validates licensing state; it does not publish update metadata
or download packages. A product may use a successful validation result as one
input to its own update service, but that service remains a separate security
boundary. It should:

- fetch update metadata and packages only over HTTPS;
- avoid putting license keys or credentials in URLs;
- authenticate download requests server-side;
- sign update metadata or packages and verify the signature before installation;
- validate product and version compatibility independently;
- fail safely when the licensing or update service is unavailable.

Client-side checks are inspectable and must not be described as unbreakable DRM.
The server remains authoritative for legitimate access and activation state.

## Secret handling

- Never commit, log, email, or include real license keys in diagnostics.
- Do not expose management Bearer credentials in a distributed plugin.
- Store any locally retained key according to the consuming product's documented
  threat model and reveal it only to authorized administrators.
- Keep the public lifecycle API and privileged management API separate.

See `docs/API.md`, `docs/openapi-v1.yaml`, and `docs/THREAT-MODEL.md` for the
authoritative contract and security boundaries.
