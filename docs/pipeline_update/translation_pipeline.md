# TransLangs — Translation Pipeline (Discovery → Hooks → Render)

> The end-to-end pipeline: how strings are first discovered, kept fresh on
> content changes, captured from gettext, and replaced at render — and the
> ownership rules that stop the same string being stored twice. Builds on the
> five-table schema (`goal_data_migration.md`) plus the i18n additions in §6.

---

## 0. The core principle

> **Structured sources are the source of truth for *ownership*. Rendered HTML is
> the source of truth for *"this appeared on the page"* — never for who owns the
> text.**

Three independent lifecycles feed the same translation memory:

```
A. Initial discovery   — scan the DB once, fill the tables
B. Hook updates        — keep tables fresh as content changes
C. Render-time         — replace on the page; capture only genuine orphans
```

All three fan out to enabled target languages and obey one ownership rule
(§5) before anything lands in `i18n`.

---

## A. Initial discovery (structured scan)

Runs on demand (admin "Scan" button) or first activation. Reads content from
where WordPress actually stores it — never by rendering pages.

**Scan order (global/reusable before ordinary content, so references resolve):**
```
1. attachment        2. nav_menu_item    3. wp_navigation
4. wp_block          5. wp_template_part 6. wp_template
7. page              8. post             9. public CPTs
10. (WooCommerce products — deferred)    11. skip internal/system types
```

**Per source kind → reader → routeTable:**

| Reader | Reads | Routes to | field / key |
|---|---|---|---|
| Terms | term name/description/slug | `_terms` | by `term_taxonomy_id` |
| Posts/Pages/CPT | post_title/content/excerpt | `_post` | field name |
| Media (attachment) | title/caption/description | `_post` | field name |
| Media (attachment) | **alt** (`_wp_attachment_image_alt`) | `_postmeta` | meta_key, empty context |
| Post meta (named keys) | selected frontend meta | `_postmeta` | meta_key (+ context) |

Each reader emits `SourceString[]` → `ScanStructuredSources` normalizes + hashes
→ `DiscoverSourceStrings.discover()` inserts one `discovered` row per **active
target language** (never the source language), `INSERT IGNORE` (idempotent).

**This is the bulk of the table.** Most translatable text is owned content and
is discovered here, not by parsing pages.

---

## B. Hook updates (keep tables fresh)

Structured tables must track content changes. Each hook re-runs the relevant
reader for *one* entity and reconciles.

| Hook | Action |
|---|---|
| `created_term` / `edited_term` | re-discover that term's strings into `_terms` |
| `delete_term` | FK CASCADE on `term_taxonomy_id` removes its rows |
| `save_post` | re-discover that post's fields; **delete stale-hash rows** for it |
| `before_delete_post` | FK CASCADE on `post_id` removes `_post` + `_postmeta` rows |
| `trash`/`untrash` (status change) | leave rows; only permanent delete cascades |
| `added/updated/deleted_post_meta` (named keys) | re-discover / delete that meta's rows |
| plugin **uninstall** / theme switch | reconcile `_i18n` (see §6) |

**Stale-hash reconciliation (the subtle part):** when a post title changes
"Widget"→"Gadget", the old `hash(Widget)` row is now stale. After re-discovering
current fields, delete this entity's rows whose `original_hash` is **not** among
the current field hashes. This is what prevents orphaned-by-edit rows.

**`save_post` re-entrancy guard (required):**
```php
remove_action('save_post', [$this, 'onSavePost']);
// re-discover + stale-delete (may write meta) ...
add_action('save_post', [$this, 'onSavePost']);
```
Without it, a re-scan that touches meta re-fires `save_post` → infinite loop.

---

## C. Gettext capture (frontend, memory-only)

Theme/plugin UI strings (`__('Add to cart','woocommerce')`) are captured at
runtime, not by scanning PHP files (scanning shows what *exists*, not what's
*used on the frontend*).

**Hooks as lightweight collectors** (callback NEVER writes to DB):
`gettext`, `gettext_with_context`, `ngettext`, `ngettext_with_context`.

```
frontend request starts
  → gettext hooks fire many times
  → collector validates request area (frontend/ajax_frontend/selected rest)
  → normalized strings held IN MEMORY only (de-duped in the request)
  → request finishes
  → shutdown: enqueue the captured batch
  → background/cron/admin: bulk-insert into _i18n (source='gettext')
```

Capture areas: `frontend`, `ajax_frontend`, selected `rest` (frontend UI).
Skip: `admin`, `ajax_admin`, `cli`, `cron`, `login`.

Gettext i18n rows keep a **real** identity: `source='gettext'`,
`reference`=textdomain/file, eventually `_x()` context. (Full domain/plural
shape is future work; minimal now.)

---

## 5. Ownership resolution — the anti-duplication gate

The rule that stops HTML parsing from duplicating owned text into `i18n`.

**Before ANY HTML-parsed string is inserted as i18n:**
```
HTML segment
  → normalize → hash
  → OwnedStringIndex.existsOwnedHash(hash)   // checks _post, _postmeta, _terms
  → if owned (ANY status): DO NOT insert i18n        ← the guard
  → else if a gettext capture matched this request/hash: it's gettext i18n
  → else: rendered_html fallback i18n (text-only identity, §6)
```

**Ownership includes ALL statuses** — `discovered`, `machine`, `reviewed`,
`ignored`, `needs_review`. A *discovered-but-untranslated* post title is still
owned by the post; it must never be duplicated into i18n just because it's not
yet renderable.

### `OwnedStringIndex` service

```php
interface OwnedStringIndex {
    public function existsOwnedHash(string $originalHashRaw): bool;
    public function existsOwnedHashForPage(
        string $originalHashRaw, int $postId, array $termTaxonomyIds
    ): bool;
}
```
- Query-based across the three owned tables; **cached per request**.
- Prefers current-page context when available (cheap: the page's own preload
  maps already hold most owned hashes).
- First implementation queries existing tables; add a dedicated source-index
  table later only if measured too slow.
- **Not** a parent/join table — ownership is a *read*, not a structural FK.

---

## 6. i18n model (identity + observation)

i18n holds only text with **no** post/term/postmeta owner. Two identity styles:

**gettext-sourced** — real reference:
```
(target_lang, source='gettext', reference_hash=hash(domain/file), original_hash)
```

**rendered_html fallback — STRUCTURAL-SELECTOR identity:**
```
source        = 'rendered_html'
reference     = '{landmark}>{intermediate}>{tag}'   -- e.g. 'header>nav>a'
reference_hash = sha256(reference)
(target_lang, 'rendered_html', reference_hash, original_hash)
```
The `reference` column (same one gettext uses for the plugin/theme file) holds a
**two-level structural selector** for fallback chrome — built from the HTML tag
hierarchy, **never from CSS classes**.

**Selector construction:** `{nearest landmark ancestor} > {intermediate tag} >
{element tag}`, where a landmark is an HTML5 sectioning element (`header`, `nav`,
`main`, `footer`, `aside`, `article`, `section`, `form`). The parser
(`WP_HTML_Processor`) reads this cheaply from its breadcrumb stack: find the
nearest landmark ancestor, take the immediate child tag on the path, then the
element's own tag. Falls back to fewer levels if no landmark/intermediate
exists (`nav>a`, or bare `a`).

Examples:
```
"Contact" in header nav      → 'header>nav>a'
"Contact" in footer          → 'footer>ul>a'      (different region → different row)
"Add to cart" in a form      → 'form>div>button'
"Read more" across 50 posts  → 'article>div>a'    (same template path → ONE row)
```

**Why classes are excluded (deliberate):** classes are the volatile part —
themes/builders rewrite them, frameworks share them. Structural tags carry
*semantic* meaning and change only on real restructuring. So a theme reskin does
NOT fragment identity; only genuine structural change does — and per the design
intent, "if the structure changed, it's fair to treat it as different."

**Consequences (accepted):**
- Same text in different regions (header vs. footer) → two rows, two
  translations. Intended: they are genuinely different chrome.
- Same template element repeated across pages → one row (same selector path).
  Dedup preserved.
- **Residual fragility:** a major theme restructure that changes the landmark or
  intermediate tag (e.g. wrapping nav in a new `section`) shifts `reference_hash`
  → that translation orphans and re-discovers. Rarer than class churn; accepted
  cost of selector-in-identity. The HTML-fallback collector's candidates still
  pass the ownership gate (§5) first, so this only affects genuine orphan chrome.

"Where it appeared" (URL, last-seen) is still observation in `_i18n_meta` — the
selector in `reference` is *identity* (which slot), the meta is *observation*
(which page, when).

### Observation metadata — separate table, NOT identity

"Where it appeared" is observation, not identity, so it never fragments dedup.

```sql
CREATE TABLE {$i18n_meta} (
    i18n_id       bigint(20) unsigned NOT NULL,   -- PK + FK, 1:1 with the i18n row
    source_url    text NULL,                       -- last frontend URL (overwritten)
    selector      text NULL,                       -- last stable DOM path (overwritten)
    request_area  varchar(20) NULL,                -- frontend|ajax_frontend|rest
    seen_count    bigint unsigned NOT NULL DEFAULT 0,
    first_seen_at datetime NOT NULL,
    last_seen_at  datetime NOT NULL,
    PRIMARY KEY (i18n_id),
    CONSTRAINT fk_i18n_meta
        FOREIGN KEY (i18n_id) REFERENCES {$i18n} (id) ON DELETE CASCADE
) ENGINE=InnoDB {$charsetCollate};
```
- **1:1** with the i18n row (FK *is* the PK) → no orphan/duplicate meta.
- All fields **overwritten** on each sighting (latest wins); `first_seen_at` set
  once; `seen_count` increments (rough "common chrome" signal — soft, not
  analytics).
- Separate table keeps the **hot** `_i18n` lean: render preload reads `_i18n`
  only; admin "View" joins `_i18n_meta` (cold path).
- Written in the **background/shutdown** phase, never on the render hot path.
- This is the one legitimate `last_seen_at` — i18n has no FK-parent lifecycle,
  so a recency signal is genuinely useful here (unlike the owned tables, where
  cascade handles lifecycle).

---

## 7. Render-time page process (the hot path)

```
1. Detect request area; bail if not frontend.
2. Start gettext collection (memory).
3. Start output buffering.
4. WordPress renders normally.
5. Parse final HTML.
6. Build page context: current post ID (if singular), attached
   term_taxonomy_ids, current URL, request area.
7. PRELOAD renderable maps (one bulk query each — never per string):
     getPostTranslations(lang, postId)
     getPostmetaTranslations(lang, postId)
     getTermTranslations(lang, termTaxonomyIds)
     getI18nTranslations(lang, activeDomains/sources)
   (only renderable statuses: machine/reviewed/needs_review)
8. Replace matching segments from the in-memory maps (array lookups).
9. Collect UNKNOWN visible segments as fallback candidates (memory).
10. Shutdown: enqueue gettext batch + fallback candidates for background
    bulk processing (ownership-resolved before any i18n insert, §5).
```

**Replacement priority** when a segment matches:
```
1. current-page owned: post fields → postmeta → visible terms
2. i18n gettext known for this frontend source
3. i18n rendered_html fallback
```
If the same text exists in multiple owned sources with different translations,
HTML alone can't prove the owner → prefer current-page context, and do NOT
create a new fallback i18n row.

**The hot path NEVER:** queries per string, writes to the DB, or inserts i18n
inline. Discovery/observation writes happen after the response, in the
background processor.

---

## 8. Why no parent/context table

Considered and rejected (again): a parent table hashing all contexts, joined to
the four tables, to centralize dedup.
- Within-table dedup is already handled by each table's `uniq_identity`.
- Cross-table ownership is a *query* (`OwnedStringIndex`, cached), not a
  structural constraint — no join on render.
- Duplication is per-*identity*, not per-text, so a text-hash parent would
  wrongly merge a post title "News" with a tag "News" — exactly what the
  per-table identity already keeps apart.
- It reintroduces the join + two-write cost of the rejected `originals` table.

The unique keys + `OwnedStringIndex` + text-only fallback identity solve the
duplication problem without it.

---

## 9. Build order (this pipeline)

```
1. Stop using post_id = 0 for no-owner strings (chrome → i18n, not _post).
2. OwnedStringIndex (query-based, per-request cache).
3. Rendered HTML discovery → produces fallback CANDIDATES (not direct inserts).
4. Ownership resolution gate before any i18n insert.
5. Frontend gettext collector (memory-only) + request-area guard.
6. Shutdown queue + background bulk processor.
7. _i18n_meta table + observation upsert (background).
8. Lifecycle hooks: terms, posts, postmeta, plugins/themes (+ save_post guard).
```

---

## 10. Open questions (carried)

1. Which postmeta keys are frontend-translatable by default? (allow-list needed)
2. `OwnedStringIndex`: existing tables only, or dedicated index table if slow?
3. How to mark stale gettext when a plugin/theme is removed?
4. REST capture: default on, or only known frontend-UI routes?