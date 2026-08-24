# Privacy and record retention

This is an operational policy template, not legal advice. Merchants must choose and document periods appropriate to their obligations.

| Class | Examples | Default handling |
| --- | --- | --- |
| Operational/accounting | public license ID, product/order references, lifecycle, expiry | Retain while required for fulfillment, disputes, accounting, and integrity |
| Direct customer identity | WordPress customer ID, encrypted email snapshot if justified | Avoid duplicate snapshots; anonymize on erasure when integrity permits |
| Installation label | domain, URL, device name | Optional; customer-visible; erase/anonymize when no longer justified |
| Network security identity | keyed/truncated source network | Short bounded retention; never raw long-term IP |
| Audit evidence | event type/time/actor class/references | Configurable; metadata minimized and schema-versioned |
| Secrets | clear license key, API secret, idempotency key, claim token | Never retain in logs/audit; key ciphertext exists only for licensed function |

The WordPress exporter returns license identifiers and lifecycle facts, not clear keys. The eraser removes direct customer links, labels, fingerprints, and free-form metadata while retaining minimum order/license/lifecycle evidence. Repeated erasure is idempotent. Customer email alone never proves ownership for reveal or claim.
