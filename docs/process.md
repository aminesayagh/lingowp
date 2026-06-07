# Plugin Boot Process

What happens, in order, when WordPress calls `(new Plugin())->boot()`.

---

## 1. Ensure the database is ready

Before anything else, the plugin checks whether its custom tables exist and are
at the expected schema version. If they are outdated or missing, it runs the
install/upgrade SQL. This guarantees every step that follows can safely read
from and write to the database.

---

## 2. Load the language configuration

The plugin reads the list of languages the site owner has configured (stored in
the WordPress database). It also sets up the logic that will detect, on every
incoming request, which language the visitor is asking for — based on the URL
prefix (e.g. `/fr/`, `/es/`).

---

## 3. Assemble the translation memory

The translation memory is the central store for all translatable content. It
spans four storage areas:

- **Post fields** — title, content, excerpt of posts and pages
- **Post-meta fields** — custom fields attached to posts
- **Term fields** — category and tag names and descriptions
- **i18n strings** — PHP `__()` / `_e()` strings captured at runtime

These are wired together into one unified repository that the rest of the
plugin reads from and writes to.

---

## 4. Register the background capture worker

When a visitor lands on a translated page and the plugin encounters strings it
has not seen before, it does not block the response to save them. Instead it
schedules an async job (via Action Scheduler). This worker runs outside the
HTTP request, reads the queued batch, and inserts the newly discovered strings
into the translation memory for every configured target language.

---

## 5. Set up URL routing for translated pages

WordPress does not know about language-prefixed URLs out of the box. The plugin
registers its own rewrite rules so that `/fr/about` is recognized as "the
French version of the About page" rather than a 404. It also registers the
`lang` query variable that carries the detected language through the WordPress
request lifecycle.

---

## 6. Register the language-switcher shortcode

Adds a `[translangs_switcher]` shortcode that renders a list of links letting
visitors jump between the same page in different languages. The accompanying
CSS and JS are also enqueued here.

---

## 7. Inject SEO tags for translated pages

For every translated page, the plugin:

- Outputs `<link rel="alternate" hreflang="...">` tags in `<head>` covering
  all available language versions, so search engines discover and index them.
- Overrides the canonical URL so a translated page points to itself, not to
  the original-language version. Works with WordPress core, Yoast SEO, and
  Rank Math.

---

## 8. Wire cache invalidation

Translated pages are cached as plain HTML for performance. The plugin hooks
into two WordPress events to flush that cache when the content it reflects
has changed:

- A post is saved or updated → flush that post's cached translations.
- The active theme is switched → flush the entire translation cache (theme
  templates affect every page).

---

## 9. Re-scan content when a post is saved

Whenever a post is inserted or updated, the plugin re-scans its translatable
fields (content, title, custom fields) and updates the translation memory with
any new or changed source strings. This keeps the memory in sync with the
actual content without requiring a manual rescan.

---

## 10. Start the public render pipeline

This is the core translation step for frontend visitors.

When a request comes in for a translated-language URL, the pipeline:

1. **Checks the cache.** If a previously translated HTML page exists for this
   URL + language, it is served immediately and the request ends there.

2. **Buffers the original HTML.** If there is no cached version, WordPress
   renders the page normally in the default language. The pipeline intercepts
   the entire HTML output before it is sent to the browser.

3. **Translates the HTML.** The buffered HTML is parsed; every recognized text
   segment is replaced with the stored translation for the requested language.
   Gettext strings (`__()` / `_e()` calls) captured during rendering are also
   matched.

4. **Caches the result.** If any replacements were made, the translated HTML is
   stored in the cache so the next visitor gets a fast cached response.

5. **Queues new strings.** Any text segments that had no translation yet are
   collected and handed off to the background capture worker (step 4) so they
   appear in the translation memory and can be translated by the editor.

---

## 11. Boot the admin UI (admin requests only)

The following only runs when the current request is inside the WordPress admin:

- **Enqueue admin assets** — the CSS and JavaScript needed for the plugin's
  admin pages.

- **Languages settings page** — lets the site owner add, remove, and configure
  target languages.

- **Translation editor page** — the main workspace where a translator can
  search all captured strings, write or edit translations, mark strings for
  review, approve reviewed translations, and ignore strings that should not be
  translated. Also exposes a manual "scan structured sources" action that
  re-indexes all posts, terms, and custom fields on demand.
