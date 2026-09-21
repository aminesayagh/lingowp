=== LingoWP ===
Contributors: masayagh
Tags: multilingual, translation, localization, ai translation
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Translate WordPress content locally, with optional AI-assisted translation through LingoWP Cloud.

== Description ==

LingoWP makes a WordPress site multilingual without duplicating it. Your posts, pages, and settings stay exactly as they are — the single source of truth — and LingoWP stores each translation next to the original, then swaps it in when a visitor reads the site in another language. Everything below works entirely on your own server, with no account and no external service.

= What makes LingoWP different =

* **No duplicate posts.** Most multilingual plugins clone every post per language, so you end up managing three copies of every page, menu, and category. LingoWP keeps one canonical post and stores translations in its own tables keyed to the original text. Delete the original and its translations go with it; edit the original and the changed sentences are flagged for re-translation while the rest stay translated.
* **Finds translatable text for you, across everything.** Instead of asking you to register strings, LingoWP discovers them: post titles and content (block by block, table cell by table cell), categories and tags, custom fields (including nested and repeater-style values), author bios, site title and tagline, widgets, theme customizer text, text stored inside block settings such as Search button labels and Navigation items, plus theme-rendered text that appears on the page but lives in no post at all. It all lands on one board, in one place.
* **Plugin and theme strings included.** The text your theme and plugins output through WordPress's translation functions gets its own board. Fetch WordPress.org's existing language pack for a plugin and translate only the strings it missed, import and export standard PO files, and see how much of each plugin is still untranslated — no separate translation-editor plugin needed.
* **An editor that cannot break your markup.** Links, bold text, and shortcodes inside a sentence become numbered placeholders while you translate, so word order is free but the tags and shortcodes are always rebuilt from the original. A translation with a missing or misplaced placeholder is rejected before it saves.
* **Translate once, apply everywhere.** Translations are keyed to the text itself, so a sentence that appears in ten places is translated once.
* **Language-aware frontend, done in the plugin.** Language-prefixed URLs, translated internal links, `hreflang` tags, the page's `lang` and `dir` attributes for RTL languages, localized dates and numbers, optional browser-language and cookie-based redirects, and a per-language fallback chain so a missing translation shows the next best language instead of nothing.
* **A switcher you can drop anywhere.** A dropdown, inline, or list switcher with flag icons, available as a shortcode, a template function, and a filterable link array for building your own. The dropdown works without JavaScript.
* **AI is optional and never required.** If you connect to LingoWP Cloud, you can translate with LingoWP's hosted models or bring your own OpenAI or Anthropic key. The plugin itself contains no translation engine and never contacts any service until an administrator chooses to connect.

Add-ons for Elementor, WooCommerce, Advanced Custom Fields, and the major SEO plugins extend discovery to their content through public hooks; they are separate plugins, not bundled here.

Connecting to LingoWP Cloud is optional and never automatic. During setup, an administrator chooses "Connect to LingoWP Cloud" or "Continue without connecting"; the connection can also be made later from the Workspace or Account page. Connecting enables AI translation, provider-key management, licensing, billing, and account management. An administrator can fully disconnect at any time from the Account page's Danger zone; disconnecting disables all cloud features until reconnected, and does not affect any manually translated content.

The Insights screen is an intentional preview of a possible future feature. Opening it does not submit demand data. For a connected site, its “Notify me at launch” button voluntarily submits the fixed product-interest code `feature_interest:translangs_insights` through the support suggestion service. The plugin does not include an email address in that request; the service associates it with the authenticated license owner, falling back to the site administrator email.

= External services =

LingoWP Cloud is hosted at https://cloud.lingowp.com and provides optional account, AI translation, provider-key, billing, and authenticated support-suggestion services.

When an administrator clicks "Connect to LingoWP Cloud", the site domain and WordPress administrator email address are sent to create the site connection. No request is made to LingoWP Cloud before that click, other than an unauthenticated reachability check during setup that sends no site data. AI translation requests send the selected source text, source and target languages, and grouping identifiers, and only when an administrator explicitly requests AI translation. Provider keys entered by an administrator are sent for provider validation and encrypted storage. Billing and account actions send the information necessary to perform the requested action. Clicking “Notify me at launch” sends the legacy backend code `feature_interest:translangs_insights` with the site's existing authentication and domain headers. It does not send an email address in the request body. An administrator can disconnect entirely at any time from the Account page's Danger zone, which stops all further requests to LingoWP Cloud until reconnected.

Local manual translation works fully without LingoWP Cloud; disconnecting does not affect it.

LingoWP Cloud Terms of Service: https://www.lingowp.com/legal/terms-of-service/
LingoWP Cloud Privacy Policy: https://www.lingowp.com/legal/privacy-policy/
Refunds policy: https://www.lingowp.com/legal/refunds-policy/
Legal notice: https://www.lingowp.com/legal/legal-notice/

LingoWP can also contact WordPress.org language-pack APIs when an administrator explicitly requests an available translation package for an installed theme or plugin.

= Human-readable source =

Everything in `assets/admin/build/` is compiled output. The human-readable JavaScript and CSS source it is built from ships in this package: the `admin-ui/` directory, together with `package.json`, `package-lock.json`, `webpack.config.js`, and `postcss.config.js`.

To reproduce the compiled files from that source, with Node.js 20 or newer, run from the plugin directory:

`npm ci`
`npm run build`

That runs `wp-scripts build admin-ui/index.js --output-path=assets/admin/build` and writes the same bundles shipped here.

== Installation ==

1. Upload the `lingowp` folder to `/wp-content/plugins/`, or install it through the WordPress Plugins screen.
2. Activate LingoWP.
3. Open LingoWP in wp-admin and choose the source and target languages.
4. Choose whether to connect to LingoWP Cloud (for AI translation, billing, and account features) or continue without connecting. You can connect later from the Workspace or Account page, and disconnect anytime from Account → Danger zone.

== Frequently Asked Questions ==

= Does LingoWP require a cloud account? =

No. Local manual translation works fully without using any LingoWP Cloud feature. The site never connects on its own: an administrator chooses to connect during setup or later from the Workspace or Account page, and can fully disconnect at any time from the Account page.

= When is content sent to LingoWP Cloud? =

The site domain and administrator email are sent only when an administrator clicks "Connect to LingoWP Cloud". Translation text is sent only when an administrator explicitly requests AI translation. An administrator can disconnect entirely at any time from the Account page's Danger zone.

= How are LingoWP add-ons (Elementor, WooCommerce, ACF, SEO) installed? =

Like any other plugin, by you, through WordPress's own Plugins screen. Account → Integrations only shows which add-ons your plan includes for the plugins active on your site and which are already installed; it offers no download or install action. LingoWP itself never downloads, installs, or updates any code.

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

= 1.3.1 =

* Initial marketplace release candidate.
