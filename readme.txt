=== LingoWP ===
Contributors: aminesayagh
Tags: multilingual, translation, localization, ai translation
Requires at least: 6.2
Tested up to: 6.2
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Translate WordPress content locally, with optional AI-assisted translation through LingoWP Cloud.

== Description ==

LingoWP creates language-specific versions of WordPress content while keeping the original content as the source. Manual translation and frontend language selection work locally on your WordPress site.

Connecting to LingoWP Cloud is optional and requires an administrator to choose the connection action. Cloud features include AI translation, provider-key management, licensing, billing, and account management.

The Insights screen is an intentional preview of a possible future feature. Opening it does not submit demand data. For a connected site, its “Notify me at launch” button voluntarily submits the fixed product-interest code `feature_interest:translangs_insights` through the support suggestion service. The plugin does not include an email address in that request; the service associates it with the authenticated license owner, falling back to the site administrator email.

= External services =

LingoWP Cloud is currently hosted at the legacy technical endpoint https://translangs-api.masayagh.com and provides optional account, AI translation, provider-key, billing, and authenticated support-suggestion services.

When an administrator connects, the site domain, WordPress administrator email address, default language, WordPress version, plugin version, and relevant site metadata may be sent to create or validate the site connection. AI translation requests send the selected source text, source and target languages, and grouping identifiers. Provider keys entered by an administrator are sent for provider validation and encrypted storage. Billing and account actions send the information necessary to perform the requested action. Clicking “Notify me at launch” sends the legacy backend code `feature_interest:translangs_insights` with the site's existing authentication and domain headers. It does not send an email address in the request body.

No LingoWP Cloud request is required for local manual translation. Service terms and privacy-policy URLs must be finalized and added before marketplace submission; this is tracked in `docs/wordpress-org/release-readiness.md`.

LingoWP can also contact WordPress.org language-pack APIs when an administrator explicitly requests an available translation package for an installed theme or plugin.

= Human-readable source =

The human-readable JavaScript and CSS source corresponding to version 1.0.0 is published at https://github.com/aminesayagh/lingowp/tree/v1.0.0. Build instructions are included in the repository and the release script rebuilds the admin assets from that source.

== Installation ==

1. Upload the `lingowp` folder to `/wp-content/plugins/`, or install it through the WordPress Plugins screen.
2. Activate LingoWP.
3. Open LingoWP in wp-admin and choose the source and target languages.
4. Translate content manually, or explicitly connect LingoWP Cloud if you want cloud features.

== Frequently Asked Questions ==

= Does LingoWP require a cloud account? =

No. Local manual translation does not require a cloud connection.

= When is content sent to LingoWP Cloud? =

Only after an administrator explicitly connects, and translation text is sent only when an administrator requests AI translation.

= Does the Insights preview track visitors? =

No. The preview contains illustrative placeholders. On a connected site, clicking “Notify me at launch” sends a fixed Insights-interest code through the authenticated support service; no visitor data or frontend-supplied email address is included.

== Privacy ==

LingoWP adds suggested disclosure text to WordPress's Privacy Policy Guide. Site owners remain responsible for adapting that text to their configuration and publishing an accurate privacy policy.

== Changelog ==

= 1.0.0 =

* Initial marketplace release candidate.
