# Privacy and record retention

This is an operational policy template, not legal advice. Merchants must choose and document periods appropriate to their obligations.

| Class | Examples | Default handling |
| --- | --- | --- |
| Operational/accounting | public license ID, product/order references, lifecycle, expiry | Retain while required for fulfillment, disputes, accounting, and integrity |
| Direct customer identity | WordPress customer ID, encrypted email snapshot if justified | Avoid duplicate snapshots; anonymize on erasure when integrity permits |
| Installation label | domain, URL, device name | Optional; customer-visible; erase/anonymize when no longer justified |
| Network security identity | keyed/truncated source network | Short bounded retention; never raw long-term IP |
| Audit evidence | event type/time/actor class/references | Configurable; metadata minimized and schema-versioned |
| Guest claim evidence | claim public ID, order/account reference, status and times | Pending proof hash cleared on final state; export status facts; erase direct account link and outstanding proof material |
| Secrets | clear license key, API secret, idempotency key, claim token | Never retain in logs/audit; key ciphertext exists only for licensed function |

The WordPress exporter returns license identifiers, lifecycle facts, and guest-claim status facts, not clear keys, token hashes, or ownership hashes. The eraser removes direct customer/claim-owner links, invalidates outstanding proofs, clears proof hashes, labels, fingerprints, and free-form metadata while retaining minimum order/license/lifecycle/audit evidence. Repeated erasure is idempotent. Customer email alone never proves ownership for reveal or claim.
