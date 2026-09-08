# WordPress.org release readiness

## Implemented in the repository

- [x] The cloud connection displays a data disclosure and can be fully undone from Billing → Danger zone.
- [x] Insights remains an intentional preview with a voluntary notification action.
- [x] Privacy Policy Guide content describes local and optional-cloud processing.
- [x] Deactivation and uninstall clean up known scheduled hooks and stored state.
- [x] Plugin metadata, readme, license, third-party notice, and release script exist.
- [x] The release script rebuilds assets, excludes development files, creates a deterministic ZIP, and writes a SHA-256 manifest.
- [x] Action Scheduler is actually present in the built archive. It was silently absent: a stale gitlink held `vendor/woocommerce/action-scheduler` and a committed `vendor/composer/installed.json` made Composer skip the package. Both are untracked now, and the release script fails if the entry file is missing.
- [x] `HtmlDiscoveryCrawl::maybeEnqueue()` clears its activation flag only once `start()` has queued the sweep. It previously cleared the flag first, so a site without Action Scheduler lost the frontend crawl permanently rather than deferring it.
- [x] Published Terms of Service and Privacy Policy URLs are linked from `readme.txt` and are the filter defaults in `OnboardingPageController`.

## Blocking owner decisions and evidence

- [ ] **Require explicit consent before the first cloud request.** A fresh install connects automatically today: `SiteConnector::ensureValid()` calls `connect()` when no valid credential exists, and the Dashboard and Billing screens reach it through `POST /connection/check` on mount. Opening a LingoWP admin page therefore sends the site domain and administrator email before the administrator has agreed to anything, which Guideline 7 does not permit. `readme.txt` and `external-services.md` describe this accurately; `compliance-audit.md` previously claimed it was already fixed and did not.
- [ ] Confirm `LingoWP`, the `lingowp` slug, and `aminesayagh` contributor identity are available and final.
- [ ] Make `https://github.com/aminesayagh/lingowp` public and publish the exact `v1.3.1` tag referenced by `readme.txt`. The archive ships no admin source, so this link is the only thing satisfying the human-readable-source requirement. The release script now refuses to build while it does not resolve. Note this plugin is its own repository, but its remote is named `aminesayagh/translangs` and it has no tags — so publishing means either renaming the repository to `lingowp` or pointing `readme.txt` at the `translangs` URL, then tagging.
- [ ] Convert remaining React interface strings to WordPress i18n calls and generate translation assets.
- [ ] Complete the compatibility matrix. `Tested up to` is now 7.1, the version the development environment runs; confirm that is the newest version genuinely exercised.
- [ ] Decide the real PHP floor. The header and `composer.json` declare 7.4, no runtime version check exists, and development runs on 8.4 — so 7.4 is declared but untested.
- [ ] Reconcile every production dependency and bundled asset against `THIRD-PARTY-NOTICES.md`.
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
