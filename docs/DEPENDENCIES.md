# Dependency and license inventory

## Runtime

| Dependency | Source | License | Included in ZIP | Review |
| --- | --- | --- | --- | --- |
| WordPress APIs | wordpress.org | GPL-2.0-or-later | No, provided by host | Required baseline 6.9; compatibility evidence pending |
| WooCommerce APIs | woocommerce.com / wordpress.org | GPL-3.0-or-later | No, provided by host | Required baseline 10.8; compatibility evidence pending |
| PHP Sodium extension | php.net | PHP License | No, provided by host | Used for XChaCha20-Poly1305 |

No third-party PHP runtime packages are bundled. Development-only tools in `composer.json` are excluded from the distribution ZIP. Before release, generate a lockfile, run vulnerability/license checks, and record unresolved findings in the release evidence.
