# TransLangs — Database Structure (v5)

Reference for the translation-memory schema. Source of truth is
`src/TranslationMemory/Infrastructure/Schema/TableDefinitions.php` (DDL) and
`src/TranslationMemory/Infrastructure/TranslationMemorySchema.php` (install /
versioning). If they disagree with this doc, the code wins — update the doc.

---

## 1. Mental model

Every translatable string in the site is stored **once** as a *source* (a PARENT
row), independent of language. Each translation of that source, per target
language, is a *translation* (a CHILD row). One parent → zero-or-more children.

There are five **kinds** of source, each with its own parent table and its own
child table — ten tables total:

| Kind        | Parent table        | Child table                     | What it holds |
|-------------|---------------------|---------------------------------|---------------|
| post        | `wp_tl_post`        | `wp_tl_post_translation`        | post title / excerpt / each `post_content` block |
| meta        | `wp_tl_meta`        | `wp_tl_meta_translation`        | translatable post meta (e.g. ALT text, allow-listed keys) |
| term        | `wp_tl_term`        | `wp_tl_term_translation`        | taxonomy term name / description |
| staticText  | `wp_tl_staticText`  | `wp_tl_staticText_translation`  | gettext (`__()`) strings |
| html        | `wp_tl_html`        | `wp_tl_html_translation`        | hardcoded theme/template HTML block units |

(`wp_` is the site's `$wpdb->prefix`.) There is **no** numeric "type" column — the
identity of a string is its table. An opaque `SourceRef` of the form `{kind}:{id}`
(e.g. `post:42`) points at one parent row.

Languages live in WordPress **options**, not a table. `lang_code` (e.g. `fr`,
`pt-br`) is a plain `varchar(20)` column on every child row, with no FK.

---

## 2. The two child-key models

This is the single most important design decision. Children link to their source
in one of two ways:

### a) Hash-link children — `post`, `term`, `html`

Child keyed by **`(original_hash, lang_code)`**. No `parent_id`, no FK.

The translation is owned by the *text*, not the position. Identical source text
appearing in many places (the same paragraph in 10 posts, the same heading in two
templates) shares **one** translation row — translate it once, it applies
everywhere. The parent's `original_hash` is the link; `idx_hash` on the parent
makes the lookup fast.

> Because there is no FK, deleting a parent can leave an orphan child. Orphans are
> harmless (nothing references them) and reclaimed by a background sweep.

### b) Parent-id children — `meta`, `staticText`

Child keyed by **`(parent_id, lang_code)`**, FK'd to the parent's `id` with
`ON DELETE CASCADE`.

The translation is owned by the *position*, because context can make identical
text translate differently (a meta value under one key vs another; a gettext
string in one domain/context vs another). Deleting the parent cascades the
children away — no orphans possible.

| | Hash-link | Parent-id |
|---|---|---|
| Tables | post, term, html | meta, staticText |
| Child key | `(original_hash, lang_code)` | `(parent_id, lang_code)` |
| FK to parent | none | yes, `ON DELETE CASCADE` |
| Shared by | identical text, everywhere | the specific position only |
| Render lookup | by `original_hash` | join `child.parent_id = parent.id` |

---

## 3. Parent tables (sources)

Common columns on every parent: `id` (PK, auto-increment), `original_hash`
`binary(32)`, `original_text` `longtext` (the readable source, kept for display),
`status` `tinyint` (signed — see §5), `hash_algorithm_version` `tinyint unsigned`,
`created_at` / `updated_at` `datetime`. Common indexes: `idx_hash (original_hash)`,
`idx_queue (status, id)`.

The `UNIQUE KEY uniq_source` per table defines what makes a source distinct (its
*location*), and is what `INSERT IGNORE` deduplicates against during discovery.

| Table | Identity-specific columns | `uniq_source` | Owner FK |
|-------|---------------------------|---------------|----------|
| `tl_post` | `post_id`, `field` varchar(32) | `(post_id, field, original_hash)` | `fk_post_owner` → `wp_posts(ID)` |
| `tl_meta` | `post_id`, `meta_key` varchar(191), `meta_key_hash`, `context` text, `context_hash` | `(post_id, meta_key_hash, context_hash, original_hash)` | `fk_meta_owner` → `wp_posts(ID)` |
| `tl_term` | `term_taxonomy_id`, `field` varchar(32) | `(term_taxonomy_id, field, original_hash)` | `fk_term_owner` → `wp_term_taxonomy(term_taxonomy_id)` |
| `tl_staticText` | `source` varchar(20), `reference` varchar(191), `reference_hash` | `(source, reference_hash, original_hash)` | none |
| `tl_html` | `selector` varchar(255), `selector_hash`, `source_url` text | `(selector_hash, original_hash)` | none |

Notes:
- `field` on post = `post_title` / `post_excerpt` / `post_content` (block units).
- `meta` hashes the long columns (`meta_key_hash`, `context_hash`) so the unique
  key stays fixed-width; the readable `meta_key` / `context` are kept alongside.
- `staticText.reference` = `domain|context` for a gettext string; `reference_hash`
  is its hash. `source` distinguishes the discovery channel (e.g. `gettext`).
- `html.selector` is a structural CSS-ish path for admin display only. It is **not**
  part of the translation key — html is hash-link, so two positions with the same
  text share one translation even with different selectors.
- The owner FKs are why post/term/meta sources vanish automatically when the
  underlying WordPress post or term is deleted.

---

## 4. Child tables (translations)

Common columns: `id` (PK), `lang_code` varchar(20), `translated_text` longtext,
`status` tinyint (default `1` = machine), `provider` varchar(50) NULL (the MT
engine name, or NULL for human), `created_at` / `updated_at`. Index
`idx_queue (lang_code, status, id)`.

| Table | Key column | `uniq_translation` | FK |
|-------|------------|--------------------|----|
| `tl_post_translation` / `tl_term_translation` / `tl_html_translation` | `original_hash` binary(32) | `(original_hash, lang_code)` | none |
| `tl_meta_translation` | `parent_id` | `(parent_id, lang_code)` | `fk_meta_tr_parent` → `tl_meta(id)` CASCADE |
| `tl_staticText_translation` | `parent_id` | `(parent_id, lang_code)` | `fk_static_tr_parent` → `tl_staticText(id)` CASCADE |

A missing child row means "not translated in this language" — that is the
`to_translate` state. Adding a new language creates **zero** rows up front;
children appear as strings get translated.

---

## 5. Status vocabulary

`status` is a **signed** `tinyint`. A row is always either a parent or a child, so
`-1` is unambiguous (ignored on a parent, needs_review on a child). Defined in
`src/TranslationMemory/Domain/TranslationStatus.php`.

| Scope | Value | Constant | Meaning |
|-------|------:|----------|---------|
| parent | `0` | `SOURCE_DISCOVERED` | translatable; normal — shows on the board |
| parent | `-1` | `SOURCE_IGNORED` | never translate; hidden, listed under "ignored" |
| child | `1` | `MACHINE` | machine translation (insert default) |
| child | `2` | `REVIEWED` | human-confirmed |
| child | `-1` | `NEEDS_REVIEW` | flagged (incl. carry-forward after a source edit) |

Derived rules:
- **done** = `status > 0` (machine or reviewed). This is the whole reason the
  column is signed — one `> 0` test instead of `IN (1, 2)`.
- **needs review** = `status = -1` (child).
- **to translate** = no child row exists for that `(source, lang)`.
- **Render**: a translation shows on the frontend whenever a child row exists for
  the language (machine, reviewed, *and* needs_review all render). Render reads do
  not filter on child status — only `lang_code`.

There is no "stale" status and no `translated_against_hash`. A source-text edit
deletes the stale parent and **carries forward** its translation into the new
parent as `needs_review (-1)`; child status alone drives the admin queue.

---

## 6. Hashing & normalization

`original_hash` = `SHA-256` (raw 32 bytes) of the **normalized** source text. The
single normalizer is `src/TranslationMemory/Domain/StringNormalizer.php`, used
identically by discovery, render, gettext capture and the HTML parser — same bytes
in, same hash out, everywhere.

Normalization steps (order is load-bearing):
1. strip `\r`
2. decode HTML entities as UTF-8
3. `mb_strtolower` (UTF-8) — case-insensitive matching
4. trim
5. collapse internal whitespace runs to a single space

Accents are **kept** (lexical in fr/es/…). The display text is preserved
separately in `original_text`, so normalization only affects matching.

`HASH_ALGORITHM_VERSION` (currently **2**) is stamped on every parent's
`hash_algorithm_version` column. Any change to the five rules above must bump this
constant and re-hash stored rows, or stored hashes silently stop matching freshly
computed ones.

---

## 7. Install, verification, versioning

`TranslationMemorySchema::install()` (raw DDL — never `dbDelta()`, which mangles FK
clauses):

1. Assert `wp_posts` and `wp_term_taxonomy` are **InnoDB** (else FKs can't be
   built) — abort with a readable failure otherwise.
2. Create the five **parents** (FK targets) first.
3. Create the three hash-link children (no FK), then the two parent-id children
   (FK'd back to their parent).
4. Verify **all five FKs** exist via `information_schema`
   (`fk_post_owner`, `fk_meta_owner`, `fk_term_owner`, `fk_meta_tr_parent`,
   `fk_static_tr_parent`).
5. On any missing FK: drop the just-created v5 tables (children first) and throw.
6. Only after verification passes, write `DB_VERSION` to the
   `translangs_db_version` option.

- `DB_VERSION` is currently **`15`**. `maybeUpgrade()` runs a full install when the
  stored version is lower. There is **no data migration** path — pre-existing
  tables are assumed dropped out of band; `CREATE TABLE IF NOT EXISTS` is
  idempotent.
- Source locale is frozen once at install into `translangs_source_locale`, so
  runtime never reads the mid-request-switchable `get_locale()`.

---

## 8. Ownership gate (why a string lands in one table, not two)

The same visible text could be discovered by more than one channel (a post block
*and* a gettext call *and* the HTML pass). To avoid storing it twice, a two-tier
gate keeps structured sources (post/meta/term/html) authoritative over staticText:

- **Tier-1** (`PageOwnedHashes`, request memory): hashes rendered as structured
  content this request are marked; gettext capture skips them.
- **Tier-2** (`OwnedStringIndex`, DB): the background gettext-capture worker and the
  HTML marking pass query the four structured parent tables and drop any hash they
  already own before inserting.

---

## Quick reference

```
PARENTS (sources, language-independent)         CHILDREN (translations, per lang)
  tl_post  ──fk──> wp_posts.ID                    tl_post_translation        (orig_hash, lang)
  tl_meta  ──fk──> wp_posts.ID                    tl_meta_translation        (parent_id, lang) ─fk→ tl_meta.id
  tl_term  ──fk──> wp_term_taxonomy.tt_id         tl_term_translation        (orig_hash, lang)
  tl_staticText                                   tl_staticText_translation  (parent_id, lang) ─fk→ tl_staticText.id
  tl_html                                         tl_html_translation        (orig_hash, lang)

status (signed tinyint):  parent 0=discovered  -1=ignored
                          child  1=machine  2=reviewed  -1=needs_review     done = status > 0
SourceRef: {kind}:{id}    kinds: post|meta|term|staticText|html   id = parent row id
```
