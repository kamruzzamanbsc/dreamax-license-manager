# 0.3.1 pre-submission manual evidence

- Environment: disposable local WordPress 7.1, WooCommerce 11.0.1, PHP 8.2.4, MariaDB 10.4.28.
- Administrator workflows exercised: license inventory and details, lifecycle and ownership controls, activity, masked and authorized CSV portability, system status, API credential creation/rotation/revocation, scoped REST authorization, WooCommerce order preview/allocation/resend, and guest ownership release/override.
- Safety observations: one-time secrets were not retained; the local HTTP test override was restored; test mail was intercepted locally; temporary guards were removed; audit output remained sanitized.
- Official Plugin Check result after query and uninstall hardening: no errors and no warnings.
- Official hosted WordPress.org readme validation: no error; only optional notes for absent Upgrade Notice, Screenshots, and donate-link sections.
- Automated candidate gates after the hardening changes: PHPUnit, WordPress PHPCS, PHPStan, release metadata validation, and diff checks passed.

This evidence records a disposable pre-submission candidate check. It does not authorize tagging, publication, or production deployment.
