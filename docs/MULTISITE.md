# Multisite ownership and lifecycle

Licensing is blog-local. Each site uses its own prefixed tables, options, KDF salt, derived keys, credentials, buckets, and jobs. Super Administrators remain inside WordPress's trusted platform boundary, but ordinary requests and jobs never enumerate or switch through unrelated sites.

Credential-use/rotation/revocation advisory locks include the current blog identity and table prefix before hashing into the bounded lock name. A credential public ID from one site therefore cannot authorize or lock credential work on another site.

Audit-event contracts are code-global but event rows, internal references, reads, and uninstall operations remain site-local through the current blog table prefix. The catalog introduces no network-global event store or cross-site enumeration.

Network activation initializes every existing site independently. While the plugin remains network-active, WordPress's `wp_initialize_site` lifecycle initializes each newly created site's schema, capabilities, salt, and cleanup schedule. Network-wide deactivation clears the cleanup schedule on every existing site while retaining data and capabilities.

Permanent uninstall is disabled by default and accepts only the explicit enabled setting. It removes only the current site's prefixed tables and site-local options; it never enumerates other sites. A guarded three-site private-clone matrix verified existing-site and future-site initialization, per-site encryption/restore, site-local data/API/export/jobs, and current-site-only uninstall. Network-global licensing remains a future feature.
