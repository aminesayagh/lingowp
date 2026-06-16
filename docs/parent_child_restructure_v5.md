# TransLangs — Parent/Child Restructure v5 (10 content tables)

> Re-architects the five self-contained translation tables into five
> **parent** source-string tables and five **child** per-language translation
> tables. The primary operational goal remains unchanged: adding a language
> creates **zero content rows**. Version 5 corrects render identity, enforces
> parent-level ignore semantics, preserves the option-backed language-code
> model, completes the post-meta lifecycle, and defines a safe migration/cutover
> protocol.

---

## 1. Core model and invariants

| | Parent: discovered source | Child: actual translation |
|---|---|---|
| One row per | contextual source string | parent × target language |
| Language | none | `lang_code` |
| Holds | readable identity, `original_hash`, `original_text`, source status | translated text, translation status, provider |
| Status | `0 discovered` or `3 ignored` | `1 machine`, `2 reviewed`, or `4 needs_review` |
| Created when | a source is discovered | a translation actually exists |
| Referential links | optional WordPress content FK | parent FK; language code is validated by the option-backed registry |

The model follows these invariants:

1. **A parent is the complete contextual identity of a source string.** A text
   hash alone is never treated as translation identity.
2. **A child is uniquely identified by `(parent_id, lang_code)`.**
3. **Children do not copy `original_hash`.** The source hash belongs to the
   parent and is not sufficient for context-safe rendering.
4. **`ignored` is authoritative on the parent.** Rendering must read the parent
   and require `parent.status = 0`; existing children may remain stored but are
   inactive while the parent is ignored.
5. **Child absence means “not translated yet.”** It does not mean ignored.
6. **Adding a language creates no parent or child rows.**

### Why rendering is contextual

The same normalized text can legitimately have different translations in
separate contexts. For example, `Home` can be a navigation label, a page title,
or a real-estate concept. Therefore this lookup is invalid:

```sql
WHERE lang_code = ? AND original_hash = ?
```

It can match several children belonging to different parents. Rendering must
first resolve the complete parent identity, then join its one child for the
requested language.

---

## 2. Option-backed language configuration

Do not add a `tl_languages` table. The current repository uses
`OptionLanguageRegistry`, and v5 keeps that model:

```text
translangs_active_lang    fr|ar      currently enabled target codes
translangs_existing_lang  fr|ar|es   every target code ever added
```

`translangs_existing_lang` is append-only. Disabling a language removes its code
from `translangs_active_lang` only; the code remains in the existing roster and
its translation content remains stored. There is no hard-delete or language
cleanup flow in this v5 plan.

The legacy `translangs_enabled_languages` option may be used only as a one-time
seed when both current rosters are empty. Display metadata such as name, native
label, and direction comes from `SupportedLanguages` by code; it is not stored
in SQL.

Target language codes are stored directly on child rows as:

```sql
lang_code varchar(10) NOT NULL
```

The source language remains the normalized WordPress locale from `get_locale()`
through `LocaleNormalizer::current()`. It is never stored as a target language.

---

## 3. The ten content tables

All hashes are raw SHA-256 values stored as `BINARY(32)`. All children use the
same relational shape, store the target language code directly, and do not
contain `original_hash`.

### 3.1 Posts — `wp_tl_post` / `wp_tl_post_translation`

```sql
CREATE TABLE {$post} (
    id            bigint unsigned NOT NULL AUTO_INCREMENT,
    post_id       bigint unsigned NOT NULL,
    field         varchar(32) NOT NULL,
    original_hash binary(32) NOT NULL,
    original_text longtext NOT NULL,
    status        tinyint unsigned NOT NULL DEFAULT 0,
    created_at    datetime NOT NULL,
    updated_at    datetime NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uniq_source (post_id, field, original_hash),

    CONSTRAINT {$fk_post_parent}
        FOREIGN KEY (post_id)
        REFERENCES {$wpdb->posts} (ID)
        ON DELETE CASCADE
) ENGINE=InnoDB {$charsetCollate};

CREATE TABLE {$post_tr} (
    id              bigint unsigned NOT NULL AUTO_INCREMENT,
    parent_id       bigint unsigned NOT NULL,
    lang_code       varchar(10) NOT NULL,
    translated_text longtext NOT NULL,
    status          tinyint unsigned NOT NULL DEFAULT 1,
    provider        varchar(50) NULL,
    created_at      datetime NOT NULL,
    updated_at      datetime NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uniq_translation (parent_id, lang_code),
    KEY idx_lang_status (lang_code, status),

    CONSTRAINT {$fk_post_tr_parent}
        FOREIGN KEY (parent_id)
        REFERENCES {$post} (id)
        ON DELETE CASCADE
) ENGINE=InnoDB {$charsetCollate};
```

Valid `field` values must come from a code-level allow-list, for example:
`post_title`, `post_content`, `post_excerpt`, and the attachment fields supported
by TransLangs.

### 3.2 Meta — `wp_tl_meta` / `wp_tl_meta_translation`

Parent-specific columns and identity:

```sql
post_id       bigint unsigned NOT NULL,
meta_key      varchar(191) NOT NULL,
meta_key_hash binary(32) NOT NULL,
context       text NULL,
context_hash  binary(32) NOT NULL,
original_hash binary(32) NOT NULL,
original_text longtext NOT NULL,
status        tinyint unsigned NOT NULL DEFAULT 0,

UNIQUE KEY uniq_source
    (post_id, meta_key_hash, context_hash, original_hash)

FOREIGN KEY (post_id)
    REFERENCES {$wpdb->posts} (ID)
    ON DELETE CASCADE
```

The child is identical in shape to `{$post_tr}`:

```text
id, parent_id, lang_code, translated_text, status, provider,
created_at, updated_at
```

with:

```sql
UNIQUE KEY uniq_translation (parent_id, lang_code)
```

### 3.3 Term — `wp_tl_term` / `wp_tl_term_translation`

Parent-specific columns and identity:

```sql
term_taxonomy_id bigint unsigned NOT NULL,
field            varchar(32) NOT NULL,
original_hash    binary(32) NOT NULL,
original_text    longtext NOT NULL,
status           tinyint unsigned NOT NULL DEFAULT 0,

UNIQUE KEY uniq_source
    (term_taxonomy_id, field, original_hash)

FOREIGN KEY (term_taxonomy_id)
    REFERENCES {$wpdb->term_taxonomy} (term_taxonomy_id)
    ON DELETE CASCADE
```

The child uses the common child shape and
`UNIQUE (parent_id, lang_code)`.

### 3.4 Static text — `wp_tl_staticText` / `wp_tl_staticText_translation`

Version 5 preserves the existing static text identity decision:

```sql
source         varchar(20) NOT NULL,
reference      varchar(191) NOT NULL,
reference_hash binary(32) NOT NULL,
original_hash  binary(32) NOT NULL,
original_text  longtext NOT NULL,
status         tinyint unsigned NOT NULL DEFAULT 0,

UNIQUE KEY uniq_source
    (source, reference_hash, original_hash)
```

There is no WordPress content FK. The child uses the common child shape and
`UNIQUE (parent_id, lang_code)`.

### 3.5 Rendered HTML — `wp_tl_html` / `wp_tl_html_translation`

Version 5 preserves selector-based identity:

```sql
selector      varchar(255) NOT NULL,
selector_hash binary(32) NOT NULL,
source_url    text NULL,
original_hash binary(32) NOT NULL,
original_text longtext NOT NULL,
status        tinyint unsigned NOT NULL DEFAULT 0,

UNIQUE KEY uniq_source
    (selector_hash, original_hash)
```

There is no WordPress content FK. The child uses the common child shape and
`UNIQUE (parent_id, lang_code)`.

`selector_hash` is calculated from the complete selector and avoids uniqueness
based on a truncated prefix. `selector` remains readable for administration.

---

## 4. Keys and context-safe lookup

| Pair | Parent unique identity | Child unique identity |
|---|---|---|
| post | `(post_id, field, original_hash)` | `(parent_id, lang_code)` |
| meta | `(post_id, meta_key_hash, context_hash, original_hash)` | `(parent_id, lang_code)` |
| term | `(term_taxonomy_id, field, original_hash)` | `(parent_id, lang_code)` |
| staticText | `(source, reference_hash, original_hash)` | `(parent_id, lang_code)` |
| html | `(selector_hash, original_hash)` | `(parent_id, lang_code)` |

There is deliberately no child index on `(lang_code, original_hash)` because:

- `original_hash` is not stored in the child;
- text hash alone is not contextual identity;
- the parent must be read to enforce `status = discovered`.

### 4.1 Representative post render query

```sql
SELECT
    p.id AS parent_id,
    tr.translated_text,
    tr.status AS translation_status
FROM {$post} AS p
INNER JOIN {$post_tr} AS tr
    ON tr.parent_id = p.id
   AND tr.lang_code = %s
WHERE p.post_id = %d
  AND p.field = %s
  AND p.original_hash = %s
  AND p.status = 0
LIMIT 1;
```

The same rule applies to the other source types: query the complete parent
identity, require `parent.status = 0`, then join the requested language child.

### 4.2 Batch rendering

Do not run one query per string. On a cache miss:

1. collect visible identities by source type;
2. de-duplicate them in PHP;
3. issue at most one batched parent→child query per type, chunked when needed;
4. index returned rows in memory by the same full contextual identity;
5. populate the persistent object/Redis cache;
6. apply replacements.

A cache key must contain the source type, target language code, and complete
contextual identity. Conceptually:

```text
tl:v5:{type}:{lang_code}:{identity_digest}
```

`identity_digest` may be a cache-only hash over the complete parent identity. It
is not stored as the database's authoritative identity.

---

## 5. Runtime flows

### 5.1 Discovery

Write the parent only. Use an explicit upsert rather than relying on warnings or
silent coercion:

```sql
INSERT INTO {$post}
    (post_id, field, original_hash, original_text, status, created_at, updated_at)
VALUES
    (?, ?, ?, ?, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE
    original_text = VALUES(original_text),
    updated_at    = VALUES(updated_at);
```

Discovery does not change language options and creates no child rows.

### 5.2 Translation

First resolve the parent by full identity or parent ID. Then upsert:

```sql
INSERT INTO {$post_tr}
    (parent_id, lang_code, translated_text, status, provider,
     created_at, updated_at)
VALUES
    (?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE
    translated_text = VALUES(translated_text),
    status          = VALUES(status),
    provider        = VALUES(provider),
    updated_at      = VALUES(updated_at);
```

### 5.3 Ignore and unignore

To ignore a source:

```sql
UPDATE {$post}
SET status = 3, updated_at = UTC_TIMESTAMP()
WHERE id = ?;
```

Children are not required to be deleted. Rendering always requires
`parent.status = 0`, so an ignored source renders the original text even if old
translations remain. Unignoring can restore those translations immediately.

### 5.4 Add or disable a language

- **Add or re-enable:** add the code to `translangs_active_lang` and append it
  to `translangs_existing_lang` if it is not already present; create no content
  rows.
- **Disable:** remove the code from `translangs_active_lang`; keep it in
  `translangs_existing_lang`; retain every child row for that `lang_code`.
- Translation content is not deleted solely because a language is disabled.

### 5.5 Delete or update source content

- Deleting a WordPress post or term-taxonomy row cascades into its parent rows,
  then children cascade through `parent_id`.
- Changing source content creates or updates the current parent identity and
  prunes stale identities according to the source repository's lifecycle.

### 5.6 Post-meta lifecycle

For allow-listed translatable keys, handle all three mutation hooks:

```text
added_post_meta
updated_post_meta
deleted_post_meta
```

`update_post_meta()` can create a missing row, so handling only updated and
deleted events misses the add path.

Each handler must:

1. reject non-allow-listed keys early;
2. normalize and rediscover the current value;
3. remove stale parent identities for that logical meta context;
4. invalidate contextual caches;
5. use a re-entrancy guard if repository actions can write metadata.

WordPress permits repeated rows with the same `post_id` and `meta_key`. For a
repeated key, `context` must deterministically identify the logical value.
Unstable array position alone must not be used as persistent identity. If a
stable context cannot be derived, that key should be excluded from automatic
translation or processed through a key-specific adapter.

### 5.7 Administration

For one selected target language:

```sql
SELECT p.*, tr.id AS translation_id, tr.translated_text, tr.status
FROM {$post} AS p
LEFT JOIN {$post_tr} AS tr
    ON tr.parent_id = p.id
   AND tr.lang_code = ?
WHERE ...;
```

Interpretation:

- child present: translated, reviewed, or needs review according to child status;
- child absent and parent discovered: needs translation;
- parent ignored: excluded from normal work queues or shown in an ignored view.

---

## 6. Migration from the five self-contained tables

Migration is a controlled cutover, not a single `INSERT ... SELECT` executed
inside an activation request.

### 6.1 Parent status conversion

Legacy per-language ignore values cannot safely become a global ignore merely
because one row was ignored. For one contextual source group:

- set parent `status = 3` only when **all** legacy rows are ignored and there is
  **no real translation** in the group;
- otherwise set parent `status = 0`;
- record mixed ignored/translated groups in a migration-conflict report for
  review.

Equivalent rule:

```sql
CASE
    WHEN translated_count = 0
     AND ignored_count = row_count
    THEN 3
    ELSE 0
END
```

### 6.2 Canonical source text

Do not calculate `MIN(original_text)` over `LONGTEXT`. Build grouped staging
rows with a canonical source row ID, for example `MIN(id)`, then join back to the
legacy table to copy the exact `original_text`, timestamps, and identity values
from that row.

### 6.3 Language code validation

Before children are inserted:

1. extract distinct legacy target codes;
2. normalize and validate every code against the supported-language catalog;
3. ensure retained codes exist in `translangs_existing_lang`;
4. reject children whose code is invalid or empty.

### 6.4 Do not use `INSERT IGNORE` for migration

Migration must distinguish an expected duplicate from invalid or truncated
data. Use:

- staging tables with explicit unique keys;
- pre-insert duplicate checks;
- strict SQL mode where available;
- normal `INSERT` statements that fail the batch on unexpected errors;
- a conflict table or structured log for rejected rows.

Runtime idempotence may use explicit `ON DUPLICATE KEY UPDATE`; authoritative
migration must not silently discard rows.

### 6.5 Cutover protocol

1. **Preflight**
   - verify InnoDB and required privileges;
   - verify core-table column compatibility;
   - calculate table names and FK names;
   - verify sufficient free storage.
2. **Create new tables**
   - create five parents, then five children;
   - track each successfully created object.
3. **Enable write control**
   - enable dual-write to old and new schemas, or temporarily pause translation
     writes;
   - capture a legacy high-water mark.
4. **Backfill in batches**
   - group and insert parents;
   - resolve `parent_id`, validate `lang_code`, and insert children;
   - commit each bounded batch.
5. **Catch up**
   - replay rows written after the high-water mark;
   - compare old/new writes while dual-write is active.
6. **Validate**
   - counts, uniqueness, hashes, language-code validation, FK integrity,
     ignored-state conversion, and sampled translated text;
   - ensure the conflict report is empty or explicitly accepted.
7. **Atomic application cutover**
   - update one schema-version/active-schema option;
   - stop reads from the legacy tables;
   - keep dual-write briefly if rollback-at-application-level is desired.
8. **Retention**
   - keep old tables read-only for one release;
   - drop them only in a later explicit cleanup migration.

### 6.6 DDL failure handling

Do not describe table creation as transactionally rollbackable. DDL must use
compensating cleanup:

1. record every object created by this migration run;
2. on failure, drop only newly created child tables first, then parent tables,
   if they were created by this run;
3. never modify or drop the legacy production tables before validation and
   cutover succeed.

### 6.7 Batch boundaries

Batch by stable numeric ranges such as legacy row ID, `post_id`, or
`term_taxonomy_id`. A typical initial target is around 5,000 source groups per
batch, adjusted using observed row size and I/O. Run large migrations through
Action Scheduler or WP-CLI, never synchronously inside a normal admin request.

---

## 7. Validation requirements

Validate each source type separately.

### Structural

- every explicit FK name is unique in the database schema;
- every child has a valid parent and non-empty target language code;
- no duplicate parent natural identities;
- no duplicate `(parent_id, lang_code)` pairs.

### Data

- parent count equals distinct legacy contextual source identities;
- child count equals accepted legacy rows with a real translation;
- every migrated language code is valid and retained in the existing-language
  option roster;
- parent status follows the all-ignored/no-translation rule;
- mixed legacy status groups appear in the conflict report;
- sampled exact original and translated text matches the legacy source.

### Runtime

- identical text in different contexts resolves independently;
- ignored parents never return translations;
- unignoring restores retained children;
- a newly added language creates zero content rows;
- add/update/delete post-meta paths rediscover and prune correctly.

---

## 8. Trade notes

- Rendering now performs an indexed parent→child join instead of an unsafe
  child-only hash lookup. Correct contextual identity takes precedence over a
  micro-optimization.
- The renderer should batch by type and use persistent caching, so the join is
  paid mainly on cache misses.
- Ten content tables increase repository and migration surface area, but each
  source type retains readable, type-specific identity.
- `ignored` remains intentionally global to a source identity, not per language.
- The primary operational benefit remains: adding a target language creates zero
  parent or child rows.
- Option-backed language codes keep disabled-language translations available
  without deleting translation content.
