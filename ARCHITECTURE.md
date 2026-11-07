# Architecture

How Linvara is put together: the path a piece of text takes from your site
to a translated page, where each part lives in `src/`, and the decisions
that shape the code. Read this before your first change; the code itself
carries the detail, in comments next to the rules they explain.

## The big picture

Every translated page goes through the same four steps:

```
  discover            store                 translate              render
 ───────────►  ───────────────────►  ─────────────────────►  ─────────────────►
 find visible   one row per source     by hand on the board,   swap the text in,
 text: content, text, keyed by a       or AI via Linvara       per language, on
 meta, terms,   hash of its            Cloud (optional)        every request,
 options, theme normalized form                                with /fr/ URLs
 HTML
 src/Extraction src/Database           src/Board               src/Resolution
                                       src/AiTranslation       src/LocalizationRouting
```

- **Discovery is automatic.** Content is scanned when it is saved, existing
  content is scanned once in the background after activation, and theme
  text (headers, footers, template strings) is found by crawling the site's
  own pages.
- **Rendering never edits your content.** Translations live in Linvara's
  own tables; WordPress filters and one page-level pass replace the text as
  a page is built, for the visitor's language only. The default language is
  served untouched.
- **Plugin and theme strings** (their `__()` / `_e()` text) follow a parallel
  path in `src/GettextDomains`: scanned from their source code, translated on
  their own board, written to `.mo` files that WordPress loads.

## Boot

`lingowp.php` defines the constants, loads the Composer autoloader and the
bundled Action Scheduler, registers activation/deactivation and the
multisite hooks, then calls `Setup\Plugin::boot()` on `plugins_loaded`.

`Plugin` is the composition root: plain objects built once and passed where
they are needed, no container. It wires the plugin in phases, in this order:

| Phase | Method | What it sets up |
|---|---|---|
| Language | `bootLanguage()` | Which languages exist, which one this request is in, URL building. Runs the schema upgrade. |
| Routing | `bootLocalizationRouting()` | Language rewrite rules, translated slugs, redirects. |
| Extraction | `bootExtraction()` | Save-time scanners, the initial scan, the theme HTML crawl. |
| Resolution | `bootResolution()` | Render filters, the page-level pass, SEO output. |
| Backend + Board | `bootBackendAndBoard()` | REST API for the admin app, cloud connection, AI queue. Only in wp-admin, REST and cron — never on a visitor's page view. |
| Admin | `bootAdmin()` | Admin pages, assets, Site Health panel. |

## `src/`, folder by folder

| Folder | Role | Start with |
|---|---|---|
| `Setup` | Bootstrap, activation (including multisite: every site of a network), deactivation. | `Plugin`, `Activator` |
| `Language` | The language list (stored in options), the request's language, locale and flag helpers. | `OptionLanguageRegistry`, `ResolveRequestLanguage`, `LocaleNormalizer` |
| `LocalizationRouting` | `/fr/` URL prefixes, translated slugs, language-preserving redirects, the links behind the switcher. | `LanguageRouteRegistrar`, `LocalizedUrlBuilder`, `SlugMap` |
| `Extraction` | Finding text: on save (`Structured/`), the one-time background scan, options and widgets, and the crawl that finds theme text in rendered pages (`Html/`). | `StructuredSourceScanner`, `InitialDiscoveryScan`, `HtmlDiscovery` |
| `Shared` | Building blocks used everywhere: the source types (`Source/Type/`), text normalization and hashing (`Text/`), the HTML unit splitter and codec, translation guards (`Parsing/`). | `HtmlUnitExtractor`, `TranslationUnitCodec`, `StringNormalizer` |
| `Database` | Schema, the repositories over Linvara's tables, foreign-key checks, the orphan sweep, uninstall cleanup. | `TranslationMemorySchema`, `TableDefinitions`, `Repository/WpDbSourceRepository` |
| `Board` | The translation board's data and REST endpoints (collections, units, saves). | `CollectionBoard` |
| `Resolution` | Rendering translations: post/term/meta/bio filters, options, dates and numbers, the page-level HTML pass, link rewriting, and `Seo/` (hreflang, sitemap, robots, `<html lang>`, SEO plugin fields). | `StructuredRenderResolver`, `PublicRenderPipeline`, `HtmlRenderer` |
| `GettextDomains` | The Plugins & Themes board: scanning a plugin's or theme's source for strings, `.mo` writing and loading, community translations from WordPress.org, AI for those strings. | `GettextTranslationUnitsService`, `GettextMoFileLoader`, `GettextPoImporter` |
| `AiTranslation` | Sending units to Linvara Cloud and applying results, through Action Scheduler jobs. | `AiTaskSubmitter`, `AiResultCollector` |
| `Backend` | The cloud connection (opt-in), billing and entitlements, which add-ons fit this site. | `BackendClient`, `SiteConnector`, `ConnectionRepository` |
| `Switcher` | The language switcher: rendering, shortcode, saved design, admin preview. | `Switcher`, `SwitcherSettings` |
| `Admin`, `Onboarding` | Admin pages and assets, the setup flow, admin-bar link, Site Health report. | `OnboardingPageController` |
| `Insights`, `Support`, `Privacy` | The Insights preview screen, the support form, privacy-policy text. | — |

## Data model

Linvara stores everything in its own tables (`wp_lingowp_*`, InnoDB), never
in post content. There are seven kinds of source text, each with a **parent**
table (the original text) and a **child** `_translation` table (one row per
language):

| Source | Parent table | What it holds |
|---|---|---|
| post | `lingowp_post` | Titles, excerpts, content pieces |
| meta | `lingowp_meta` | Allow-listed custom fields |
| term | `lingowp_term` | Category / tag names and descriptions |
| term_meta | `lingowp_term_meta` | Allow-listed term fields |
| option | `lingowp_option` | Site title, tagline, widgets, plugin settings |
| user | `lingowp_user` | Author bios |
| html | `lingowp_html` | Theme text found by the crawl |

Two ways a translation is linked to its source:

- **By text** (post, term, option, user, html): the child is keyed by
  `original_hash`, so an identical sentence on ten pages shares one
  translation.
- **By position** (meta, term_meta): the child is keyed by the parent row,
  because a custom field's context can change how the same words translate.

Parents carry a foreign key to the WordPress row they come from, so deleting
a post, term, option or user cleans up its rows (`html` has none: theme text
belongs to no row). Translations of text that no longer appears anywhere are
reclaimed by a daily sweep, after a one-hour grace period. A separate
`lingowp_gettext_unit` table holds the Plugins & Themes board's source
strings; their translations live in `.mo` files under
`wp-content/uploads/linvara/languages/`, not in the database.

## The admin app

`admin-ui/` is a React app built with `@wordpress/scripts`, mounted on
Linvara's admin pages. Two rules keep it predictable:

- **All server calls go through React Query.** Reads are `useQuery` /
  `useInfiniteQuery`, writes are `useMutation` and invalidate the affected
  query keys (`admin-ui/queryKeys.js`). The raw REST client
  (`admin-ui/api.js`) may only be imported from `admin-ui/queries/`; an
  ESLint rule fails the build otherwise.
- **The REST API lives under `lingowp/v1`**, every route behind a capability
  check.

The front-end switcher (`assets/switcher/src/`) is a separate, small Vite
bundle with no React, using Floating UI to position its dropdown.

## Extending

- **PHP functions:** `lingowp_get_language_links()`, `lingowp_get_switcher()`,
  `lingowp_switcher()`, and `lingowp_source_repository()` for add-ons.
- **Shortcode:** `[lingowp_language_switcher]`.
- **Filters** (all prefixed `lingowp_`): which taxonomies, meta keys, term meta
  keys, user fields and options are translatable
  (`lingowp_translatable_taxonomies`, `lingowp_translatable_meta_keys`,
  `lingowp_option_paths`, …), the switcher's links
  (`lingowp_language_links`), and the SEO integration points
  (`lingowp_seo_*`).

The add-ons (Elementor, WooCommerce, ACF, SEO, TranslatePress migration) are
separate plugins built on these filters and on `lingowp_source_repository()`;
they need no change to this plugin's core.

## Decisions worth knowing

- **The text hash is a contract.** A unit's key is the SHA-256 of its
  normalized form (`StringNormalizer`). Linvara Cloud hashes the same way to
  match AI results back; changing the normalization on one side breaks every
  existing match.
- **Only what a visitor can see is translated.** Drafts, private posts and
  revisions are skipped. Slugs are never translated on their own: a
  translated URL (`/fr/a-propos/`) is derived from the translated title.
- **Foreign keys are unnamed.** MySQL constraint names are unique per
  database, so fixed names made every multisite site after the first fail to
  install. InnoDB names them per table.
- **Files go to uploads, nowhere else.** `.mo` files are written only under
  `uploads/linvara/languages/`, with every path built from validated values.
- **Output is escaped where it is returned.** Translated titles, excerpts and
  bios go through `wp_kses_post()` at each filter's return; content is
  escaped per translated piece, so a post's own markup (embeds included)
  is kept.
- **The cloud is opt-in and local code is never locked.** Everything that runs
  on your site works without an account; only the AI service and account
  features need Linvara Cloud, and nothing is sent before you connect.
- **Background work uses Action Scheduler**, bundled, deferring to a newer
  copy if another plugin (WooCommerce) ships one.
