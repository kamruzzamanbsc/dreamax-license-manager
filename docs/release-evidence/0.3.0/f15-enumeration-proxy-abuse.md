# F15 enumeration and proxy-abuse evidence — 0.3.0

- Date: 2026-08-27
- Classification: `PASS_WITH_EVIDENCE`
- Environment: WordPress 7.1; WooCommerce 11.0.1; PHP 8.2.4; MariaDB 10.4.28; HPOS enabled; Dreamax License Manager 0.3.0
- Scope: public validation rejection privacy, forwarded-header trust, timing shape, and layered rate limiting
- Sensitive-data handling: no license key, product, instance, customer, order, request, rate-bucket, address, nonce, Cookie, URL, database, or private-path value is retained.

## Guarded live verification

The verifier required the explicit disposable marker, local/test environment guard, active plugin, exactly one existing paid synthetic assigned-license fixture, and an InnoDB rate-limit table. All unknown keys, alternate product identities, instances, forwarded addresses, and timing requests were synthetic.

Eight abuse-protection checks passed:

- forwarded-address input from an untrusted direct peer was ignored;
- forwarded HTTPS from an untrusted direct peer did not bypass the transport guard;
- a trusted proxy chain resolved to the first untrusted client address;
- forwarded HTTPS was accepted only when the direct peer was explicitly trusted;
- an actual loopback HTTP request carrying spoofed forwarded headers was rejected while the same request through the explicitly trusted loopback proxy reached the stable application rejection;
- unknown-key and wrong-product responses used their documented status/code and empty-data envelope without echoing protected inputs;
- twenty alternating unknown-key and wrong-product samples remained within the verifier's bounded relative-median timing shape; and
- the twenty-first failure in an isolated network bucket returned the stable rate-limit result.

The timing check is a bounded regression signal, not a claim of mathematically constant execution time. It compared alternating samples in one local run, required the slower median to remain within four times the faster median, and limited their absolute median difference to 25 milliseconds. Exact timing values were not retained.

## Restoration

Before first use, the verifier snapshotted every known breaker, aggregate, read, and failure bucket it could touch. It restored each existing row exactly or removed only a newly created known row. It also restored the exact pre-run trusted-proxy and local-HTTP options and suppressed opaque runtime request identifiers during the run. The final output contained only sanitized booleans and counts.

## Result

The source contracts and guarded WordPress REST/actual loopback HTTP matrix jointly verify the F15 enumeration, proxy-spoof, timing-shape, and layered-rate-limit requirement. F15 is `PASS_WITH_EVIDENCE`.
