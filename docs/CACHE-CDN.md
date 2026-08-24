# Cache, CDN, TLS, and trusted proxies

Exclude `/wp-json/dreamax-license-manager/v1/licenses/*` and the WooCommerce My Account licenses endpoint from page, reverse-proxy, and CDN caching. Sensitive responses send private/no-store, Pragma no-cache, and an expired Expires header; verify that intermediaries preserve them.

Terminate TLS only at the application server or an explicitly configured trusted proxy. `X-Forwarded-For` and `X-Forwarded-Proto` are ignored from all other sources. The default trusted-proxy option accepts exact IP addresses; deployment tooling must update it when proxy egress addresses change.

Plain HTTP is available only when the local-development option is explicitly enabled and the resolved source is loopback. Never enable it from a hostname alone.

Test direct HTTPS, trusted termination, spoofed forwarding/proto headers, downgrade attempts, query-secret rejection, body/header ceilings, page cache, object cache, reverse proxy, and CDN behavior before release.
