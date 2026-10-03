# WordPress.org release readiness

## Implemented in the repository

- [x] The cloud connection is opt-in: onboarding offers "Connect to LingoWP Cloud" or "Continue without connecting", the connect button always sits next to the Terms and Privacy Policy links, `SiteConnector::ensureValid()` never connects a site that has no key, and the connection can be fully undone from Account → Danger zone. Covered by `tests/db/no_auto_connect_it.php`.
- [x] Insights remains an intentional preview with a voluntary notification action.
- [x] Privacy Policy Guide content describes local and optional-cloud processing.
- [x] Deactivation and uninstall clean up known scheduled hooks and stored state.
- [x] Plugin metadata, readme, license, third-party notice, and release script exist.
- [x] The release script rebuilds assets, excludes development files, creates a deterministic ZIP, and writes a SHA-256 manifest.
- [x] Action Scheduler is actually present in the built archive. It was silently absent: a stale gitlink held `vendor/woocommerce/action-scheduler` and a committed `vendor/composer/installed.json` made Composer skip the package. Both are untracked now, and the release script fails if the entry file is missing.
- [x] `HtmlDiscoveryCrawl::maybeEnqueue()` clears its activation flag only once `start()` has queued the sweep. It previously cleared the flag first, so a site without Action Scheduler lost the frontend crawl permanently rather than deferring it.
- [x] Published Terms of Service and Privacy Policy URLs are linked from `readme.txt` and are the filter defaults in `OnboardingPageController`.
- [x] No add-on installer (Guideline 8): the plugin relays a signed download link and the administrator installs the zip through Plugins → Add New → Upload Plugin. `AddonInstaller.php`, the install route, and the zip-fetching client method are deleted.

## Blocking owner decisions and evidence

- [ ] Confirm `LingoWP`, the `lingowp` slug, and `aminesayagh` contributor identity are available and final.
- [x] Human-readable source no longer depends on any URL: the WordPress.org archive ships `admin-ui/` plus `package.json`, `package-lock.json`, `webpack.config.js` and `postcss.config.js`, and the release script's required-file check refuses to package without them. The old link-resolves check and its `LINGOWP_ALLOW_UNPUBLISHED_SOURCE` bypass are gone. The client archive in `release/` deliberately excludes the admin source; it is not distributed by WordPress.org.
- [ ] Convert remaining React interface strings to WordPress i18n calls and generate translation assets.
- [ ] Complete the compatibility matrix. `Tested up to` is now 7.1, the version the development environment runs; confirm that is the newest version genuinely exercised.
- [ ] Decide the real PHP floor. The header and `composer.json` declare 7.4, no runtime version check exists, and development runs on 8.4 — so 7.4 is declared but untested.
- [ ] Reconcile every production dependency and bundled asset against `THIRD-PARTY-NOTICES.txt`.
- [ ] Run and disposition unit, integration, lint, security, and Plugin Check results.
- [ ] Inspect the generated ZIP and install that exact ZIP on a clean WordPress site — clean meaning no WooCommerce and no other plugin bundling Action Scheduler, since that is what masked the missing dependency. Confirm existing content is backfilled and the frontend crawl runs.
- [ ] Decide whether to submit with Polaris after reviewing its license one final time. Current decision: retain it and accept possible rejection.

## Owner-run verification

```bash
npm test
composer test
npm run lint:js
npm run build
composer run test:db
composer run security
composer run security:plugin-check
bin/build-release-zip.sh
```

The build command intentionally refuses a dirty worktree. Commit the reviewed
release candidate first, then build from that clean commit and compare the ZIP
with the matching public source tag.
