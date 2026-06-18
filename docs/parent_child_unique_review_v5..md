# TransLangs — v5 Implementation Plan (Claude Code hand-off)

> The authoritative build spec. Consolidates every locked decision: hybrid
> child-key model, content fragmentation, source-change mechanic with approximate
> carry-forward, lifecycle hooks, migration/cutover, and the cross-cutting rules
> (normalization, multisite, FKs). Where older docs differ, **this doc wins.**

---

## 0. The model in one paragraph

Each source string is stored as a **parent position** (where it appears) plus
**child translations**. Two child-key strategies by type:
- **hash-link** `(original_hash, target_lang)` for **post, terms, html** —
  same text → same translation (translation reused across positions).
- **parent_id** `(parent_id, target_lang)` for **i18n, postmeta** — context can
  make identical text translate differently (`_x('Post','noun'|'verb')`, ACF
  field labels), so the translation is owned by the specific position.

`post_content` is **fragmented** into per-paragraph/block parent rows, so an edit
re-hashes only the changed fragment. Render is fronted by a full-page cache;
on miss, translations are preloaded into memory and applied by the output-buffer
parser. All heavy writes go through Action Scheduler, never `shutdown`.

---

## 1. Tables (10 + languages)

### 1.1 Languages
```sql
CREATE TABLE {$languages} (
    code   varchar(20) NOT NULL,        -- TARGET languages only; varchar(20) for de_DE_formal etc.
    label  varchar(100) NOT NULL,
    status tinyint(3) unsigned NOT NULL DEFAULT 1,   -- 1 active | 0 disabled (soft)
    PRIMARY KEY (code)
) ENGINE=InnoDB {$charsetCollate};
```
- **Source language is NOT here.** It's a stable plugin option `source_locale`,
  seeded once from `get_locale()` at setup (never read `get_locale()` at runtime —
  it's filtered and can switch mid-request).

### 1.2 Parent shape (all five)
Common columns: `id` (PK), the type identity columns, `original_hash binary(32)`,
`original_text longtext`, `status tinyint` (0 discovered | 3 ignored),
`hash_algorithm_version tinyint` (see §6), timestamps. Plus per-type identity:

| Table | Identity (UNIQUE uniq_source) | Content FK |
|---|---|---|
| `wp_tl_post`     | `(post_id, field, original_hash)` | → `wp_posts.ID` CASCADE |
| `wp_tl_postmeta` | `(post_id, meta_key_hash, context_hash, original_hash)` + nullable `meta_id` | → `wp_posts.ID` CASCADE |
| `wp_tl_terms`    | `(term_taxonomy_id, field, original_hash)` | → `wp_term_taxonomy` CASCADE |
| `wp_tl_i18n`     | `(text_domain, context_hash, original_hash)` | none |
| `wp_tl_html`     | `(selector_hash, original_hash)` | none |

All parents also get `KEY idx_hash (original_hash)` (the join/dedup link, NON-unique)
and `KEY idx_queue (status, id)`.

- `field varchar(32)`; `post_content` rows are **fragments** (one per
  paragraph/block), not the whole post — so editing one paragraph re-hashes only
  that fragment. Identical fragments within one post dedup to one parent
  (acceptable: identical text → identical translation).
- `selector_hash binary(32)` = sha256(full selector); `selector text` kept
  readable. (Not a prefix index.)
- `meta_key_hash`, `context_hash`, `selector_hash`, `reference`/`sample_reference`
  hashed to avoid the 767-byte index limit.
- i18n v1 = **singular only** (`gettext`, `gettext_with_context`); `_n()`/`_nx()`
  passed through; `@wordpress/i18n` JS out of scope. `sample_reference` is an
  observation, not identity.

### 1.3 Child shape — TWO variants

**Hash-link child (post, terms, html):**
```sql
CREATE TABLE {$post_tr} (
    id              bigint unsigned NOT NULL AUTO_INCREMENT,
    original_hash   binary(32)  NOT NULL,
    target_lang     varchar(20) NOT NULL,
    translated_text longtext    NOT NULL,
    status          tinyint(3) unsigned NOT NULL DEFAULT 1,  -- 1 machine|2 reviewed|4 needs_review
    provider        varchar(50) NULL,
    created_at      datetime NOT NULL,
    updated_at      datetime NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_translation (original_hash, target_lang),  -- one translation per text per lang
    KEY idx_queue (target_lang, status, id),
    CONSTRAINT {$fk_name_lang} FOREIGN KEY (target_lang)
        REFERENCES {$languages}(code) ON DELETE CASCADE
    -- NO parent_id, NO FK to parent. Link is original_hash (many positions → one row).
) ENGINE=InnoDB {$charsetCollate};
```

**parent_id child (i18n, postmeta):**
```sql
CREATE TABLE {$i18n_tr} (
    id              bigint unsigned NOT NULL AUTO_INCREMENT,
    parent_id       bigint unsigned NOT NULL,
    target_lang     varchar(20) NOT NULL,
    translated_text longtext    NOT NULL,
    status          tinyint(3) unsigned NOT NULL DEFAULT 1,
    provider        varchar(50) NULL,
    created_at      datetime NOT NULL,
    updated_at      datetime NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_translation (parent_id, target_lang),     -- one translation per POSITION per lang
    KEY idx_queue (target_lang, status, id),
    CONSTRAINT {$fk_name_parent} FOREIGN KEY (parent_id)
        REFERENCES {$i18n}(id) ON DELETE CASCADE,
    CONSTRAINT {$fk_name_lang} FOREIGN KEY (target_lang)
        REFERENCES {$languages}(code) ON DELETE CASCADE
) ENGINE=InnoDB {$charsetCollate};
```

> **The repository layer MUST be per-type.** Hash-keyed access for post/terms/html;
> parent_id-keyed for i18n/postmeta. Do not build one uniform child repository —
> it will break on the parent_id tables. `idx_queue (target_lang, status, id)`
> on every child for keyset pagination.

---

## 2. Render (the hot path)

```
1. Full-page cache lookup (key: url + target_lang + content_version). Hit → done.
2. Miss → preload translation maps into memory (batched, see below), then run the
   output-buffer parser; replace matched strings; enqueue HTML fallback candidates.
```

**Preload (no per-string queries):**
- **hash-link tables (post/terms/html):** one `IN (hashes)` query per type:
  `SELECT original_hash, translated_text FROM {child} WHERE target_lang=? AND original_hash IN (...)`.
  Build a `hash → translated_text` map. Correct because `UNIQUE(original_hash,
  target_lang)` guarantees one row per hash.
- **parent_id tables (i18n/postmeta):** resolve the parent identity first, then
  `SELECT parent_id, translated_text ... WHERE target_lang=? AND parent_id IN (...)`.
  Build a `parent_id → translated_text` map.
- **terms:** hook gives an id, not text → read the parent to get `original_hash`,
  then use the hash map. (hash-link storage, hash obtained via parent.)

**Apply:** for each discovered string on the page, look up its map entry; replace
if present, else leave original. `ignored` never has a child (§3), so absence =
render original (covers both "untranslated" and "ignored"). Never one query per
gettext call (the documented gettext perf trap).

---

## 3. Lifecycle & flows

### Discovery / set source text
Write the **parent only** (`INSERT IGNORE` on `uniq_source`), status `discovered`,
no children. `post_content` is split into fragments first; each fragment is its
own parent row.

### Translate into language L
- hash-link: `INSERT IGNORE` child `(original_hash, L)`.
- parent_id: `INSERT IGNORE` child `(parent_id, L)`.

### Source text change (the carry-forward mechanic)
On re-discovery of a position whose text changed H1 → H2:
1. **Delete the stale position** row (do NOT mutate `original_hash` in place —
   mutating an identity column risks unique-key collisions).
2. **Insert the new position** `(…, H2)`, status `discovered`.
3. **Approximate carry-forward (hash-link tables):** if `(H2, L)` has no child,
   seed it from `(H1, L)`'s child text with **status `needs_review`** (so the
   admin confirms or re-translates — never silently asserted as correct). If
   `(H2, L)` already exists, leave it.
4. **parent_id tables:** the position owns its child; on text change, set that
   child's status to `needs_review` (the old translation is likely still close;
   human confirms).
5. **Old `(H1)` child** (hash-link): left for the **orphan sweep** — deleted only
   when no position references H1 (some other position may still use "old text").

> Carry-forward is a **suggestion flagged for review**, not an authoritative copy.
> This is what makes it safe: a real text change (e.g. "Home"→"Welcome") surfaces
> the stale translation for correction; a trivial edit (added comma) lets the
> human approve in one click instead of re-translating a paragraph.

### Ignore ("never translate, leave original")
Set `parent.status = ignored` AND delete the child(ren) for that text/position
across languages. Render's "no child = original" then covers it. (Note: on
hash-link tables ignore is effectively text-global within the table — if any
position of a text must stay translated, don't ignore the text.)

### Add a language
**Zero rows.** Children appear as translations are made.

### Disable a language (soft)
`languages.status = 0`; children remain; pipeline skips inactive langs.

### Delete a language (hard)
Delete the `languages` row → child→languages FK CASCADE removes that language's
children across all tables.

### Delete source content
Content-FK CASCADE removes parent positions (post/term deletion). Children:
- parent_id tables → CASCADE via `parent_id`.
- hash-link tables → NOT cascaded (shared); reclaimed by the **orphan sweep**.

### Orphan sweep (hash-link child cleanup, background)
```sql
DELETE tr FROM {child} tr
LEFT JOIN {parent} p ON p.original_hash = tr.original_hash
WHERE p.id IS NULL;     -- no position uses this text anymore
```
Per type, batched, via Action Scheduler.

---

## 4. WordPress hooks

**Posts:** `save_post` (re-discover fields incl. fragmenting `post_content`),
`before_delete_post`. Re-entrancy guard on save.

**Post meta (allow-listed keys only):** `added_post_meta`, `updated_post_meta`,
`deleted_post_meta`. Store `meta_id` for repeated-key disambiguation; `context_hash`
distinguishes repeated same-key values (array position is unstable).

**Terms:** `created_term`, `edited_term` (rediscover — not just deletion),
`delete_term`.

**gettext (frontend page loads only — see gettext_capture_plan.md):** the four
filters gated by `tl_should_capture_gettext()`; memory-buffer → Action Scheduler
flush; v1 singular only.

**Translatable meta keys:** allow-list + `translangs_translatable_meta_keys`
filter; never auto-scan all meta.

---

## 5. Async & ownership

- **All DB writes via Action Scheduler** (bundle the library if WooCommerce
  absent). `shutdown` only buffers to a transient + enqueues — never writes
  (managed hosts kill shutdown workers → silent loss).
- **Two-tier ownership gate** before any i18n insert: Tier 1 in-memory
  `$pageOwnedHashes` (zero queries, from the preload maps); Tier 2 cross-table
  `OwnedStringIndex` check in the background worker (existing tables only).
- **HTML fallback** (`wp_tl_html`) is lowest-confidence: structural selector
  identity, captured only when not already owned by a structured source.

---

## 6. Normalization = relational integrity (must-freeze)

Because `original_hash` links rows and keys children, the normalization algorithm
**defines row relationships** — changing it later silently disconnects everything.

- **Freeze and version it:** store `hash_algorithm_version` on every parent;
  bump on any normalization change and provide a re-hash migration.
- **One conservative normalizer**, shared by discovery, the parser, gettext
  capture, and render — identical bytes in, identical hash out, everywhere. If a
  new normalizer is written instead of reusing the shared one, matches silently
  fail.
- Documented rules: line-ending policy, Unicode normalization form, entity
  handling, whitespace policy — pick once, write them down, don't drift.
- (Optional later: split `exact_hash` for identity/render vs `normalized_hash`
  for TM suggestions. v1 = one conservative hash + version field.)

---

## 7. Migration / activation

**If prior data is unimportant (current stance): clean rebuild.**
1. Create the 11 tables; verify FKs in `information_schema` (MyISAM silently
   ignores FK syntax — fail fast if engine/charset/privileges don't support them).
2. **Compensating drops, NOT rollback** — DDL implicitly commits; track created
   objects, drop in reverse order on failure. **Do not drop the old table until
   the new schema validates** (recoverable failed activation).
3. Seed `languages` + `source_locale`.
4. Switch the active schema-version flag.
5. Re-discover content.

**If translations must be preserved (from v4 single-table + source_key):** see
rework_drop_source_key.md, plus:
- Unpack `source_key` from the **readable columns** (one-way hashed segments can't
  be reversed; i18n/html identity rebuilds via re-discovery if not retained).
- **No `INSERT IGNORE`** (silent data loss/coercion); detect conflicts explicitly,
  fail batch on unexpected warnings, log rejects.
- Canonical row by `MIN(id)` then join for text — never `MIN(longtext)`.
- Divergent translations of the same text+lang → **conflict report**, ranked
  `reviewed > needs_review > machine`, never silent-merged.
- Batch by id ranges (~5k), transaction per chunk, background job.
- Cutover state machine: create → dual-write/pause → high-water mark → backfill →
  catch-up → validate → flip → keep old read-only one release.

---

## 8. Multisite

- Per-site tables: use `$wpdb->prefix` (NOT `base_prefix`); `get_blog_prefix()`
  for a specific site.
- Network activation: iterate existing sites; `wpmu_new_blog`/site-creation hook:
  create the site's tables; site deletion: drop/archive them.
- Per-site schema-version option; configure `source_locale` + languages per site.
- **FK constraint names must be unique per schema** → include the table
  prefix/blog id in generated names, keep under MySQL's 64-char identifier limit.
- `switch_to_blog()`/`restore_current_blog()` around install operations.

---

## 9. Build order

```
1. Languages table + source_locale option + shared Normalizer (frozen, versioned).
2. The 10 tables (parent ×5, child ×5 with the TWO key variants) + FK verify + compensating-drop activation.
3. Per-type repositories (hash-keyed: post/terms/html; parent_id-keyed: i18n/postmeta).
4. Discovery (incl. post_content fragmentation) + INSERT IGNORE parents.
5. Lifecycle hooks (posts/meta/terms) with re-entrancy guard + source-change
   mechanic (delete-stale + insert-new + approximate carry-forward→needs_review).
6. gettext capture (gettext_capture_plan.md) — singular v1, frontend-only.
7. Translation write path via Action Scheduler + two-tier ownership gate.
8. Render: full-page cache → preload maps (per-type) → output-buffer parser → apply.
9. HTML fallback capture (lowest confidence, selector identity).
10. Orphan sweep (hash-link children) + admin work-queue views (idx_queue).
11. Activation/migration + multisite provisioning.
```

---

## 10. Cross-cutting non-negotiables

- The PHP/Redis cache is **volatile** — a speed layer, not the source of truth.
  The schema must be correct on its own.
- Front-end fast (cache + memory maps); admin may be slower (joins/unions ok).
- Writes async/host-safe, never at `shutdown`.
- Hard FKs fail-fast, but DDL can't `ROLLBACK` → compensating drops.
- One shared, frozen, versioned normalizer — everywhere.
- Per-type child access — never a uniform child repository.