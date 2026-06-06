# TransLangs — Goal Data Migration

> The target data model and the plan to reach it. Supersedes the older
> single-table schema. **Decision:** the previously-saved records are no longer
> important, so there is **no data migration** from the old table — this is a
> clean rebuild. Any prior table is dropped on upgrade.

---

## 1. Direction

```
Frontend render performance first — render never joins.
Admin can join freely (cold path, paginated).
Source/default language is WordPress core state, not a per-row column.
Original text is stored inline in each translation table (self-contained rows).
Translation identity is context/field-specific.
Tables are split by parent so each can carry a real foreign key.
```

This stays within the approved architecture: URL-based public pages, separated
WordPress admin, local translation memory, reusable core engine.

**Current repository alignment:** the cleaned code creates `wp_tl_languages`,
`_post`, `_postmeta`, `_terms`, `_i18n`, and `_i18n_meta` through raw SQL.
`_i18n_meta` is added by schema version `6` through
`TableDefinitions::i18nMeta()`, `TranslationMemorySchema::tlI18nMetaTable()`,
FK verification for `fk_i18n_meta`, and reverse drop order that drops
`_i18n_meta` before `_i18n`.

---

## 2. Target shape (1 registry + 4 translation tables + i18n metadata)

```
wp_tl_languages              code (PK) — authoritative TARGET-language registry
wp_tl_translations_post      FK post_id    → wp_posts.ID            (CASCADE)
                             FK target_lang → wp_tl_languages.code   (CASCADE)
wp_tl_translations_postmeta  FK post_id    → wp_posts.ID            (CASCADE)
                             FK target_lang → wp_tl_languages.code   (CASCADE)
wp_tl_translations_terms     FK term_taxonomy_id → wp_term_taxonomy.term_taxonomy_id (CASCADE)
                             FK target_lang → wp_tl_languages.code   (CASCADE)
wp_tl_translations_i18n      FK target_lang → wp_tl_languages.code   (CASCADE)
                             (no parent FK — gettext strings have no parent row)
wp_tl_translations_i18n_meta FK i18n_id → wp_tl_translations_i18n.id (CASCADE)
                             (1:1 observation metadata; admin-only, off hot path)
```

Languages holds **target** languages only; the **source language comes from
WordPress core** (`get_locale()`), not from the plugin. The translation tables
are split by parent type, which removes the `source_type` column and lets each
table carry a real FK to its parent. Post fields and post **meta** are separate
tables because meta needs a context dimension that flat fields don't.

---

## 3. Tables

### 3.1 `wp_tl_languages` — language registry (single source of truth)

```sql
CREATE TABLE {$languages} (
    code         varchar(10)  NOT NULL,            -- 'fr','es','ar' (TARGET languages only)
    name         varchar(100) NOT NULL,            -- "French"
    native_name  varchar(100) NOT NULL DEFAULT '', -- "Français"
    direction    varchar(3)   NOT NULL DEFAULT 'ltr',
    status       tinyint(1)   NOT NULL DEFAULT 1,   -- 1 active, 0 inactive
    sort_order   int          NOT NULL DEFAULT 0,
    created_at   datetime     NOT NULL,
    updated_at   datetime     NOT NULL,
    PRIMARY KEY (code)
) ENGINE=InnoDB {$charsetCollate};
```

- `code` is the PK and the FK target for every translation table.
- Holds **target** languages only — the languages you translate *into*.
- **The source/default language is NOT stored here.** It is read from WordPress
  core via `get_locale()` (the site's `WPLANG`). Storing it would duplicate
  core's source of truth and reintroduce drift. There is no `is_default` column
  and no settings table.
- `ResolveRequestLanguage` / `SupportedLanguages` read enabled targets from this
  table and the source language from `get_locale()`.
- Enabling a target language = inserting a row here; discovery for a language
  can only succeed once its row exists (the FK enforces it).

### 3.2 `wp_tl_translations_post` — post/page/product/CPT/attachment field text

```sql
CREATE TABLE {$post} (
    id              bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    post_id         bigint(20) unsigned NOT NULL,
    target_lang     varchar(10)  NOT NULL,
    field           varchar(32)  NOT NULL,            -- post_title/post_content/post_excerpt
    original_hash   binary(32)   NOT NULL,            -- sha256(normalized original_text), raw 32 bytes
    original_text   longtext     NOT NULL,            -- inline; render needs no join
    translated_text longtext     NULL,
    status          tinyint(3) unsigned NOT NULL DEFAULT 0,
    provider        varchar(50)  NULL,
    created_at      datetime     NOT NULL,
    updated_at      datetime     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_identity (target_lang, post_id, field, original_hash),
    KEY idx_lang_status (target_lang, status),
    KEY idx_same_original (target_lang, original_hash),
    CONSTRAINT fk_post_parent
        FOREIGN KEY (post_id) REFERENCES {$wpdb->posts} (ID) ON DELETE CASCADE,
    CONSTRAINT fk_post_lang
        FOREIGN KEY (target_lang) REFERENCES {$languages} (code) ON DELETE CASCADE
) ENGINE=InnoDB {$charsetCollate};
```

Holds the post's **own field** text: `post_title`, `post_content`,
`post_excerpt` (and the equivalent for CPTs). Attachment **title / caption /
description** are post fields too, so they live here for attachments. **Alt text
does NOT live here** — it is `_wp_attachment_image_alt` post meta, so it belongs
in the postmeta table (§3.5).

> **No separate render index.** The render query filters by
> `(target_lang, post_id)`, the leftmost prefix of `uniq_identity`. `idx_same_original`
> exists only for the admin "same original text" helper (it is NOT served by the
> unique key, which leads with `post_id` after the language). No index avoids
> the off-page `longtext` fetch for `translated_text` — that is a row read.

### 3.3 `wp_tl_translations_terms` — taxonomy term name/description

```sql
CREATE TABLE {$terms} (
    id               bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    term_taxonomy_id bigint(20) unsigned NOT NULL,   -- the term-in-its-taxonomy, not the raw term
    target_lang      varchar(10)  NOT NULL,
    field            varchar(32)  NOT NULL,           -- name / description / slug
    original_hash    binary(32)   NOT NULL,
    original_text    longtext     NOT NULL,
    translated_text  longtext     NULL,
    status           tinyint(3) unsigned NOT NULL DEFAULT 0,
    provider         varchar(50)  NULL,
    created_at       datetime     NOT NULL,
    updated_at       datetime     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_identity (target_lang, term_taxonomy_id, field, original_hash),
    KEY idx_lang_status (target_lang, status),
    KEY idx_same_original (target_lang, original_hash),
    CONSTRAINT fk_term_parent
        FOREIGN KEY (term_taxonomy_id) REFERENCES {$wpdb->term_taxonomy} (term_taxonomy_id) ON DELETE CASCADE,
    CONSTRAINT fk_term_lang
        FOREIGN KEY (target_lang) REFERENCES {$languages} (code) ON DELETE CASCADE
) ENGINE=InnoDB {$charsetCollate};
```

> **Keyed on `term_taxonomy_id`, not `term_id`.** In WordPress a single
> `term_id` can be shared across taxonomies (a "shared term"); the meaningful
> multilingual object is the term *in its taxonomy* — `wp_term_taxonomy`'s
> `term_taxonomy_id`. This avoids conflating, say, a "News" category with a
> "News" tag that happen to share a term row. The FK targets
> `wp_term_taxonomy.term_taxonomy_id` (deleting the taxonomy assignment cascades
> the translations). Because the taxonomy is already implied by
> `term_taxonomy_id`, `field` is just `name` / `description` / `slug` — no
> `{taxonomy}.` prefix needed, so `varchar(32)` is comfortable.

### 3.4 `wp_tl_translations_i18n` — theme/plugin gettext strings

```sql
CREATE TABLE {$i18n} (
    id              bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    target_lang     varchar(10)  NOT NULL,
    source          varchar(20)  NOT NULL,            -- 'gettext' | 'rendered_html'
    reference       varchar(191) NOT NULL DEFAULT '', -- readable: "woocommerce/woocommerce.php"
    reference_hash  binary(32)   NOT NULL,            -- sha256(normalized reference); used in the key
    original_hash   binary(32)   NOT NULL,
    original_text   longtext     NOT NULL,
    translated_text longtext     NULL,
    status          tinyint(3) unsigned NOT NULL DEFAULT 0,
    provider        varchar(50)  NULL,
    created_at      datetime     NOT NULL,
    updated_at      datetime     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_identity (target_lang, source, reference_hash, original_hash),
    KEY idx_lang_status (target_lang, status),
    KEY idx_reference (reference_hash),
    CONSTRAINT fk_i18n_lang
        FOREIGN KEY (target_lang) REFERENCES {$languages} (code) ON DELETE CASCADE
) ENGINE=InnoDB {$charsetCollate};
```

No parent FK: a gettext string has no row in any WordPress table. Cleanup is by
plugin **uninstall** reconciliation (§6).

> **Why `reference_hash` instead of keying on `reference` directly:** the unique
> key `(target_lang, source, reference, original_hash)` with `reference
> varchar(191)` would need ~1140 bytes under utf8mb4 — over the 767-byte legacy
> InnoDB index limit on hosts without `innodb_large_prefix`, which would make
> table creation **fail** (and, since we fail-fast, block activation). Keying on
> a fixed `binary(32)` hash of the reference keeps the key small and constant
> regardless of how long the plugin path is. `reference` stays as a readable
> column for the admin; `reference_hash` is what the key and lookups use. Build
> the hash from a normalized reference (lowercased, trimmed) so casing
> differences don't split rows.

**Two i18n identity styles:**
- **gettext-sourced** — real owner: `source='gettext'`, `reference`=textdomain/file.
- **rendered_html fallback** — chrome with no file owner: `source='rendered_html'`,
  `reference` holds a **two-level structural selector** (`{landmark}>{intermediate}>{tag}`,
  e.g. `header>nav>a`), so `reference_hash=sha256(selector)`. Identity is
  `(target_lang,'rendered_html',reference_hash,original_hash)`. The selector is
  built from HTML **tag hierarchy, never CSS classes** (classes are volatile;
  structural tags change only on real restructuring). Same text in different
  regions (header vs. footer) → two rows; the same template element repeated →
  one row. A major theme restructure can shift the selector and orphan+rediscover
  that row — accepted cost of selector-in-identity. "Where it appeared" (URL,
  last-seen) is separate observation in `_i18n_meta`; the selector in `reference`
  is *identity* (which slot), the meta is *observation* (which page/when).

### 3.6 `wp_tl_translations_i18n_meta` — observation metadata (admin only)

Where an i18n string was last seen. Kept out of `_i18n` so the hot render table
stays lean; only the cold admin "View" path joins it. Written in the Action
Scheduler worker, never in `shutdown` and never on the render hot path.

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

- **1:1** with the i18n row (the FK *is* the PK) → no orphan or duplicate meta;
  cascades away when the i18n row (or its language) is deleted.
- All location fields **overwritten** on each sighting (latest wins);
  `first_seen_at` set once; `seen_count` increments as a rough "common chrome"
  signal (soft — reflects observation events, not exact page views).
- This is the **one** legitimate `last_seen_at` in the schema: i18n has no
  FK-parent lifecycle, so a recency signal is genuinely useful here (unlike the
  owned tables, where cascade handles lifecycle).

### 3.5 `wp_tl_translations_postmeta` — post meta (alt text, ACF, Elementor, custom meta)

Post fields live in `_post`; **post meta** lives here. This covers attachment
**alt text** (`_wp_attachment_image_alt`), ACF fields, Elementor data, WooCommerce
custom meta, and any other translatable `wp_postmeta` value. Meta is nested and
repeatable, so identity needs a **context** dimension that the flat `_post` table
does not.

```sql
CREATE TABLE {$postmeta} (
    id              bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    post_id         bigint(20) unsigned NOT NULL,
    target_lang     varchar(10)  NOT NULL,

    meta_key        varchar(191) NOT NULL,            -- readable: '_wp_attachment_image_alt', 'field_abc', '_elementor_data'
    meta_key_hash   binary(32)   NOT NULL,            -- sha256(meta_key); keeps the key short & fixed

    context         text         NULL,                -- readable path into nested meta (see below)
    context_hash    binary(32)   NOT NULL,            -- sha256(context); flat meta => sha256('')

    original_hash   binary(32)   NOT NULL,            -- sha256(normalized original_text)
    original_text   longtext     NOT NULL,
    translated_text longtext     NULL,

    status          tinyint(3) unsigned NOT NULL DEFAULT 0,
    provider        varchar(50)  NULL,
    created_at      datetime     NOT NULL,
    updated_at      datetime     NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uniq_identity (target_lang, post_id, meta_key_hash, context_hash, original_hash),
    KEY idx_lang_status (target_lang, status),
    KEY idx_same_original (target_lang, original_hash),
    CONSTRAINT fk_postmeta_parent
        FOREIGN KEY (post_id) REFERENCES {$wpdb->posts} (ID) ON DELETE CASCADE,
    CONSTRAINT fk_postmeta_lang
        FOREIGN KEY (target_lang) REFERENCES {$languages} (code) ON DELETE CASCADE
) ENGINE=InnoDB {$charsetCollate};
```

**Why this table exists separately from `_post`:**
- Alt text is `_wp_attachment_image_alt` **meta**, not a post field — storing it
  in `_post` as `field=alt` was inaccurate to its origin. It now lives here,
  meta-correct.
- ACF / Elementor / custom meta hold **many** translatable strings inside one
  meta value at different nested positions. `field + original_hash` can't tell
  them apart; `meta_key_hash + context_hash + original_hash` can. This is the one
  place sub-value context is genuinely part of identity (unlike template chrome,
  where identical text deliberately shares one translation).

**`meta_key` + `meta_key_hash`** — same pattern as i18n's `reference`: the
readable key can be long (`_elementor_data`, ACF keys), so the **hash** goes in
the unique key (fixed `binary(32)`), the readable column stays for admin.

**`context` conventions (each reader defines its own):**
```
flat meta (alt text):  context = NULL/''        context_hash = sha256('')
ACF field:             context = 'acf:field_abc123'
Elementor:             context = 'elementor:section[0].col[1].widget[3].settings.title'
repeater row:          context = 'acf:field_rep[2].field_child'
```
The table never parses these — it only hashes whatever path string the reader
emits. New integrations add their own convention without schema change.

FK to `wp_posts` by `post_id` (meta dies with its post → cascade). FK to
languages, same as the others.

> **Build status:** this table **is created now** (activation builds it), and
> the **media reader writes alt text here** (`meta_key='_wp_attachment_image_alt'`,
> flat context). ACF / Elementor / custom-meta readers are future work that will
> populate it without further schema change.

---

## 4. Keys & identity

| Table | Physical PK | Logical unique identity |
|---|---|---|
| post     | `id` bigint | `(target_lang, post_id, field, original_hash)` |
| postmeta | `id` bigint | `(target_lang, post_id, meta_key_hash, context_hash, original_hash)` |
| terms    | `id` bigint | `(target_lang, term_taxonomy_id, field, original_hash)` |
| i18n     | `id` bigint | `(target_lang, source, reference_hash, original_hash)` |

All hashes are `BINARY(32)` (raw SHA-256, 32 bytes) — half the index footprint
of hex `char(64)` and no charset/collation budget concerns. The **source
language is not stored** in any table; it is `get_locale()` from WordPress core.

**Role split (settled):**
- `id bigint AUTO_INCREMENT` — physical PK: InnoDB clustered key, lean FK/index
  references (8 bytes, sequential inserts), admin row handle (`WHERE id = ?`).
- `original_hash = sha256(normalized(original_text))` — a **content** hash (text
  only, NOT identity-folded), used for render matching and the same-original-text
  admin feature.
- `uniq_identity` — logical uniqueness guarantee + the render lookup index.

Rejected: hash-as-PK (a `char(64)` clustered key bloats every secondary index
and both FKs ~8×, and randomizes inserts; `uniq_identity` already guarantees
uniqueness). No `source_lang` column (source language is `get_locale()` from
core). No `source_type` column (the table is the type). `normalized_text` not
stored (recompute transiently). `last_seen_at` not stored (FK cascade +
uninstall reconciliation).

---

## 5. Render query (hot path, never joins)

```sql
SELECT field, original_hash, translated_text, status
FROM   wp_tl_translations_post
WHERE  target_lang = %s AND post_id = %d;
```

Served by the **leftmost prefix of `uniq_identity`** —
`(target_lang, post_id, …)` — as one indexed seek per entity, independent of
table size. No separate render index exists or is needed. A page may touch up to
four tables (post + postmeta for the entity, terms for its categories, i18n for
theme/plugin chrome) — each a separate seek, no join. `original_text` is not
selected at render (the text is already on the page; the hash links the row back
to the node).

Admin board MAY join / order / paginate freely (cold path).

### Hot-path rule: preload to memory, never query-per-string (required)

The real performance danger is not joins — it is **one DB query per string**,
especially for gettext, where a single page fires hundreds of `__()` calls. The
render path must batch, not loop:

```
request → detect language → preload the needed translation maps into memory
        → render does PHP array lookups → zero repeated DB queries
```

- **Per entity:** one query per post/page loads ALL its field + meta
  translations for the active language into an associative map
  (`'field:<hash>' => '...'`).
- **Terms on the page:** one batched query for all term_taxonomy_ids in use.
- **i18n/gettext:** preload by `target_lang` + domain/source, then serve every
  `__()` call from the in-memory map — never a query inside the gettext filter.

Repositories expose `getXxxTranslations(...) : array` (a map), and the renderer
consumes the map. A query inside a per-string filter is a defect.

Same-original-text admin helper (apply one translation to all rows of identical
source text), served by `idx_same_original`:

```sql
SELECT id, post_id, field, translated_text, status
FROM   wp_tl_translations_post
WHERE  target_lang = %s AND original_hash = %s;
```

---

## 6. Lifecycle & cleanup

| Event | Mechanism |
|---|---|
| Post/page/product/attachment deleted | FK CASCADE on `post_id` (both `_post` and `_postmeta`) |
| Taxonomy term/assignment deleted | FK CASCADE on `term_taxonomy_id` |
| Language removed | FK CASCADE on `target_lang` — deletes all its translations across all tables in one operation |
| Plugin **uninstalled** | Reconcile `_i18n`: delete rows whose `reference` is no longer installed (survives deactivate/reactivate) |
| Theme switched | Reconcile `_i18n` theme-sourced rows |
| Entity field/meta edited (e.g. title changed) | Reader re-scan on `save_post`: re-discover current fields/meta; delete this entity's rows whose hash is no longer among current hashes |

Source language is `get_locale()` from WordPress core — there is no stored
default to protect or accidentally delete. Removing a **target** language simply
cascades away its translations (intended).

---

## 7. Integrity model — hard FK, fail-fast

- All FKs are **real DB constraints**, `ON DELETE CASCADE`, created via
  `$wpdb->query()` raw SQL (not `dbDelta`, which strips FK clauses). `$wpdb`
  gives no idempotency, so each step is guarded (`CREATE TABLE IF NOT EXISTS`;
  check `information_schema` before `ADD CONSTRAINT`).
- **Activation verifies each FK exists** in
  `information_schema.TABLE_CONSTRAINTS` / `KEY_COLUMN_USAGE` after creation. If
  any is missing (MyISAM `wp_posts`, collation mismatch, restricted host),
  activation **rolls back** (drops created tables) and **fails** with an admin
  notice stating the requirement. No silent degraded mode.
- FK columns must **exactly** match targets:
  - `post_id` → `bigint(20) unsigned` (mirror `wp_posts.ID`).
  - `term_taxonomy_id` → `bigint(20) unsigned` (mirror `wp_term_taxonomy.term_taxonomy_id`).
  - `target_lang` → `varchar(10)`, same charset/collation as `languages.code`.
- **Creation order:** `wp_tl_languages` first (FK target must pre-exist), then
  `_post`, `_postmeta`, `_terms`, `_i18n`, and finally `_i18n_meta` because it
  FKs to `_i18n`. **Drop order:** exact reverse.
- Engine **InnoDB**; charset **utf8mb4** asserted explicitly on activation.
- `status` / `field` / `source` domains enforced in code (value objects);
  cross-host CHECK constraints aren't dependable.
- **Schema evolution without `dbDelta` (required).** Dropping `dbDelta` means we
  lose its automatic add-columns-on-upgrade behavior, so we must replace it
  deliberately: a versioned migration runner keyed off the DB-version option.
  `maybeUpgrade()` is not just "create tables" — it is a sequence of **guarded
  ALTERs** (check `information_schema` for the column/constraint, apply only if
  absent), each step idempotent and safe to re-run. Every future schema change
  adds a numbered, guarded step here.

---

## 8. Build / upgrade plan (NO legacy data migration)

Previously-saved records are not important, so this is a **clean rebuild**, not a
migration. This removes the riskiest part (backfill + orphan pre-filtering +
count validation) entirely.

**Order:**

1. **On upgrade/activation, drop the old single table** if present
   (`DROP TABLE IF EXISTS wp_translangs_strings`). No backfill.
2. **Create `wp_tl_languages`** (InnoDB). Seed it with the enabled **target**
   languages (the source language is `get_locale()`, never stored here). Must
   exist before the FK tables.
3. **Create the four translation tables** (`_post`, `_postmeta`, `_terms`,
   `_i18n`) with inline FK clauses via `$wpdb->query()`.
4. **Create `_i18n_meta` explicitly** after `_i18n`, using
   `TableDefinitions::i18nMeta()`, `TranslationMemorySchema::tlI18nMetaTable()`,
   and `fk_i18n_meta` in `ConstraintVerifier::findMissing(...)`.
5. **Verify every FK** (§7), including `fk_i18n_meta`. On any failure: drop the
   created tables in reverse order (`_i18n_meta`, `_i18n`, `_terms`,
   `_postmeta`, `_post`, languages), abort activation, show admin notice.
6. **Bump the DB version option** so the rebuild/additive schema step runs once.
7. **Point all code at the new tables** — repositories, render, admin; target
   languages read from `wp_tl_languages`, source language from `get_locale()`.

Because there is no data to preserve, activation is create-verify-or-rollback
only. If the host can't support the FKs, the plugin refuses to activate rather
than running degraded.

---

## 9. Code wiring (build order)

1. `wp_tl_languages` table + language registry; rewire
   `ResolveRequestLanguage` / `SupportedLanguages` to read target languages from
   it and the source language from `get_locale()`.
2. The four translation tables plus `_i18n_meta` (raw `$wpdb->query()`, exact
   FK column types, InnoDB, utf8mb4). `_i18n_meta` uses
   `TableDefinitions::i18nMeta()`, `tlI18nMetaTable()`, `fk_i18n_meta`
   verification, reverse drop order, and schema version `6`.
3. Activation create-verify-rollback; correct create/drop ordering.
4. Split repositories + the §5 render lookup (no join); structured discovery
   under `TransLangs\TranslationMemory` produces `TranslationSourceIdentity`
   directly for each active `wp_tl_languages` row. Do not restore
   `ContentSource`, `SourceString`, or the old discovery namespace.
5. Cleanup hooks: `before_delete_post`, `delete_term` (covered by CASCADE too,
   belt-and-braces), language-delete (CASCADE), i18n uninstall reconciliation,
   `save_post` entity re-scan + stale-hash delete.

---

## 10. Still application-enforced (DB can't, on WordPress)

- utf8mb4 assertion on activation.
- `status` / `field` / `source` value domains (value objects).
- i18n uninstall reconciliation (no FK models "plugin no longer installed").
- Entity re-scan + stale-hash delete on `save_post`.
- **`save_post` re-entrancy guard (required).** The entity re-scan must unhook
  itself before any write that could re-fire the save lifecycle, then re-hook:
  ```php
  remove_action('save_post', [$this, 'scanPostFields']);
  // ... discovery / updates ...
  add_action('save_post', [$this, 'scanPostFields']);
  ```
  Without this, a re-scan that touches post meta can infinite-loop.
- **Deterministic normalization (correctness-critical).** The hash match only
  works if normalization is identical everywhere. Normalization must: strip
  carriage returns (`\r`), trim, collapse internal whitespace, decode HTML
  entities, and standardize encoding (UTF-8). Same input → same hash, always.
  `reference_hash` uses a normalized reference (lowercased, trimmed) for the
  same reason.
- **Cache-clear hooks on delete.** Alongside the FK cascade, hook
  `before_delete_post` / `pre_delete_term` to clear any in-memory/object caches
  the plugin built for that entity, so the app layer never serves ghost data
  after the rows are cascade-deleted.

---

## 11. External review — applied vs. corrected

A schema review (Gemini) raised several points. Recorded here so the reasoning
survives: which were folded in, and which were overstated or wrong.

**Applied (real):**
- **`_i18n` unique-key byte length** — `reference varchar(191)` in the key
  exceeds the 767-byte legacy InnoDB limit on hosts without
  `innodb_large_prefix`, failing table creation. **Fixed** via `reference_hash
  binary(32)` in the key (§3.4).
- **`save_post` infinite-loop guard** — folded into §10 as a required pattern.
- **Keep schema-evolution despite dropping `dbDelta`** — folded into §7 as the
  guarded-ALTER delta runner.
- **Deterministic normalization** — made an explicit correctness requirement
  (§10).
- **Cache-clear on delete** — added to §10.

**Corrected (overstated or wrong):**
- **"Change `idx_render` to `(target_lang, post_id)`"** — the proposed index was
  itself redundant: the **unique key's leftmost prefix already serves the render
  query**, so the right move was to **drop `idx_render` entirely** (done), not
  re-shape it. The claim that an index would speed the `longtext` off-page fetch
  is incorrect — that is a row read, not an index operation.
- **Index-length arithmetic ("134 chars × 4")** — mixed character and byte
  columns (`post_id` is an 8-byte bigint, not chars). The *conclusion* (watch
  key length) was right; the method was not. We additionally narrowed `field` to
  `varchar(32)` to reclaim real key budget.
- **`delete_term` split-terms caution** — does not affect a CASCADE on
  `wp_terms.term_id`; addressed sufficiently by the cache-clear hook, no schema
  change needed.

### Second review (ChatGPT)

**Applied (real):**
- **`term_taxonomy_id` instead of `term_id`** (§3.3) — the meaningful
  multilingual object is the term-in-its-taxonomy; `term_id` can be shared across
  taxonomies. FK now targets `wp_term_taxonomy`. Also simplifies `field` to
  `name`/`description`/`slug`.
- **`BINARY(32)` instead of `CHAR(64)` for all hashes** — half the index
  footprint, no charset/collation budget concerns.
- **Preload-to-memory render rule** (§5) — never query-per-string; batch into an
  in-memory map, critical for gettext. Made an explicit hot-path requirement.
- **`wp_tl_translations_postmeta` table** (§3.5) — alt text is meta
  (`_wp_attachment_image_alt`), not a post field; ACF/Elementor/custom meta need
  a `context_hash` dimension. Built now; media reader writes alt here.

**Decided differently:**
- **Default-language storage (#13)** — reviewer offered a settings-table FK; we
  went further and **store no default at all**. WordPress core already holds the
  site language (`get_locale()`); duplicating it is the drift problem again.
  Dropped `is_default` entirely.
- **Full gettext i18n shape (#7: domain/context/plural)** — **deferred.** Real
  for a gettext-capture pipeline, which is not built. Keeping i18n minimal until
  that feature exists; expand the table with the readers that need it.
- **`context_hash` on the post table (#11)** — **not added to `_post`.** Whole
  post fields don't need sub-position context, and template chrome deliberately
  shares one translation for identical text. Context-as-identity lives where it's
  actually needed: the **postmeta** table (nested ACF/Elementor values).
- **Dual `exact_hash` + `normalized_hash` (#12)** — **declined.** Solved by
  tuning one conservative normalization (don't collapse meaningful whitespace,
  treat `&nbsp;` distinctly) rather than doubling hash columns. Revisit only if a
  real collision appears.
- **Dual-mode FK fallback (#3)** — **not adopted** (private/controlled project,
  fail-fast chosen). Revisit if TransLangs ships to public WordPress.org.

---

## 12. Decisions ledger

| Decision | Choice | Why |
|---|---|---|
| One table vs split | **Split by parent** | Real FK per parent; kills polymorphic `source_id` |
| `source_type` column | **Removed** | The table identifies the type |
| `source_lang` / default | **Not stored at all** | WordPress core `get_locale()` is the source of truth |
| `original_text` | **Inline per table** | Self-contained; render needs no join; storage cheap/off-page |
| Shared `originals` table | **Rejected** | Two-write discovery + join + orphan sweep for small saving |
| i18n as own table | **Yes (minimal for now)** | Gettext strings have no parent ID; full domain/plural shape deferred |
| postmeta table | **Yes, built now** | Alt is meta not field; ACF/Elementor need context_hash |
| Terms key | **`term_taxonomy_id`** | Term-in-taxonomy is the real object; term_id can be shared |
| Hashes | **`BINARY(32)`** | Half the index footprint vs hex char(64) |
| Render hot path | **Preload to memory, no query-per-string** | One query per entity/context; gettext would collapse otherwise |
| `context_hash` | **Postmeta only** | Nested meta needs it; flat post fields and template chrome don't |
| Languages table + FK | **Target languages only** | Delete a language → cascade its translations; FK targets OUR table |
| Languages PK | **`code` varchar(10)** | Human-readable rows |
| FK enforcement | **Hard FK, fail activation if absent** | Deliberate "modern InnoDB host" stance; verify-or-rollback |
| Translation table PK | **`id` bigint + `uniq_identity` unique** | Lean clustered key + lean FKs; logical uniqueness preserved |
| Hash-as-PK | **Rejected** | clustered hash key bloats indexes/FKs, randomizes inserts |
| `normalized_text` | **Not stored** | Pure function of original_text |
| `last_seen_at` | **Not stored** | FK cascade + uninstall reconciliation cover lifecycle |
| Dual hashes (exact+normalized) | **Declined** | Tune one conservative normalization instead |
| **Legacy data migration** | **None — clean rebuild** | Prior records not important; removes the riskiest step |
