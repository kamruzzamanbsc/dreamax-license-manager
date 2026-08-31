# Building the release candidate

The distributable is built only from a recorded Git commit. The build exports that commit twice into separate temporary directories, runs `composer install --no-dev` against the lockfile in each snapshot, selects the production allowlist, lints every included PHP file, normalizes ZIP timestamps and permissions, and compares both hashes and inventories.

Run from the repository root after all changes are committed:

```text
php scripts/build-release.php --commit=<40-character-commit> --output=build
```

The ignored `build/` directory receives:

- `dreamax-license-manager-0.3.1.zip`, an unshipped release-candidate verification artifact;
- `release-inventory.json`, the exact path, size, and content digest inventory;
- `release-manifest.json`, the external hash-bearing provenance sidecar.

The manifest intentionally remains outside the ZIP. No build output is committed. The command refuses unrecorded source, output outside `build/`, nondeterministic results, missing required files, unsafe paths, and prohibited artifacts.

The ZIP contains one `dreamax-license-manager/` root, the main plugin file at `dreamax-license-manager/dreamax-license-manager.php`, production PHP, the WordPress readme, the plugin license, dependency notices, and this build/source guide. Tests, fixtures, development tools, Composer metadata, `vendor`, repository metadata, caches, logs, local configuration, and credentials are excluded. There are currently no third-party production Composer packages, so the temporary production Composer install produces no runtime library that needs bundling.

This process creates an unshipped release candidate; it does not tag, publish, submit, or deploy the artifact. All 33 gates in `docs/FREE-V1-MUST-PASS.md` have recorded evidence, and the external manifest carries the accepted runtime versions. Release authorization and target-environment operational checks remain separate.
