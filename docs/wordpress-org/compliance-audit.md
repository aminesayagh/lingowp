# WordPress.org compliance audit

Status reflects repository review only. Nothing here claims that Plugin Check,
security scanners, compatibility checks, or marketplace review have passed.

| Area | Status | Evidence or remaining action |
| --- | --- | --- |
| Plugin identity | Ready for review | Header, constant, package metadata, readme stable tag, and release script use `1.3.1`. Verify `aminesayagh` is the intended WordPress.org username. |
| GPL licensing | Ready for review | Plugin declares GPL-2.0-or-later and includes `LICENSE`. Composer runtime dependencies declare compatible licenses. |
| Shopify Polaris | Accepted risk | Polaris is intentionally retained. Its license restricts its permitted use, so WordPress.org may reject the compiled dependency as non-GPL-compatible. |
| Human-readable source | Blocked | The archive ships only minified bundles in `assets/admin/build/`; `admin-ui/` and the build configuration are excluded. `readme.txt` names the exact `v1.3.1` source tag and the `npm ci && npm run build` steps that reproduce the bundles. The repository and that tag must be public before submission — `bin/build-release-zip.sh` now refuses to build while the URL does not return 200. |
| External services | Ready for review | Data flows are disclosed in `readme.txt` and `external-services.md`. Published legal pages are linked from `readme.txt` and are the defaults behind the `lingowp_cloud_terms_url` and `lingowp_cloud_privacy_url` filters. |
| Consent | Ready for review | A fresh installation does not connect on its own. `SiteConnector::ensureValid()` only repairs an existing key that has become invalid, detached, or revoked; with no key it reports not-connected and makes no request. The site domain and administrator email are sent only when an administrator clicks "Connect to LingoWP Cloud" — offered in onboarding beside "Continue without connecting", and again in the Workspace rail and on the Account page — always next to the Terms and Privacy Policy links. Onboarding's only prior request is an unauthenticated `GET /health` reachability check with no site data. Verified by `tests/db/no_auto_connect_it.php`. Insights is fine: its fixed support suggestion is sent only after its button is clicked. |
| Third-party code (Guideline 8) | Ready for review | No code is installed, updated, or executed from any server but WordPress.org's. The former add-on installer (`AddonInstaller.php`, `POST /billing/addons/{id}/install`, `BackendClient::downloadAddonZip()`) is removed. Account → Integrations now only relays a short-lived signed download URL that the administrator's browser opens; the zip is installed through WordPress's own uploader. `grep -rn "unzip_file\|activate_plugin\|WP_PLUGIN_DIR" src` now finds only a read-only path lookup for installed translation files (`InstalledGettextComponentDiscovery`) and `deactivate_plugins()` of LingoWP itself in the `WP_DEBUG`-only reset tool. Add-on updates are the add-ons' own concern; they are not distributed through this plugin. |
| Privacy guide | Ready for review | Suggested policy text is registered through WordPress's Privacy Policy Guide. Final legal links remain a release gate. |
| Internationalization | Blocked | PHP uses the `lingowp` text domain and script translations are registered. Many React strings still need conversion to WordPress i18n calls before submission. |
| Uninstall/deactivation | Ready for review | Known options, transients, and Action Scheduler hooks are cleaned up. Confirm retention expectations manually before release. |
| Bundled code and assets | Needs review | `THIRD-PARTY-NOTICES.md` summarizes dependencies. Compare it with both lockfiles and the actual ZIP before release — this check has already caught one real defect: Action Scheduler was listed as bundled but was absent from the archive entirely (see `release-readiness.md`). |
| Readme and metadata | Needs verification | Marketplace fields are populated and legal URLs are published. `Tested up to` is 7.1, matching the development environment the plugin actually runs on; contributor identity and name availability still require owner confirmation. |
| Runtime requirements | Needs verification | The header and `composer.json` both declare PHP 7.4, not 8.1, and no runtime PHP-version check exists anywhere in `src/` — a site below the declared floor is stopped by WordPress's own header check only. WordPress 6.2 is declared. DOM and mbstring are relied on; ZipArchive-dependent imports fail safely when unavailable. Decide whether 7.4 is the intended floor and test it, or raise the declared value. |

## Submission decision

Do not submit until every blocked item in `release-readiness.md` is resolved.
Polaris is the one explicitly accepted exception: submit with that known risk and
decide whether to abandon the directory release if the reviewer rejects it.
