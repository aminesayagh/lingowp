# Plan — Split rendered-HTML out of `_i18n` into `_html`

> Focused migration. Everything else is already implemented. Goal: move the
> rendered-HTML fallback rows currently mixed into `wp_tl_translations_i18n`
> into the new `wp_tl_translations_html` table, leave `_i18n` as gettext-only,
> and redirect the fallback code path to the new table.

---

## Pre-check: how to tell the two kinds apart

In the combined table, rendered-HTML rows were written with the discriminator
`source = 'rendered_html'` (and the old structural selector lived in the
`reference` column). gettext rows used `source IN ('plugin','theme','core')`.

**Confirm this before migrating** — it's the whole basis of the split:

```sql
SELECT source, COUNT(*) FROM wp_tl_translations_i18n GROUP BY source;
```

- If `rendered_html` rows are cleanly labelled → the split is a clean
  `WHERE source='rendered_html'`. Proceed.
- If the discriminator is missing/unreliable → STOP; we need a different rule
  (e.g. "reference looks like a selector `a>b>c`, not a file path") before
  migrating, or risk moving gettext rows by mistake.

---

## Step 1 — Create `wp_tl_translations_html`

Raw `$wpdb->query()` (same pattern as the other tables), InnoDB, utf8mb4, FK to
`wp_tl_languages(code)` CASCADE, verify the FK in `information_schema`, roll back
on failure. Final minimal shape:

```sql
CREATE TABLE {$html} (
    id              bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    target_lang     varchar(10)  NOT NULL,
    selector        varchar(255) NOT NULL,            -- 'header>nav>a' (keyed directly, no hash)
    original_hash   binary(32)   NOT NULL,
    original_text   longtext     NOT NULL,
    translated_text longtext     NULL,
    status          tinyint(3) unsigned NOT NULL DEFAULT 0,
    provider        varchar(50)  NULL,
    source_url      text         NULL,                -- last URL only
    created_at      datetime     NOT NULL,
    updated_at      datetime     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_identity (target_lang, selector(100), original_hash),
    KEY idx_lang_status (target_lang, status),
    KEY idx_same_original (target_lang, original_hash),
    CONSTRAINT fk_html_lang
        FOREIGN KEY (target_lang) REFERENCES {$languages} (code) ON DELETE CASCADE
) ENGINE=InnoDB {$charsetCollate};
```

Bump DB version; gate this behind `maybeUpgrade()` so it runs once.

---

## Step 2 — Migrate the rendered-HTML rows across

Copy the `rendered_html` rows from `_i18n` into `_html`, mapping columns:

| `_i18n` (old) | `_html` (new) | note |
|---|---|---|
| `reference` | `selector` | the structural selector was stored in `reference` |
| `original_hash` | `original_hash` | unchanged |
| `original_text` | `original_text` | unchanged (inline, kept) |
| `translated_text` | `translated_text` | preserve existing translations |
| `target_lang`, `status`, `provider`, timestamps | same | preserve |
| `source`, `reference_hash` | — | dropped (no longer needed) |
| (none) | `source_url` | NULL on migrate; repopulated on next observation |

```sql
INSERT IGNORE INTO {$html}
    (target_lang, selector, original_hash, original_text, translated_text,
     status, provider, source_url, created_at, updated_at)
SELECT
    target_lang,
    reference        AS selector,
    original_hash,
    original_text,
    translated_text,
    status,
    provider,
    NULL             AS source_url,
    created_at,
    updated_at
FROM {$i18n}
WHERE source = 'rendered_html';
```

`INSERT IGNORE` so a re-run is idempotent (the new unique key dedupes). If the
old `reference` exceeded 255 chars for any row, check for truncation first
(selectors should be short, but verify):

```sql
SELECT COUNT(*) FROM {$i18n} WHERE source='rendered_html' AND CHAR_LENGTH(reference) > 255;
-- expect 0
```

---

## Step 3 — Validate the copy

```sql
-- counts match
SELECT COUNT(*) FROM {$i18n} WHERE source='rendered_html';   -- A
SELECT COUNT(*) FROM {$html};                                 -- should equal A
                                                              -- (or A minus any dupes the unique key collapsed)
```

Spot-check a few rows: selector, text, and translation carried over intact. Only
proceed to Step 4 once counts reconcile.

---

## Step 4 — Delete the migrated rows from `_i18n`

Only after Step 3 passes:

```sql
DELETE FROM {$i18n} WHERE source = 'rendered_html';
```

`_i18n` is now gettext-only. (Optional: if `source` can now only be
plugin/theme/core, no schema change is required — the column simply no longer
sees `rendered_html`.)

---

## Step 5 — Redirect the code path

Wherever the fallback insert currently writes to `_i18n` with
`source='rendered_html'`, point it at the new `_html` table instead:

- **Repository:** add `HtmlTranslationRepository` (or a route in the existing
  repo) writing `(target_lang, selector, original_hash, original_text, status,
  provider, source_url)`. Drop the `source`/`reference_hash` handling for this path.
- **Discovery/worker (Tier-2 fallback insert):** the "else → rendered_html
  fallback" branch now inserts into `_html`, not `_i18n`.
- **Selector:** keep building `{landmark}>{intermediate}>{tag}` and write it to
  `selector` directly (no `reference`, no hashing).
- **Observation:** upsert `source_url` (last URL) on the `_html` row in the
  background worker.

---

## Step 6 — Redirect the render preload

- Add `getHtmlTranslations(targetLang, selectorsOnPage)` returning the map for
  rendered-HTML rows from `_html`.
- Keep `getI18nTranslations(...)` reading `_i18n` (gettext only) — it no longer
  needs to filter out `rendered_html`.
- Replacement priority unchanged: owned (post/postmeta/terms) → `_i18n` gettext
  → `_html` fallback.

---

## Step 7 — RowRef + admin + uninstall

- **RowRef:** add `html` to the enum (`post|postmeta|terms|i18n|html`) so admin
  save/review/ignore/reset can address `_html` rows.
- **Admin list:** add `_html` to the UNION across translation tables.
- **uninstall.php / drop order:** include `_html` in the reverse-order drop
  (before `wp_tl_languages`).

---

## Step 8 — Test (manual, local WordPress)

- New `_html` table created with FK; activation still clean / rolls back on FK fail.
- Migration: every `source='rendered_html'` row now in `_html` with selector +
  text + translation intact; `_i18n` has none left; counts reconcile.
- Re-running the migration inserts nothing new (idempotent).
- Fallback discovery now writes to `_html`; gettext still writes to `_i18n`.
- Render: a chrome string translated pre-migration still renders translated
  post-migration (proves the preload reads `_html`).
- Admin: `_html` rows are listable/editable via RowRef.

---

## Notes / trades (carried from the decision)

- `_html` keys on `selector` directly (short; `selector(100)` prefix), no
  `selector_hash`.
- Only `source_url` observation — no `seen_count`/`first_seen_at`/`last_seen_at`.
  Trade: no auto dead-chrome detection; manual prune only.
- Migration sets `source_url` NULL; it repopulates naturally on the next render
  observation, so no data is truly lost (URL wasn't part of identity anyway).
- If the `source='rendered_html'` discriminator is NOT reliable in the current
  data (pre-check), do not run Step 2 as-is — resolve the classification rule first.
