# External services and consent

## LingoWP Cloud

- Endpoint origin: `https://translangs-api.masayagh.com`.
- Trigger: connects automatically on the first admin page load after
  activation — no separate onboarding step or connection action required. An
  administrator can fully disconnect at any time from Billing → Danger zone,
  which stops all further requests to LingoWP Cloud until they reconnect
  (an explicit "Connect" action, which re-enables automatic reconnection).
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

## Release blockers

- Publish final LingoWP Cloud Terms of Service and Privacy Policy URLs.
- Configure the `lingowp_cloud_terms_url` and
  `lingowp_cloud_privacy_url` filters, or replace their empty defaults with
  canonical production URLs before submission.
- Ensure the same URLs and data descriptions appear in `readme.txt`, the cloud
  connection UI, the service website, and the WordPress privacy-policy guidance.
