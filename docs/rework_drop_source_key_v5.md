# TransLangs — Rework v5: packed `source_key` → contextual parent/child tables

> Replaces the v4 polymorphic table keyed by a packed `source_key` with the v5
> parent/child architecture defined in `parent_child_restructure_v5.md`.
> `source_key` is removed as database identity. Version 5 also corrects render
> semantics, source-ignore behavior, option-backed language-code storage,
> migration recoverability, post-meta hooks, and production cutover safety.

---

## 1. Why remove the packed key and single table

The v4 design packed source type, owner, reference digest, and a truncated text
hash into one `BINARY(32)` value:

```text
[type:1][owner_id:8 big-endian][H(ref1|ref2):8][text_hash:15]
```

The design is being reversed because:

- the packed key is opaque during debugging and operations;
- every query depends on packing rules that are easy to misuse;
- its hashed segments cannot be reversed into readable identity columns;
- its text-hash segment stores only 15 bytes, not the full new
  `BINARY(32)` source hash;
- type-specific indexed tables already provide efficient seeks;
- hot rendering should be protected by page/object caching rather than an
  under-contextualized database key;
- adding a target language should not fan out rows for every discovered source.

The target therefore uses:

- five readable parent tables for contextual source identities;
- five child tables for translations that actually exist;
- no language dictionary table; children store target language codes directly;
- contextual parent→child rendering;
- no authoritative packed identity.

---

## 2. v5 target identities

| Pair | Parent identity | Child identity |
|---|---|---|
| post | `(post_id, field, original_hash)` | `(parent_id, lang_code)` |
| meta | `(post_id, meta_key_hash, context_hash, original_hash)` | `(parent_id, lang_code)` |
| term | `(term_taxonomy_id, field, original_hash)` | `(parent_id, lang_code)` |
| staticText | `(source, reference_hash, original_hash)` | `(parent_id, lang_code)` |
| html | `(selector_hash, original_hash)` | `(parent_id, lang_code)` |

Key consequences:

- parents are language-agnostic;
- children contain `lang_code`, translated text, translation status, provider,
  and timestamps;
- children do **not** copy `original_hash`;
- render never searches a child using only language and text hash;
- `ignored` remains on the parent and is enforced by every render query;
- adding a language updates WordPress options and creates zero content rows.

### Language configuration

Do not add a `tl_languages` table. Keep the repository's current
option-backed language model:

```text
translangs_active_lang    fr|ar      currently enabled target codes
translangs_existing_lang  fr|ar|es   every target code ever added
```

Child rows store the target code directly as `lang_code`. Disabling a language
removes it from `translangs_active_lang` only; `translangs_existing_lang` remains
append-only and translation content is retained. There is no hard-delete or
language cleanup requirement in this v5 plan.

The legacy `translangs_enabled_languages` option may only seed the two current
rosters when both are empty. Display metadata comes from `SupportedLanguages` by
code. The source language remains the normalized WordPress locale from
`get_locale()` and is never stored as a target.

---

## 3. Recoverability of the v4 packed key

### 3.1 Clean migration requirements

A clean migration is possible only when each v4 row still retains:

- every readable identity column required by its destination parent;
- the full raw `BINARY(32)` `original_hash`;
- exact `original_text`;
- target language code;
- translated text and status when present;
- timestamps and provider where required.

Migrate from these readable columns. Do not attempt to derive them from the
packed key.

### 3.2 What the packed key alone can and cannot recover

The raw `owner_id` segment can narrow candidate ownership for content-backed
sources, but it does not reconstruct the complete v5 identity.

| Type | Recoverable from packed key | Missing for exact v5 identity |
|---|---|---|
| post | type, owner ID, short reference digest, truncated text digest | `field`, full `original_hash` |
| meta | type, owner ID, short reference digest, truncated text digest | `meta_key`, `meta_key_hash`, `context`, `context_hash`, full `original_hash` |
| term | type, owner ID, short reference digest, truncated text digest | `field`, full `original_hash` |
| staticText | type and digests | readable `source`/`reference`, full `original_hash` |
| html | type and digests | readable `selector`, full `original_hash` |

Therefore, if v4 retained only `source_key` plus text/translation values,
**none of the five source types is perfectly reconstructable from the key
alone**. Post and term owner IDs are useful hints, not complete identity.

### 3.3 Rediscovery-assisted migration

When identity columns are missing:

1. create the v5 schema without importing ambiguous parents;
2. run structured rediscovery for post, meta, term, staticText, and HTML;
3. generate complete v5 parent identities and full hashes from current content;
4. attempt conservative legacy matching using available type, owner ID, exact
   original text, target language, and any retained readable context;
5. attach a legacy translation only when exactly one current parent matches;
6. write zero-match and multi-match cases to a conflict table/report;
7. never assign an ambiguous translation arbitrarily.

A re-discovered source can differ from its historical version. Such rows require
manual review or can remain in the retained legacy backup.

---

## 4. Migration mapping

The type mapping remains:

```text
1 post     → tl_post / tl_post_translation
2 meta     → tl_meta / tl_meta_translation
3 term     → tl_term / tl_term_translation
4 staticText → tl_staticText / tl_staticText_translation
5 html     → tl_html / tl_html_translation
```

Before migrating children:

1. extract distinct valid legacy target codes;
2. normalize and validate them against the supported-language catalog;
3. ensure retained codes exist in `translangs_existing_lang`;
4. reject invalid or empty codes into the conflict report.

---

## 5. Parent migration rules

### 5.1 Canonical row selection

Do not use `MIN(original_text)` to select a value from `LONGTEXT`. For each full
contextual identity, stage:

```text
canonical_legacy_id = MIN(id)
row_count
ignored_count
translated_count
created_at_min
updated_at_max
```

Then join the staged `canonical_legacy_id` back to v4 to copy exact identity and
source text columns.

### 5.2 Converting legacy ignore state

The old status is per language, while v5 ignore state is global to the source.
Do not mark the parent ignored merely because any one legacy row was ignored.

Use:

```text
parent.status = ignored
only when ignored_count = row_count AND translated_count = 0
```

Otherwise use `discovered`. If ignored and translated legacy rows coexist in one
source group, record a migration conflict.

### 5.3 Explicit insert behavior

Authoritative migration must not use `INSERT IGNORE`. Stage and validate
expected uniqueness, then use a normal insert. Unexpected duplicates, invalid
values, truncation, missing parents, or invalid language codes must fail the
current batch and be reported.

Runtime discovery may use an explicit upsert:

```sql
INSERT ...
ON DUPLICATE KEY UPDATE
    original_text = VALUES(original_text),
    updated_at = VALUES(updated_at);
```

---

## 6. Child migration rules

A child is inserted only for a real accepted translation:

```text
translated_text is not null
legacy status is not ignored
parent_id resolved uniquely
lang_code is valid and non-empty
```

The v5 child contains:

```text
parent_id
lang_code
translated_text
status
provider
created_at
updated_at
```

It does not contain the parent's source hash.

Representative shape:

```sql
INSERT INTO {$post_tr}
    (parent_id, lang_code, translated_text, status, provider,
     created_at, updated_at)
SELECT
    p.id,
    v.target_lang,
    v.translated_text,
    v.status,
    v.provider,
    v.created_at,
    v.updated_at
FROM {$validated_v4_post_rows} AS v
INNER JOIN {$post} AS p
    ON p.post_id = v.post_id
   AND p.field = v.field
   AND p.original_hash = v.original_hash;
```

The validated staging source must contain at most one accepted row for each
`(parent identity, target language code)` pair. Conflicts are resolved before this
statement is executed.

---

## 7. Production cutover protocol

Run the following as a controlled migration procedure.

### Phase A — preflight

- derive table names and FK names;
- verify storage engine, privileges, core-column compatibility, and free space;
- inspect whether v4 has the readable columns required for clean migration;
- choose clean backfill or rediscovery-assisted migration.

### Phase B — create v5 schema

Create in dependency order:

1. five parents;
2. five children.

Track every successfully created object. DDL is not treated as transactionally
rollbackable.

### Phase C — control concurrent writes

Choose one:

- enable dual-write to v4 and v5; or
- pause translation/discovery writes during the migration window.

Capture a v4 high-water mark after write control is active.

### Phase D — backfill

- batch by stable legacy ID or owner ranges;
- stage canonical parent groups;
- insert validated parents;
- resolve parent IDs and validate language codes;
- insert validated children;
- commit each bounded batch;
- store the last completed batch cursor.

Large migrations should run through Action Scheduler or WP-CLI rather than a
normal admin request.

### Phase E — catch up

Replay or compare all writes after the high-water mark. Do not cut over while
v4 contains accepted rows absent from v5.

### Phase F — validation

At minimum validate:

- distinct parent identity counts;
- accepted child counts;
- no duplicate `(parent_id, lang_code)`;
- every child has a parent and a valid target language code;
- source and translated text samples match exactly;
- ignored conversion follows the all-ignored/no-translation rule;
- conflict report is empty or explicitly accepted;
- context-collision tests return different translations correctly;
- ignored parents return no translation.

### Phase G — application cutover

Switch one versioned plugin option, for example:

```text
tl_active_schema = 5
```

After the switch:

- reads use only v5;
- cache namespaces use `v5`;
- old tables become read-only;
- optional dual-write may continue briefly for application-level rollback.

### Phase H — retention and cleanup

Keep v4 tables for one release. Drop them only in a later explicit cleanup after
backup and operational confirmation.

### Compensating cleanup on schema-creation failure

If v5 creation fails before cutover:

1. drop only child tables created by this migration run;
2. drop only parent tables created by this run;
3. leave every v4 table untouched.

---

## 8. Runtime code changes

### Remove

- source-key packer/unpacker;
- database queries by packed key;
- child lookup by `(target_lang, original_hash)`;
- copied `original_hash` in children;
- any planned `tl_languages` table or numeric language lookup layer.

### Add

- continued use of the existing `LanguageRegistry` options for active and
  existing target codes;
- per-type parent repositories using readable contextual identity;
- child repositories keyed by `(parent_id, lang_code)`;
- render queries joining the contextual parent to its selected child and
  requiring `parent.status = 0`;
- batched rendering by type;
- cache keys containing source type, language code, and the complete
  contextual identity digest;
- migration high-water mark, cursor, conflict report, and schema-version state.

### Post-meta lifecycle

Process allow-listed keys on all of:

```text
added_post_meta
updated_post_meta
deleted_post_meta
```

Rediscover the current logical value, prune stale hashes, invalidate contextual
cache keys, and use a re-entrancy guard. Repeated meta keys require a stable,
key-specific context; otherwise exclude them from generic automatic handling.

---

## 9. Render behavior after migration

Representative post lookup:

```sql
SELECT tr.translated_text
FROM {$post} AS p
INNER JOIN {$post_tr} AS tr
    ON tr.parent_id = p.id
   AND tr.lang_code = ?
WHERE p.post_id = ?
  AND p.field = ?
  AND p.original_hash = ?
  AND p.status = 0
LIMIT 1;
```

This resolves both v4 problems:

- identical text in different contexts does not cross-match;
- an ignored parent cannot render an existing child translation.

Per page, collect identities and issue one batched query per source type on
cache misses. The persistent cache remains the hot path, but the database schema
is correct without assuming the cache is always present.

---

## 10. Decisions retained from previous versions

- ten content tables: five parent + five child;
- full `BINARY(32)` hashes in parents;
- `original_text` stored once in the parent;
- hard foreign keys, including content FKs, with activation preflight;
- translation child rows use target language codes directly and are created only
  when translations exist;
- zero content rows when a language is added;
- Action Scheduler/WP-CLI for large migration and discovery work;
- rendered HTML as the fallback source type;
- existing staticText and selector identity decisions, except that rendering now uses
  the complete parent identity.

---

## 11. Net result

Version 5 keeps the operational benefits of the parent/child model while
removing the unsafe hash-only render path. The database now expresses the real
contextual source identity, parent ignore status is authoritative, language
references remain readable option-backed codes, and migration is explicit about
both irrecoverable packed-key information and production cutover safety.
