# Dependency and license inventory

Inventory checked from `composer.lock` with Composer 2.8.5 on 2026-08-25. Run `composer licenses --format=json` to reproduce it and `composer audit --locked --format=json` for the advisory scan.

## Production/runtime

There are no third-party production Composer packages. Runtime requirements are PHP 8.0 or newer, `ext-json`, `ext-sodium`, WordPress, and the WordPress.org `woocommerce` plugin dependency. Dreamax License Manager is GPL-2.0-or-later. The distribution contains human-readable PHP and no compiled or minified third-party source.

## Development-only locked packages

| Package | Locked version | License |
| --- | --- | --- |
| `dealerdirect/phpcodesniffer-composer-installer` | 1.2.1 | MIT |
| `myclabs/deep-copy` | 1.14.0 | MIT |
| `nikic/php-parser` | 5.8.0 | BSD-3-Clause |
| `phar-io/manifest` | 2.0.4 | BSD-3-Clause |
| `phar-io/version` | 3.2.1 | BSD-3-Clause |
| `php-stubs/woocommerce-stubs` | 11.0.0 | MIT |
| `php-stubs/wordpress-stubs` | 6.9.4 | MIT |
| `phpcsstandards/phpcsextra` | 1.5.1 | LGPL-3.0-or-later |
| `phpcsstandards/phpcsutils` | 1.2.3 | LGPL-3.0-or-later |
| `phpstan/phpstan` | 2.2.9 | MIT |
| `phpunit/php-code-coverage` | 10.1.16 | BSD-3-Clause |
| `phpunit/php-file-iterator` | 4.1.0 | BSD-3-Clause |
| `phpunit/php-invoker` | 4.0.0 | BSD-3-Clause |
| `phpunit/php-text-template` | 3.0.1 | BSD-3-Clause |
| `phpunit/php-timer` | 6.0.0 | BSD-3-Clause |
| `phpunit/phpunit` | 10.5.64 | BSD-3-Clause |
| `sebastian/cli-parser` | 2.0.1 | BSD-3-Clause |
| `sebastian/code-unit` | 2.0.0 | BSD-3-Clause |
| `sebastian/code-unit-reverse-lookup` | 3.0.0 | BSD-3-Clause |
| `sebastian/comparator` | 5.0.5 | BSD-3-Clause |
| `sebastian/complexity` | 3.2.0 | BSD-3-Clause |
| `sebastian/diff` | 5.1.1 | BSD-3-Clause |
| `sebastian/environment` | 6.1.0 | BSD-3-Clause |
| `sebastian/exporter` | 5.1.4 | BSD-3-Clause |
| `sebastian/global-state` | 6.0.2 | BSD-3-Clause |
| `sebastian/lines-of-code` | 2.0.2 | BSD-3-Clause |
| `sebastian/object-enumerator` | 5.0.0 | BSD-3-Clause |
| `sebastian/object-reflector` | 3.0.0 | BSD-3-Clause |
| `sebastian/recursion-context` | 5.0.2 | BSD-3-Clause |
| `sebastian/type` | 4.0.0 | BSD-3-Clause |
| `sebastian/version` | 4.0.1 | BSD-3-Clause |
| `squizlabs/php_codesniffer` | 3.13.6 | BSD-3-Clause |
| `szepeviktor/phpstan-wordpress` | 2.0.3 | MIT |
| `theseer/tokenizer` | 1.3.1 | BSD-3-Clause |
| `wp-coding-standards/wpcs` | 3.4.1 | MIT |

MIT, BSD-3-Clause, and LGPL-3.0-or-later are compatible with distribution under GPL-2.0-or-later. Development-only packages and their license files are not placed in the production ZIP.
