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

LingoWP connects to LingoWP Cloud automatically on first use to enable AI translation, provider-key management, licensing, billing, and account management. An administrator can fully disconnect at any time from the Billing page's Danger zone; disconnecting disables all cloud features until reconnected, and does not affect any manually translated content.

The Insights screen is an intentional preview of a possible future feature. Opening it does not submit demand data. For a connected site, its “Notify me at launch” button voluntarily submits the fixed product-interest code `feature_interest:translangs_insights` through the support suggestion service. The plugin does not include an email address in that request; the service associates it with the authenticated license owner, falling back to the site administrator email.

= External services =

LingoWP Cloud is hosted at https://cloud.lingowp.com and provides optional account, AI translation, provider-key, billing, and authenticated support-suggestion services.

On first use, the site domain and WordPress administrator email address are sent automatically to create the site connection; this is the only data sent without a further administrator action. AI translation requests send the selected source text, source and target languages, and grouping identifiers, and only when an administrator explicitly requests AI translation. Provider keys entered by an administrator are sent for provider validation and encrypted storage. Billing and account actions send the information necessary to perform the requested action. Clicking “Notify me at launch” sends the legacy backend code `feature_interest:translangs_insights` with the site's existing authentication and domain headers. It does not send an email address in the request body. An administrator can disconnect entirely at any time from the Billing page's Danger zone, which stops all further requests to LingoWP Cloud until reconnected.

Local manual translation works fully without LingoWP Cloud; disconnecting does not affect it. Service terms and privacy-policy URLs must be finalized and added before marketplace submission; this is tracked in `docs/wordpress-org/release-readiness.md`.

LingoWP can also contact WordPress.org language-pack APIs when an administrator explicitly requests an available translation package for an installed theme or plugin.

= Human-readable source =

The human-readable JavaScript and CSS source corresponding to version 1.0.0 is published at https://github.com/aminesayagh/lingowp/tree/v1.0.0. Build instructions are included in the repository and the release script rebuilds the admin assets from that source.

== Installation ==

1. Upload the `lingowp` folder to `/wp-content/plugins/`, or install it through the WordPress Plugins screen.
2. Activate LingoWP.
3. Open LingoWP in wp-admin and choose the source and target languages.
4. LingoWP connects to LingoWP Cloud automatically to enable AI translation, billing, and account features; translate manually if you prefer, or disconnect anytime from Billing → Danger zone.

== Frequently Asked Questions ==

= Does LingoWP require a cloud account? =

No. Local manual translation works fully without using any LingoWP Cloud feature. The site does connect automatically in the background to make those features available when needed; an administrator can fully disconnect at any time from the Billing page.

= When is content sent to LingoWP Cloud? =

The site domain and administrator email are sent automatically on first use to establish the connection. Translation text is sent only when an administrator explicitly requests AI translation. An administrator can disconnect entirely at any time from the Billing page's Danger zone.

= Does the Insights preview track visitors? =

No. The preview contains illustrative placeholders. On a connected site, clicking “Notify me at launch” sends a fixed Insights-interest code through the authenticated support service; no visitor data or frontend-supplied email address is included.

== For developers ==

LingoWP exposes a small template API for building your own language switcher.

`lingowp_get_language_links( array $args = [] )` returns the current page's URL
in every enabled language, as an array of rows with keys `code`, `slug`, `url`
(absolute), `native_name`, `english_name`, `dir` (`ltr`/`rtl`), `region` (ISO
region from the locale, or empty), `flag_url` (bundled flag SVG, or empty when
none maps), `is_source`, and `current`. Values are raw — escape at output. It
runs no database query and is memoized per request. Args: `hide_current`
(bool), `include` (array of locale codes), `order` (`settings`, `alpha`, or an
explicit array of codes).

`lingowp_get_switcher( array $args = [] )` returns the bundled switcher markup;
`lingowp_switcher( array $args = [] )` echoes it. Args: `display` (`native`,
`english`, `code`), `layout` (`dropdown`, `inline`, `list`), `flags` (`true`,
`false`, or `only` — default `true` for `dropdown`, `false` otherwise; `only`
keeps the label for screen readers), `flag_fallback` (`globe`, `code`, `none`
— shown when a language has no bundled flag), `size` (`lg` default, `md`, `sm`),
`shadow` (`true` default; `false` removes the control's drop shadow),
`hide_current`, `label` (the wrapper's aria-label), `id`, `class`, `include`,
`order`.

The dropdown is a native `<details>` element and works without JavaScript; a
small bundled script adds `aria-expanded`, close-on-Escape / click-outside, and
arrow-key navigation when it loads.

The `[lingowp_language_switcher]` shortcode accepts the same attributes (as
strings; `include`/`order` take a comma-separated list) and works in every page
builder.

Filter `lingowp_language_links` ( `array $links`, `array $args` ) to reorder,
relabel, or drop languages once for every switcher surface at the same time.

Language-prefixed URLs require pretty permalinks (Settings → Permalinks).

== Privacy ==

LingoWP adds suggested disclosure text to WordPress's Privacy Policy Guide. Site owners remain responsible for adapting that text to their configuration and publishing an accurate privacy policy.

== Changelog ==

= 1.0.0 =

* Initial marketplace release candidate.
