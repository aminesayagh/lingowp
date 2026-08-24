# WordPress.org compliance audit

Status reflects repository review only. Nothing here claims that Plugin Check,
security scanners, compatibility checks, or marketplace review have passed.

| Area | Status | Evidence or remaining action |
| --- | --- | --- |
| Plugin identity | Ready for review | Header, constant, package metadata, readme stable tag, and release script use `1.0.0`. Verify `aminesayagh` is the intended WordPress.org username. |
| GPL licensing | Ready for review | Plugin declares GPL-2.0-or-later and includes `LICENSE`. Composer runtime dependencies declare compatible licenses. |
| Shopify Polaris | Accepted risk | Polaris is intentionally retained. Its license restricts its permitted use, so WordPress.org may reject the compiled dependency as non-GPL-compatible. |
| Human-readable source | Blocked | `readme.txt` identifies the exact `v1.0.0` source tag. The repository and tag must be publicly accessible before submission. |
| External services | Blocked | Data flows are disclosed, but final public Terms of Service and Privacy Policy URLs have not been supplied. |
| Consent | Ready for review | A fresh installation does not connect automatically. An administrator must select the cloud connection action. Insights submits a fixed machine-coded support suggestion only after its notification button is clicked. |
| Privacy guide | Ready for review | Suggested policy text is registered through WordPress's Privacy Policy Guide. Final legal links remain a release gate. |
| Internationalization | Blocked | PHP uses the `translangs` text domain and script translations are registered. Many React strings still need conversion to WordPress i18n calls before submission. |
| Uninstall/deactivation | Ready for review | Known options, transients, and Action Scheduler hooks are cleaned up. Confirm retention expectations manually before release. |
| Bundled code and assets | Needs review | `THIRD-PARTY-NOTICES.md` summarizes dependencies. Compare it with both lockfiles and the actual ZIP before release. |
| Readme and metadata | Needs verification | Marketplace fields are populated, but `Tested up to`, contributor identity, name availability, and legal URLs require owner confirmation. |
| Runtime requirements | Ready for review | WordPress 6.2, PHP 8.1, DOM, and mbstring are declared or checked. ZipArchive-dependent imports fail safely when unavailable. |

## Submission decision

Do not submit until every blocked item in `release-readiness.md` is resolved.
Polaris is the one explicitly accepted exception: submit with that known risk and
decide whether to abandon the directory release if the reviewer rejects it.
