# WordPress.org release readiness

## Implemented in the repository

- [x] Local/manual operation no longer triggers automatic cloud connection.
- [x] Cloud connection requires an explicit administrator action and displays a data disclosure.
- [x] Insights remains an intentional preview with a voluntary notification action.
- [x] Privacy Policy Guide content describes local and optional-cloud processing.
- [x] Deactivation and uninstall clean up known scheduled hooks and stored state.
- [x] Plugin metadata, readme, license, third-party notice, and release script exist.
- [x] The release script rebuilds assets, excludes development files, creates a deterministic ZIP, and writes a SHA-256 manifest.

## Blocking owner decisions and evidence

- [ ] Confirm `LingoWP`, the `lingowp` slug, and `aminesayagh` contributor identity are available and final.
- [ ] Publish and provide canonical LingoWP Cloud Terms of Service and Privacy Policy URLs.
- [ ] Make `https://github.com/aminesayagh/lingowp` public and publish the exact `v1.0.0` tag referenced by `readme.txt`.
- [ ] Convert remaining React interface strings to WordPress i18n calls and generate translation assets.
- [ ] Complete the compatibility matrix and update `Tested up to` to the newest version actually tested.
- [ ] Reconcile every production dependency and bundled asset against `THIRD-PARTY-NOTICES.md`.
- [ ] Run and disposition unit, integration, lint, security, and Plugin Check results.
- [ ] Inspect the generated ZIP and install that exact ZIP on a clean WordPress site.
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
