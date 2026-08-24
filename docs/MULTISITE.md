# Multisite ownership and lifecycle

Licensing is blog-local. Each site uses its own prefixed tables, options, KDF salt, derived keys, credentials, buckets, and jobs. Super Administrators remain inside WordPress's trusted platform boundary, but ordinary requests and jobs never enumerate or switch through unrelated sites.

Network activation initializes current sites independently. Future-site initialization must be handled explicitly. Deactivation retains data and capabilities. Permanent uninstall is disabled by default and may remove only the current site's records after an explicit capability-protected choice. Network-wide licensing is a future feature.
