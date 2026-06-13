# TransLangs — Data Pipeline v4 (INSERT & GET)

Companion to `translangs_architecture_v4.md`. This document is the full
circuit: how text enters the database (discovery + translation) and how it
comes back out on a page (render + admin). It replaces the pre-v4
parent/child pipeline; §7 lists exactly what changed and what was kept.

---

## 0. The one function everything depends on

```txt
TL_Text::normalize($raw) → normalized string
TL_Key::make($type, $owner_id, $ref1, $ref2, $text_hash) → binary(32)
```

Discovery, gettext capture, the HTML parser, the render hooks, and the admin
editor MUST all hash through the same normalizer and build keys through the
same chokepoint. One divergent code path = silently failed matches forever.
These two functions are written first and unit-tested hardest (architecture
§13, step 1).

Normalization order (frozen): line endings → entity decode → trim →
collapse internal whitespace → UTF-8.

---

## 1. Exact WordPress sources (what is read, from where)

| # | Content | WP storage | type | owner_id | ref1 | ref2 |
|---|---|---|---|---|---|---|
| 1 | Post / page / CPT title | `wp_posts.post_title` | 1 | post_id | `post_title` | — |
| 2 | Post body | `wp_posts.post_content` | 1 | post_id | `post_content` | — |
| 3 | Excerpt | `wp_posts.post_excerpt` | 1 | post_id | `post_excerpt` | — |
| 4 | Media title | attachment `post_title` | 1 | attachment_id | `post_title` | — |
| 5 | Media caption | attachment `post_excerpt` | 1 | attachment_id | `post_excerpt` | — |
| 6 | Media description | attachment `post_content` | 1 | attachment_id | `post_content` | — |
| 7 | Media ALT text | meta `_wp_attachment_image_alt` | 2 | attachment_id | the meta key | — |
| 8 | Allow-listed post meta (SEO titles, ACF text fields, …) | `wp_postmeta` | 2 | post_id | meta_key | path inside serialized/JSON value, else NULL |
| 9 | Term name | `wp_terms.name` | 3 | term_taxonomy_id | `name` | — |
| 10 | Term description | `wp_term_taxonomy.description` | 3 | term_taxonomy_id | `description` | — |
| 11 | Theme/plugin/core strings | `__()`, `_e()`, `_x()`, `_n()` calls | 4 | 0 | text domain | gettext context (`_x`) or NULL |
| 12 | Hardcoded template text, builder output that bypasses filters | final HTML only | 5 | 0 | structural selector (≤100) | — |

Allow-list rules (D25): post meta and taxonomies are **opt-in**
(`translangs_translatable_meta_keys`, `translangs_translatable_taxonomies`
filters + admin UI). Media ALT (`_wp_attachment_image_alt`) is allow-listed
by default. Attachments are posts: rows 4–6 ride the type-1 machinery
unchanged. Term meta: not in v1 (needs term_id→tt_id resolution; v1.1).

---

## 2. INSERT — how text enters the database

Four paths. Paths A–C write **source rows only** (never translations; adding
a language writes zero rows). Path D is the only thing that writes
translation rows.

### Path A — Save-time structured discovery (types 1–3, the primary path)

```txt
TRIGGER (admin-side, synchronous — latency acceptable here):
  wp_after_insert_post   → re-scan the post: fields (rows 1-6) + all
                           allow-listed meta (rows 7-8). Fires AFTER post,
                           meta and terms are persisted; covers attachments.
  added_post_meta /
  updated_post_meta      → single-key re-scan for meta written outside a
                           post save (importers, frontend forms, cron)
  created_term /
  edited_term            → rows 9-10 (both hooks pass $tt_id directly)

GUARDS: skip revisions, autosaves, auto-drafts, non-allow-listed post
types/taxonomies/meta keys. Re-entrancy guard around all writes
(remove_action / add_action) — discovery must never trigger itself.

PER ENTITY (the UC-I2 diff — NON-DESTRUCTIVE, D26/D27):
  1. read current values from the rows above
  2. normalize → current_hash; compute source_key from LOCATION
     (type + owner_id + ref1/ref2) — NOT from text
  3. for each current string:
       - location key exists in wp_tl_source?
           YES → text changed (current_hash differs)?
                   → UPDATE original_text + current_hash IN PLACE.
                     Translations stay attached; those whose
                     translated_against_hash now differs render as
                     needs_review with a before/after diff (§4 staleness).
                     NEVER delete, never re-fan-out.
           NO  → INSERT new source row (status=0, current_hash)
  4. DELETE only source rows for this entity whose LOCATION no longer
     exists (field removed, meta key gone, repeater item deleted):
       WHERE post_id = X / term_taxonomy_id = X
         AND source_key NOT IN (current location set)
     → FK cascade removes their translations (genuinely gone content)

  Capitalizing one character in a title now hits the YES branch: same
  location key, text+hash updated in place, translation preserved and
  flagged — one approve click, not a re-translation.
```

### Path B — Initial scan + lazy render backfill (types 1–3 catch-up)

Save hooks only cover content touched after activation. Two complements:

```txt
B1. ACTIVATION SCAN (recommended primary):
    Action Scheduler job walks all published posts / terms in batches of
    200, running the same Path-A re-scan logic per entity. Idempotent
    (uniq_key). Site is fully discovered within minutes of install.

B2. LAZY RENDER BACKFILL (safety net, throttled):
    Render hooks already compute every key (§3). Keys not found in a
    "known-sources" object-cache set become candidates → deferred batch
    (Path C mechanics) → INSERT IGNORE → key added to known set.
    Throttle: known-set membership check is in-memory/object cache;
    a key is attempted at most once per day. Without the throttle this
    would attempt every key on every pageview.
```

### Path C — Render-time discovery (types 4–5; no save event exists)

```txt
HOOK CALLBACKS NEVER TOUCH THE DATABASE. All collection is in-memory,
de-duplicated within the request.

type 4 (gettext):
  gettext / gettext_with_context / ngettext(_with_context) fire
  → collect (domain, context, text) per unknown string
  (ngettext: singular and plural are TWO independent strings)

type 5 (html), only when the parse runs (per-URL skip flag, §3 step 5):
  output-buffer parse (fail-open, §3 step 6)
  → prune excluded subtrees (data-tl-skip, translate="no", notranslate;
    never descend script/style/code/pre/textarea/iframe/…)
  → REQUEST-LOCAL OWNERSHIP CHECK: text hash claimed by ANY structured
    hook this request? → owned → drop. (Replaces the old two-tier gate —
    zero queries, correct on cold sites. D20)
  → content filters: must contain letters; not date/time/currency/URL/
    email/phone; minimum letter ratio
  → CIRCUIT BREAKER: max N new type-5 candidates per request
  → survivor = candidate with structural selector '{landmark}>{…}>{tag}'

FLUSH (D24):
  shutdown hook, AFTER the response is sent (fastcgi_finish_request()
  where available) → ONE batched
  INSERT … ON DUPLICATE KEY UPDATE updated_at = NOW()   -- last-seen
  (throttled: a key's last-seen is touched at most once per day,
   checked via object cache before including it in the batch)
  Fallback when post-response execution is unavailable: write the buffer
  to a transient and enqueue ONE Action Scheduler job to do the insert.

CONTEXT GUARDS: render-time discovery runs only for genuine frontend
renders — never is_admin(), REST (unless explicitly frontend-flagged),
AJAX, cron, CLI.
```

### Path D — Translation writes (the ONLY creator of translation rows)

```txt
Machine translation (bursts):
  Action Scheduler jobs, batched; INSERT … ON DUPLICATE KEY UPDATE
  (translated_text, status=1, provider_id, updated_at,
   translated_against_hash = source.current_hash)
  PROCESS IN source_key ORDER → near-sequential writes into the
  clustered PK (D11 mitigation)

Human editor (admin / marking mode):
  single-row upsert by (source_key, lang_id); status 2 on review,
  4 on flag; sets translated_against_hash = source.current_hash;
  "reset" = DELETE the row (string returns to untranslated)

Approve-after-edit (the staleness resolution, D27):
  source text was edited → translation is shown but flagged needs_review
  (current_hash != translated_against_hash). Translator clicks approve →
  translated_against_hash = source.current_hash, status → reviewed.
  One click. No re-translation. (Or they edit the translation, which is
  the same upsert.)

Ignore action (on the SOURCE):
  UPDATE wp_tl_source SET status=3 + DELETE all its translation rows
  in the same operation (D1). Un-ignore: status=0; translations rebuild
  on demand.

Every write validates lang_id against the config option (no FK guards it)
and invalidates the affected cache scopes (§3 step 8 / full-page version).
```

---

## 3. GET — frontend render (the hot path)

No full-page caching anywhere in this pipeline (deliberate — see §7).
Caching here is DATA-level only: `key → translated_text` maps. These are
safe under dynamic content by construction: keys are computed from whatever
actually rendered THIS request (a random product loop fires `the_posts`
with this request's products; their keys are batched and resolved fresh
every time). Nothing about page structure is ever stored.

```txt
1. BOOTSTRAP (early, e.g. setup_theme):
   detect request area; parse target language from URL prefix.
   Not frontend, or source language → register Path-A save hooks only
   (admin requests), and stop here. No render machinery loads.

2. ARM THE PIPELINE:
   register resolution hooks (architecture §5.5) + start gettext
   collection + start output buffer (template_redirect).

3. PRELOAD MAPS — batched, never per string (architecture §7):
   - the_posts fires per WP_Query (main loop, menus, widgets, random/
     dynamic loops) → compute keys for that loop's ACTUAL posts:
     fields + allow-listed meta + attached terms → chunked
     IN-queries (≤200 keys) on wp_tl_translation → $map[key] = text.
     NEGATIVE CACHING: misses stored as misses.
   - gettext set: tier 1 whole-language preload (≤ ~8k strings) /
     tier 2 lazy batches above that.
   - html set: per-URL map  tl:html:{lang}:{url_hash}
     (caches WHICH translations exist for this URL's hardcoded strings —
      data, not page structure; safe under dynamic content).
   All maps live in the object cache; invalidated by translation writes
   on their scope. With no persistent object cache they are rebuilt per
   request from the same indexed queries — still a handful of small
   round trips.

4. REPLACE VIA HOOKS (identity resolution BY LOCATION, D26):
   each hook: identify location (post_id+field / meta_key / tt_id+field /
   domain[+context]) → TL_Key::make from that location → look up the
   preloaded source row (gives current_hash) + translation map.
     translation present?
       current_hash == translated_against_hash → show translation
       differs → show translation, mark needs_review (default;
                 option: show original) — staleness, §4
     absent → original (untranslated / ignored)
   Type 4 plain gettext (no location) still keys by text tail.
   Side products: $ownershipSet[text_hash] (type-5 gate),
   unknown-location candidates (Path B2 backfill).

5. OUTPUT BUFFER — type-5 pass (fail-open), WITH PARSE-SKIP FLAG:
   The DOM parse is the one heavy per-request step, so it is gated by a
   per-URL decision flag (a yes/no about WORK, never content — immune to
   the dynamic-page problem):
     SKIP the parse when (a) the per-URL type-5 map is empty AND
     (b) type-5 discovery for this URL ran within the last 24h.
   Otherwise: parse final HTML: WP_HTML_Processor → Masterminds fallback →
   BOTH fail → return buffer unchanged, log, discover nothing.
   Per surviving text node: normalize → hash →
   key = TL_Key::make(5, 0, selector, null, hash) → replace from per-URL
   map if present; unowned unknowns → Path C candidates; record the
   discovery timestamp for the skip flag.

6. SHUTDOWN (post-response): flush discovery batches (Paths B2 + C).
```

Properties: zero joins, zero per-string queries, zero inline writes;
every render is computed fresh (dynamic loops, personalization, and A/B
content always show the correct translation for what actually rendered);
per-page cost is O(page content), not O(catalog). The heavy step (DOM
parse) is paid only while it earns something.

If the site runs a full-page cache (WP Rocket, host-level, CDN), it sits
ABOVE this plugin: it caches our already-translated output, and the site
owner owns that staleness tradeoff — including for dynamic loops. The
plugin neither provides nor depends on one. (Compatibility note: such
caches must vary by language URL — true automatically with prefix-based
URLs.)

### Admin marking mode (translator session)

Same pipeline with one addition at display-level hooks only (`the_title`,
`the_content`, `term_name`, `term_description`, allow-listed gettext):
wrap output in a span carrying `data-tl-key` (hex source_key) so the editor
writes back by identity (Path D human) — no text matching, no lookup.
Marking mode forces the type-5 parse (translators must see html strings
too), ignoring the skip flag.

---

## 4. GET — admin (cold path, joins allowed)

```txt
WORK QUEUE (UC-A1/A2): wp_tl_source LEFT JOIN wp_tl_translation
  ON source_key + lang; WHERE s.status = 0; keyset pagination on
  idx_admin_queue (status, updated_at) — never OFFSET.
  child NULL → "needs translation"; child present → its status;
  s.status = 3 → excluded (ignored).

PER POST/TERM: entry by idx_post / idx_tt → all strings + translations.
PER STRING (UC-A4): PK prefix scan — all languages of one source_key.
SEARCH (UC-A3): LIKE on original_text; FULLTEXT only on measured need.
Rows are addressed by source_key everywhere (one table — the old
RowRef(table,id) indirection is gone).
```

---

## 5. Lifecycle summary (where rows die)

| Event | Mechanism |
|---|---|
| Post/attachment deleted | FK: wp_posts → source → translations (atomic) |
| Term deleted | FK: wp_term_taxonomy → source → translations |
| Content edited (text changed) | Path A: UPDATE original_text + current_hash in place; translations kept, flagged needs_review (D27) — NOT deleted |
| Content location removed (field/meta/item gone) | Path A diff: that location's source row deleted → cascade clears its translations |
| String ignored | source status 3 + its translation rows deleted (D1) |
| Translation reset | DELETE one (source_key, lang_id) row |
| Language hard-deleted | batched keyset job BEFORE config removal (arch §8.1) |
| Plugin/theme removed | type-4 cursor cleanup by ref1 = domain (arch §8.2) |
| Stale html rows | weekly age-out: type 5, last-seen > 90 days (arch §8.2) |

---

## 6. End-to-end picture

```txt
                         ┌────────────── INSERT ──────────────┐
 wp_posts fields ──┐
 attachments ──────┤  Path A (save hooks, sync, diff)         │
 allow-listed meta ┤  Path B (activation scan + lazy backfill)│──▶ wp_tl_source
 terms ────────────┘                                          │      │ FK
 gettext calls ───────  Path C (in-memory → shutdown batch) ──┘      │ cascade
 html residue ────────  Path C (cache-miss parse, 3 gates)           ▼
 providers/editor ────  Path D ────────────────────────────▶ wp_tl_translation
                                                                     │
                         ┌─────────────── GET ────────────────┐      │
 visitor ──▶ hooks (computed keys, batched IN, identity) ◀───────────┘
                  │
                  ▼
             buffer (type-5 pass, fail-open, per-URL skip flag)
                  │
                  ▼  every render computed fresh — dynamic loops safe
             response → shutdown: flush discovery batches
 translator ─▶ marking mode (data-tl-key wrappers, parse forced)
 admin ──────▶ keyset work queue (the one LEFT JOIN in the system)
```

---

## 7. Diff vs the pre-v4 pipeline (what changed, what was kept)

**Changed:**
- Render is hook-first by identity; HTML parsing demoted from primary
  replacement engine to type-5 fallback for genuine residue.
- One source + one translation table; keys computed, never looked up;
  children no longer carry hash copies; RowRef(table,id) → source_key.
- Old Tier-2 ownership gate (DB hash query in the worker) is GONE —
  replaced by the request-local ownership set (D20), zero queries.
  Behavior change, for the better: text hardcoded in a template that
  happens to equal some other page's structured string now becomes a
  type-5 row and IS translatable (the old gate dropped it forever).
- "Match priority owned → i18n → html" is no longer a rule — execution
  order (hooks before buffer) makes it automatic (D3).
- Discovery flush may write directly on shutdown AFTER the response
  (fastcgi_finish_request); transient + Action Scheduler remains the
  fallback, not the only path.
- FULL-PAGE CACHE REMOVED (was layer 0 in the old pipeline). Reason:
  page-level caching freezes page STRUCTURE — a cached page with a
  random/dynamic product loop serves the same products to everyone until
  invalidation, wrong independent of translation. Replaced by data-level
  caching only (translation maps), which is correct under dynamic content
  by construction: keys are computed from what actually rendered each
  request. The heavy step this exposes (per-render DOM parse) is gated by
  the per-URL parse-skip flag (§3 step 5) — a flag about work, not
  content. External full-page caches (WP Rocket, host, CDN) may sit above
  the plugin at the site owner's discretion; the plugin neither provides
  nor depends on one.

**Kept (these were right):**
- Hook callbacks never touch the DB; in-request de-duplication.
- Fail-open parsing (WP_HTML_Processor → Masterminds → serve original).
- save-event diff reconciliation with re-entrancy guards (now Path A).
- INSERT IGNORE idempotence; zero-row language addition; ignored on the
  source applying to all languages.
- One bulk query per map, never per string; the identical-normalizer
  rule — still the single hardest thing to get wrong (§0).
```