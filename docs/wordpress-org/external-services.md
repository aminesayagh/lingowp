# External services and consent

## LingoWP Cloud

- Endpoint origin: `https://cloud.lingowp.com`.
- Trigger: an explicit administrator action only. The onboarding form
  offers "Connect to LingoWP Cloud" (primary) and "Continue without
  connecting"; the same connect button exists in the Workspace rail and on
  the Billing page. Nothing connects on its own — `SiteConnector::ensureValid()`
  only repairs a key that already exists and has become invalid, detached, or
  revoked; a missing key is left alone. Before offering the connect button,
  onboarding sends one unauthenticated `GET /health` reachability check that
  carries no site data. An administrator can fully disconnect at any time from
  Billing → Danger zone, which stops all further requests to LingoWP Cloud
  until they explicitly reconnect.
- Connection data: site domain and WordPress administrator email — the only
  two fields the connect request actually sends.
- AI translation data: selected source text, source/target language identifiers,
  and translation grouping identifiers, sent only when AI translation is requested.
- Provider keys: keys entered by an administrator are sent for validation and
  encrypted storage by the service.
- Account and billing: site, owner, subscription, and transaction information
  required by the action selected by the administrator.
- Insights preview: opening the preview sends nothing. On a connected site,
  clicking “Notify me at launch” submits `feature_interest:translangs_insights`
  to `/support/suggestions` using the stored bearer credential and site-domain
  header. The request body contains no email address. The backend associates the
  suggestion with the verified license owner, falling back to the site admin email.

Local discovery, manual translation, translation storage, and frontend rendering
remain available without LingoWP Cloud.

## WordPress.org language-pack API

When an administrator requests an available gettext package for an installed
theme or plugin, LingoWP may request package metadata and download the package
from WordPress.org. The request concerns the installed component and requested
locale; it is not an automatic visitor-tracking request.

## Published legal pages

- Terms of Service: https://www.lingowp.com/legal/terms-of-service/
- Privacy Policy: https://www.lingowp.com/legal/privacy-policy/
- Refunds policy: https://www.lingowp.com/legal/refunds-policy/
- Legal notice: https://www.lingowp.com/legal/legal-notice/

The Terms and Privacy URLs are linked from `readme.txt` and are the defaults
behind the `lingowp_cloud_terms_url` and `lingowp_cloud_privacy_url` filters, so
the cloud connection UI links to them without any site-side configuration. The
filters remain in place for white-labelled deployments.

## Release blockers

- None known for consent: the first request carrying site data happens only
  after the administrator clicks "Connect to LingoWP Cloud", next to the
  Terms and Privacy Policy links. See `release-readiness.md` for the
  remaining non-consent items.
- Keep the URLs and data descriptions above in sync across `readme.txt`, the
  cloud connection UI, the service website, and `PrivacyPolicyContent`.
