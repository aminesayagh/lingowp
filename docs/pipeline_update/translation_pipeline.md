# TransLangs — Translation Pipeline (Discovery → Hooks → Render)

> The end-to-end pipeline: how strings are first discovered, kept fresh on
> content changes, captured from gettext, and replaced at render — and the
> ownership rules that stop the same string being stored twice. Builds on the
> split translation-memory schema in `goal_data_migration.md`.

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

## 0.1 Current implementation boundary

This pipeline is an extension of the cleaned repository, not a rebuild of the
old architecture:

- Wire new services directly in `src/Bootstrap/Plugin.php`, alongside the
  current language registry, split repositories, admin controllers, and public
  routing classes.
- Do **not** recreate `ServiceContainer`, `CoreServiceProvider`,
  `PublicSiteServiceProvider`, `AdminServiceProvider`, `ContentSource`,
  `SourceString`, or the old `PageTranslation` layer.
- Keep feature classes only where they map to real behavior: structured
  discovery, public render pipeline, gettext capture, queue worker, and cache
  invalidation.
- Keep current admin mutation services (`SaveTranslation`,
  `PutTranslationForReview`, `ReviewTranslation`, `IgnoreTranslation`,
  `ResetIgnoredTranslation`) and the split repositories. Add cache invalidation
  around those mutations instead of replacing them.
- `SourceRoute` lives in `TransLangs\TranslationMemory\Domain`; documentation
  and new code should refer to that location.

---

## A. Initial discovery (structured scan)

Runs from an admin "Scan" action/button, and may be invoked after activation if
we choose to seed a fresh site. Reads content from where WordPress actually
stores it — never by rendering pages.

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
| Terms | term name/description | `_terms` | by `term_taxonomy_id` |
| Posts/Pages/CPT | post_title/content/excerpt, never post slug | `_post` | field name |
| Media (attachment) | title/caption/description | `_post` | field name |
| Media (attachment) | **alt** (`_wp_attachment_image_alt`) | `_postmeta` | meta_key, empty context |
| Post meta (named keys) | selected frontend meta, never slug keys | `_postmeta` | meta_key (+ context) |

Build structured discovery under `TransLangs\TranslationMemory`. Each reader
normalizes and hashes strings into `TranslationSourceIdentity` instances
directly, with the correct `SourceRoute` (`post`, `postmeta`, `terms`, `i18n`).
Repository insertion then writes one `discovered` row per **active target
language** (never the source language), using `INSERT IGNORE` semantics for
idempotency.

**This is the bulk of the table.** Most translatable text is owned content and
is discovered here, not by parsing pages.

The admin Scan action should run the structured scan and report counts by route:
new rows, already-present rows, skipped empty/unsupported values, and failures.

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
  → collector validates request area (frontend/ajax_frontend/opt-in rest)
  → normalized strings held IN MEMORY only (de-duped in the request)
  → request finishes
  → shutdown: push the memory buffer to one batch transient + enqueue ONE job
              (NO database INSERTs here — see §C.1)
  → Action Scheduler worker: bulk INSERT IGNORE into _i18n (source='gettext'),
                             ownership-validated, off the request
```

### C.1 Why not write at `shutdown` (critical)

On managed hosts (WP Engine, Kinsta, Pantheon, VIP) `fastcgi_finish_request()`
fires when the response is flushed, and the PHP worker is often **killed
immediately after** — any heavy `INSERT` racing in `shutdown` is silently
truncated → **lost strings**. So `shutdown` does only the cheap part: dump the
in-memory buffer to a transient and schedule one async job. All DB writes happen
in a reliable background worker.

**Queue backend: Action Scheduler.** Battle-tested, ships with WooCommerce, and
designed for exactly this (reliable batched background processing that won't
time out a page load or get killed). Chosen over WP-Cron because WP-Cron is
request-triggered and stalls on low-traffic sites — unacceptable when the whole
point is not losing captured strings.

Load rules:
- If WooCommerce already provides Action Scheduler, use it.
- If WooCommerce is absent, bundle Action Scheduler and include its loader when
  the plugin file is loaded, before `plugins_loaded` priority `0`. Its own docs
  require inclusion before that point so version arbitration can register the
  newest copy.
- Register worker callbacks with normal WordPress hooks:
  `add_action($hook, [$worker, 'handle'], 10, 1)`.
- Enqueue a single async worker per request with:
  `as_enqueue_async_action($hook, ['batch_id' => $batchId], 'translangs', true, $priority)`.
- Store the request capture payload in one transient named
  `translangs_capture_batch_{uuid}`. The queued job receives only that batch ID,
  loads the transient, processes it once, and deletes it.

Capture areas: `frontend`, `ajax_frontend`. REST is **default OFF** (Gutenberg
and SaaS integrations flood it with backend strings); capture a REST route only
when the frontend explicitly signals public intent via a custom header
`X-TransLangs-Context: frontend` on its AJAX/fetch calls.
Skip: `admin`, `ajax_admin`, `cli`, `cron`, `login`, and all un-flagged REST.

Gettext i18n rows keep a **real** identity: `source='gettext'`,
`reference`=textdomain/file, eventually `_x()` context. (Full domain/plural
shape is future work; minimal now.)

---

## 5. Ownership resolution — the anti-duplication gate (two-tier)

The rule that stops HTML parsing from duplicating owned text into `i18n`. It runs
in **two tiers** so the frontend never queries per string.

### Tier 1 — fast in-memory check (hot path, zero new queries)

The render path already preloads this page's owned translations into memory maps
(§7). Existing repository preload methods return `mapKey => translated_text`,
where each `TranslationMapKey` shape ends with the final
`original_hash_hex` token. Extract every owned `original_hash` from those keys
into one flat set:

```
$pageOwnedHashes = all original_hash keys from the post/postmeta/term preload maps
```

Add a tiny helper either on `TranslationMapKey` or inside the render pipeline,
for example `originalHashHexFromMapKey(string $key): ?string`, that returns the
final 64-character hash token only when the key shape is valid.

When the parser finds a candidate string:
```
hash = sha256(normalize(text))
if isset($pageOwnedHashes[hash])  → owned by THIS page's content → do NOT queue
else                              → maybe orphan → queue as fallback candidate
```
This is an in-memory `isset()` — no DB hit, no matter how many DOM nodes. A page
with 200 candidates costs 200 array lookups, not 600 queries.

> Tier 1 is a fast **negative filter**: "is this string owned by the current
> page's own entities?" It catches the common case (chrome text that is really
> the current post's title, a visible term, etc.). It does NOT claim to know
> about strings owned by *other* entities — that's Tier 2's job.

### Tier 2 — cross-table check (background worker; keep it simple in v1)

Queued candidates are validated by the background job **before** any i18n insert
— never on the request. The goal is **"don't duplicate obvious owned text into
i18n,"** not "prove mathematically perfect site-wide ownership." A
straightforward cross-table hash check is enough for v1; don't build elaborate
ownership inference.

```
for each queued candidate (in the async worker):
  → OwnedStringIndex.existsOwnedHash(hash)   // simple query across _post,_postmeta,_terms
  → if owned (ANY status): drop it (it belongs to another entity, not i18n)
  → else if a gettext capture matched this request/hash: insert as gettext i18n
  → else: insert as rendered_html fallback i18n (structural-selector identity, §6)
```

Moving the cross-table query here means table size never affects frontend page
load. **Ownership includes ALL statuses** — `discovered`, `machine`, `reviewed`,
`ignored`, `needs_review`. A *discovered-but-untranslated* post title is still
owned; it must never be duplicated into i18n. Refine the check later only if a
real duplication problem shows up — not preemptively.

### `OwnedStringIndex` service (Tier 2 only)

```php
interface OwnedStringIndex {
    public function existsOwnedHash(string $originalHashRaw): bool;
    public function existsOwnedHashForPage(
        string $originalHashRaw, int $postId, array $termTaxonomyIds
    ): bool;
}
```
- Query-based across the three owned tables; **existing tables only** (no
  dedicated index table — it would violate single-source-of-truth and create
  sync burden, and it isn't needed once the check is off the hot path).
- Runs in the **background worker**, so its query cost never touches render.
- Per-request cache still useful inside a single worker batch.

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
The parser reads this from the current tag path/breadcrumbs when available: find
the nearest landmark ancestor, take the immediate child tag on the path, then
the element's own tag. Falls back to fewer levels if no landmark/intermediate
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
- Written in the **Action Scheduler worker**, never in `shutdown` and never on
  the render hot path.
- This is the one legitimate `last_seen_at` — i18n has no FK-parent lifecycle,
  so a recency signal is genuinely useful here (unlike the owned tables, where
  cascade handles lifecycle).

---

## 7. Public render pipeline (the hot path)

**Cache first — parsing is the exception, not the rule.** Parsing the full HTML
on every request is real CPU cost even with zero per-string queries. A
translated-page cache makes the heavy pipeline run only once per
page-version-per-language; every later visitor gets near-static speed.

Add one public render pipeline class wired directly from `Plugin::boot()`. It
owns cache lookup, gettext capture start, output buffering, parser selection,
preload, replacement, fallback candidate collection, cache store, and queue
enqueue. Do not split those steps into generic providers or a page-translation
service layer.

```
1. Detect request area; bail if not frontend.
2. CACHE LOOKUP: key = (url, target_lang, content_version).
   → HIT  : return cached translated HTML. DONE. (no parse, no replace, no maps)
   → MISS : continue to the full pipeline below.
3. Start gettext collection (memory).
4. Start output buffering.
5. WordPress renders normally.
6. Parse final HTML (fail-open — see §7.1). Full parse on miss is fine; cache
   means a miss is rare.
7. Build page context: current post ID (if singular), attached
   term_taxonomy_ids, current URL, request area.
8. PRELOAD renderable maps (one bulk query each — never per string):
     getPostTranslations(lang, postId)
     getPostmetaTranslations(lang, postId)
     getTermTranslations(lang, termTaxonomyIds)
     getI18nTranslations(lang, activeDomains/sources)
   (only renderable statuses: machine/reviewed/needs_review)
9. Replace matching segments from the in-memory maps (array lookups).
10. Collect UNKNOWN visible segments as fallback candidates, filtered by the
    **Tier-1 in-memory ownership check** (§5): drop any candidate whose hash is
    in `$pageOwnedHashes`; keep only the misses.
11. STORE the translated HTML in the page cache (key from step 2), but only
    after parse and replacement succeeded.
12. Shutdown: push the gettext buffer + surviving candidates to a transient and
    **enqueue ONE Action Scheduler job** — NO DB writes here. The worker runs
    Tier-2 ownership validation and bulk-inserts genuine orphans + observation.
```

### 7.1 Parser failure = fail open (never break render)

Keep WordPress 6.0 support. Prefer WordPress' native HTML API only when the
required class and methods exist:

```
WP_HTML_Processor available + every method the pipeline uses exists
  → use WP_HTML_Processor
otherwise
  → use Masterminds\HTML5 fallback parser
```

Restore `masterminds/html5` as a Composer dependency for that fallback.
`WP_HTML_Processor` parses a subset of HTML5 and can abort on markup it can't
handle; Masterminds can parse and serialize full documents or fragments through
`loadHTML()`, `loadHTMLFragment()`, and `saveHTML()`. If **both** fail or abort
on a page:
```
→ return the ORIGINAL HTML unchanged   (the page must always render)
→ log a debug event
→ discover NOTHING from that request    (no candidates, no partial state)
→ do NOT write a cache entry for a failed parse (so a later fix can retry)
```
Parser failure must never break the frontend and must never produce partial or
corrupt discovery.

### 7.2 Cache invalidation

Cache key:

```
translangs_page:{target_lang}:{content_version}:{sha256(canonical_url)}
```

Invalidate the page's cache entry (and bump `content_version`) when:
- the post/page/product saves (`save_post`),
- a translation affecting that page is saved, put for review, reviewed, ignored,
  or reset from ignored in admin,
- the enabled-language set changes,
- the theme changes (template chrome may differ).
Keep invalidation **targeted** (per page/per language) where possible; fall back
to a language-wide flush only for global changes (language set, theme).

**Replacement priority** when a segment matches:
```
1. current-page owned: post fields → postmeta → visible terms
2. i18n gettext known for this frontend source
3. i18n rendered_html fallback
```
If the same text exists in multiple owned sources with different translations,
HTML alone can't prove the owner → prefer current-page context, and do NOT
create a new fallback i18n row.

**The hot path NEVER:** queries per string (Tier-1 is in-memory), writes to the
translation tables, or inserts i18n inline. All discovery/observation writes
happen in the Action Scheduler worker, after the response is sent — safe from
worker kill. (The only write on the hot path is the page-cache store in step 10,
which is a cache write, not a translation-memory write.)

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

The unique keys + `OwnedStringIndex` (two-tier) + structural-selector fallback
identity solve the duplication problem without it.

---

## 9. Build order (this pipeline)

```
1. Keep all new services wired directly from Plugin::boot(); do not recreate
   containers/providers or the old page translation layer.
2. Stop using post_id = 0 for no-owner strings (chrome → i18n, not _post).
3. Render preload already builds owned-hash maps → expose $pageOwnedHashes (Tier-1)
   by extracting the final original_hash_hex token from TranslationMapKey keys.
4. Parser with fail-open fallback (WP_HTML_Processor when class/methods exist
   → Masterminds → original HTML
   unchanged); parser failure never breaks render, discovers nothing.
5. Rendered HTML discovery → produces fallback CANDIDATES (Tier-1 filtered).
6. Full-page translated cache (lookup first, store on miss, targeted invalidation)
   — makes parsing the exception, not the rule.
7. Action Scheduler integration (bundle if WooCommerce absent before
   plugins_loaded priority 0): shutdown buffers to one
   translangs_capture_batch_{uuid} transient + enqueues one unique job with
   as_enqueue_async_action(..., 'translangs', true, $priority); worker callbacks
   are normal add_action() callbacks and do all DB writes.
8. OwnedStringIndex (Tier-2, runs in the worker) → simple cross-table gate.
9. Frontend gettext collector (memory-only) + request-area guard (REST off by default).
10. _i18n_meta table + observation upsert (in the worker). The schema base now
    creates _i18n_meta with TableDefinitions::i18nMeta(),
    TranslationMemorySchema::tlI18nMetaTable(), fk_i18n_meta verification,
    reverse drop order, and schema version 6; the remaining work is the worker
    observation upsert.
11. Lifecycle hooks: terms, posts, postmeta, plugins/themes (+ save_post guard);
    deleted_plugin / switch_theme → enqueue stale-gettext cleanup;
    save_post / translation-edit / language-change → invalidate page cache.
12. Structured discovery admin Scan action/button with per-route counts.
```

---

## 10. Resolved questions (were open)

1. **Translatable postmeta keys — strict allow-list + filter.** Default set:
   `_wp_attachment_image_alt`, named SEO keys (`_yoast_wpseo_title`,
   `_yoast_wpseo_metadesc`, …), standard WooCommerce meta. Everything else
   (ACF, Elementor, custom) opts in via
   `apply_filters('translangs_translatable_meta_keys', [...])`. **Never
   auto-scan all meta** — serialized blobs/JSON would flood the tables.
2. **OwnedStringIndex — existing tables only.** With Tier-2 validation in the
   background worker, cross-table query speed never touches the frontend, so no
   dedicated index table (which would break single-source-of-truth and add sync
   burden).
3. **Stale gettext on removal — hook `deleted_plugin` / `switch_theme`**, enqueue
   a worker job: `DELETE FROM _i18n WHERE source='gettext' AND reference_hash …`;
   `_i18n_meta` cleans up via its CASCADE.
4. **REST capture — default OFF.** Capture a REST route only when the frontend
   sends `X-TransLangs-Context: frontend`; otherwise Gutenberg/SaaS backend
   strings would flood i18n.

---

## 11. Dependency: Action Scheduler

Reliable background batches require a real queue, not `shutdown` or WP-Cron.
**Action Scheduler** is the chosen backend (ships with WooCommerce; **bundle**
it when WooCommerce is absent, using its version-arbitration loader before
`plugins_loaded` priority `0`). All DB-writing steps (gettext flush, fallback
insert, observation upsert, stale cleanup) run as Action Scheduler jobs.
`shutdown` only writes the per-request transient and enqueues one unique async
action in the `translangs` group.
