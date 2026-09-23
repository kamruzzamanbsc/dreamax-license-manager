# F22 release-header URL evidence

- Date: 2026-08-25
- Reviewer: Automated release-gate audit
- Scope: release-facing URL metadata only; no licensing runtime behavior changed

## Procedure and result

1. Ran `rg -n -i "example\.invalid|plugin uri|author uri|update uri|homepage|support|source" dreamax-license-manager.php README.md readme.txt composer.json docs scripts .gitattributes`.
   - Expected: identify every release-facing placeholder without changing documentation examples or security fixtures.
   - Observed: only Plugin URI and Author URI in the main plugin header were release-facing invalid URLs. No Update URI exists. README files and Composer metadata contain no invalid release URL. The remaining release-document mention is a policy check that prohibits shipping placeholder headers.
2. Checked `https://dreamaxsoft.com/` and its WordPress sitemap, and searched the public site for Dreamax License Manager/license-manager entries.
   - Expected: retain Plugin URI only if a real plugin-specific public page exists.
   - Observed: homepage and sitemap returned HTTP 200 but contained no plugin-specific entry; the likely `/dreamax-license-manager/`, `/plugins/dreamax-license-manager/`, and `/products/dreamax-license-manager/` paths returned HTTP 404.
3. Removed the optional Plugin URI header and changed Author URI to `https://dreamaxsoft.com/`.
4. Re-ran the release-facing placeholder search.
   - Expected: no release-facing invalid URL remains.
   - Observed: pass. The only remaining `example.invalid` text is the intentional release-policy sentinel in `docs/RELEASE.md`.

This closes the F22 release-header URL blocker only. The complete F22 gate remains blocked until WordPress readme validation and a clean production build are executed and evidenced.

## Verification

| Command | Expected | Observed |
| --- | --- | --- |
| `composer validate --no-check-publish` | Valid Composer metadata | Exit 0; `./composer.json is valid` |
| `composer lint` | No WordPress coding-standard violation | Exit 0; no violation reported |
| `composer analyse` | No PHPStan error | Exit 0; `[OK] No errors`, 41/41 files |
| `composer test` | Configured PHPUnit suite passes | Exit 0; 40/40 tests, 53 assertions |
| `git -c safe.directory=E:/development/dreamax-license-manager diff --check` | No whitespace error | Exit 0; no output |
