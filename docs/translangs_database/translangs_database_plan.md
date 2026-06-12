# TransLangs Database v4 Implementation Steps

## Summary

This document breaks the v4 database refactor into ordered implementation steps. The goal is to move the plugin from the current
split translation tables to the frozen v4 standard using a clean reset, with no migration of existing translation memory data.

Each step should leave the codebase in a reviewable state. Do not start later runtime/admin work until the v4 identity and schema
contracts are stable.

## Step-by-Step Plan

### 1. Add The Source Key Contract

Implement the single TL_Key chokepoint first.

Deliverables:

- Add a key builder for all five source types:
    - post
    - postmeta
    - terms
    - i18n/gettext
    - rendered HTML fallback

- Add deterministic normalization used by both discovery and render paths.
- Generate binary(32) keys with layout:
    - 1 byte type
    - 8 byte big-endian owner ID
    - 8 byte hash of ref1|ref2
    - 15 byte original text hash prefix

- Enforce source shape rules in PHP.
- Enforce HTML selector max length of 100 characters.
- Make inline key construction forbidden outside this class.

Acceptance:

- Every source identity can produce exactly one stable key.
- Same input always produces same key.
- Invalid type/shape combinations fail before DB writes.

### 2. Replace The Database Schema

Replace the old split-table schema with v4 tables.

Deliverables:

- Replace table definitions with:
    - tl_source
    - tl_translation

- Remove creation of:
    - tl_languages
    - tl_translations_post
    - tl_translations_postmeta
    - tl_translations_terms
    - tl_translations_i18n
    - tl_translations_html

- Add raw $wpdb->query() DDL only.
- Add FKs:
    - fk_src_post
    - fk_src_tt
    - fk_tr_source

- Add indexes exactly as v4 specifies.
- Update uninstall cleanup for the new tables and new options.
- For clean reset, drop old translation-memory tables during upgrade/activation cleanup.

Acceptance:

- Activation creates only the v4 translation memory tables.
- dbDelta() is not used.
- FK verification checks the v4 constraints only.
- Old split-table helpers are no longer the active schema contract.

### 3. Update Activation And Diagnostics

Make activation enforce the v4 prerequisites.

Deliverables:

- Check wp_posts engine before table creation.
- Check wp_term_taxonomy engine before table creation.
- Abort activation with a clear wp_die() message if either parent table is not InnoDB.
- Roll back created v4 tables if any FK is missing.
- Add Site Health diagnostics for:
    - parent table engines
    - v4 FK existence
    - deferred index recommendation
    - last type 4/5 cleanup run

Acceptance:

- Activation failure is specific and human-readable.
- Partial v4 tables are removed after failed activation.
- Diagnostics report the real DB integrity state.

### 4. Replace Language And Provider Storage

Move language/provider metadata out of SQL tables.

Deliverables:

- Replace DB-backed language registry with translangs_languages option.
- Store:
    - next_lang_id
    - language entries with lang_id, code, name, native_name, direction, status, sort_order

- Ensure lang_id is never reused.
- Replace hard deletes with:
    - enqueue cleanup first
    - remove config entry after cleanup is scheduled

- Add translangs_providers option with the same monotonic ID pattern.
- Update settings/admin code to use language codes for display but lang_id for translation storage.

Acceptance:

- Render and repository code can resolve code → lang_id.
- Deleted language IDs are never reused.
- No runtime query is required to load language metadata.

### 5. Replace The Persistence Layer

Replace per-type repositories with a v4 repository.

Deliverables:

- Add source discovery methods:
    - insert/update source rows by source_key
    - update last-seen timestamps for types 4/5
    - delete stale source rows for posts/postmeta/terms

- Add translation methods:
    - save translation by source_key + lang_id
    - update translation status
    - delete translation rows when source is ignored
    - fetch translations by key batch

- Replace row-id/table routing with source-key based references.
- Remove active dependency on SourceRoute table names for DB writes.
- Keep repository methods direct and feature-local.

Acceptance:

- Source discovery does not create placeholder translation rows.
- A translation row exists only when translated text exists.
- Ignoring a source deletes its translation children.
- Save creates or updates one (source_key, lang_id) row.

### 6. Rebuild Structured Discovery

Update save-time discovery for owned WordPress content.

Deliverables:

- On wp_after_insert_post, scan post fields and allowlisted postmeta.
- Compute v4 keys for current post source strings.
- Insert current source rows.
- Delete stale source rows for that post whose keys no longer exist.
- Keep postmeta allowlist behavior.
- Add term discovery using created_term and edited_term.
- Use term_taxonomy_id for term keys.

Acceptance:

- Editing a post removes stale source rows and cascades translations.
- Existing current strings are idempotently rediscovered.
- Postmeta remains default-deny.
- Term name/description are discoverable.

### 7. Rebuild Runtime Resolution

Move render replacement to identity-aware hooks.

Deliverables:

- Register render hooks for:
    - the_title
    - the_content
    - get_the_excerpt
    - the_posts
    - get_post_metadata
    - get_term
    - get_terms
    - get_the_terms
    - gettext hooks

- Each hook:
    - normalizes incoming text
    - computes source_key
    - records request-local owned hash
    - returns translated text from preloaded map or original

- Preload translations in batches by computed source keys.
- Add negative caching for misses.
- Avoid per-hook DB queries.
- Avoid recursive get_post_meta() calls inside metadata filters.

Acceptance:

- Structured content is replaced before HTML fallback runs.
- Missing translations render original text.
- Owned untranslated structured text is still protected from HTML fallback.
- Runtime lookup uses computed keys, not text-only hash replacement.

### 8. Rework HTML Fallback

Keep output buffering only as the fallback layer.

Deliverables:

- Start output buffer for eligible frontend translated requests.
- Parse final HTML after structured hooks have run.
- Skip excluded locations:
    - data-tl-skip
    - translate="no"
    - .notranslate
    - script/style/code/pre/form-sensitive zones

- Skip text by content filters:
    - no alphabetic characters
    - date/time/currency/url/email/phone-like values
    - low letter ratio

- Check request-local owned hashes.
- For unowned text, compute type-5 source keys.
- Resolve from per-URL HTML map.
- Queue discovery candidates for deferred insert.
- Add hard cap for new type-5 rows per request.

Acceptance:

- HTML fallback never replaces structured-owned strings.
- Dynamic/noisy text is not captured.
- Fallback discovery cannot create unbounded rows on one request.

### 9. Update Gettext Handling

Convert gettext capture/resolution to v4.

Deliverables:

- Compute type-4 source keys from domain/context/reference and original hash.
- Capture singular and plural forms independently.
- Replace gettext strings from preloaded/lazy translation map.
- Insert unknown gettext sources through deferred discovery.
- Track last-seen with throttled updated_at updates.

Acceptance:

- Gettext hooks return translated text when available.
- Plural forms are independent source strings.
- Unknown gettext strings are discovered without blocking the page response.

### 10. Rebuild Admin Translation Management

Update the admin UI and actions around source_key.

Deliverables:

- Replace split-table UNION ALL query with:
  - tl_source LEFT JOIN tl_translation
  - selected lang_id
  - keyset pagination

- Use source status from tl_source.
- Use translation status from tl_translation.
- Treat NULL translation row as untranslated.
- Update save/review/needs-review actions to target source_key + lang_id.
- Update ignore action:
  - mark source ignored
  - delete child translations

- Update reset ignored:
  - mark source discovered
  - leave translations absent

- Update language siblings query to scan all translations for one source_key.
- Replace row refs like post:123 with source-key based handles.

Acceptance:

- Admin queue lists untranslated sources even with no translation row.
- Pagination does not use OFFSET.
- Editor actions target exact source identity.
- Ignored sources render original by child-row absence.

### 11. Update Marking Mode And Focus Tools

Move editor identity from text matching to source keys.

Deliverables:

- Add translator-session wrappers only on display-level hooks.
- Wrapper attributes:
  - data-tl-source
  - data-tl-key

- Do not wrap raw-value hooks.
- Update frontend/admin editor code to save by source key.
- Replace focus-highlighter text search where possible with source-key wrapper targeting.

Acceptance:

- Anonymous visitors never see wrappers.
- Translator session can identify exact source row.
- Editor save does not depend on matching rendered text.

### 12. Add Maintenance Jobs

Implement v4 lifecycle cleanup.

Deliverables:

- Language hard-delete job:
  - delete from tl_translation by lang_id
  - keyset cursor on source_key

- Type 4/5 age-out job:
  - cursor walk on tl_source.id
  - delete old unparented sources
  - rely on FK cascade for translations

- Gettext domain cleanup job:
  - delete type-4 sources by domain using cursor batches

- Deferred index detection:
  - weekly row/language count
  - admin maintenance action when thresholds are exceeded

Acceptance:

- No bulk job uses OFFSET.
- No cleanup job runs as one giant transaction.
- Deleting sources cascades translations.
- Large-site indexes are suggested, not created preemptively.

### 13. Remove Or Retire Old Code Paths

Clean up old split-table assumptions after v4 behavior is wired.

Deliverables:

- Remove old per-table repositories or leave them unreachable only during a short transition branch.
- Remove OwnedStringIndex DB hash fallback.
- Remove split-table SourceRoute table naming dependency.
- Remove old target_lang varchar query assumptions.
- Update templates/controllers that expect row refs in table:id format.
- Update uninstall to clean old tables once for clean-reset installs.

Acceptance:

- No active runtime/admin code queries tl_translations_*.
- No active runtime/admin code queries tl_languages.
- No active code uses original-hash-only replacement as the primary identity.

## Test Plan

- Key contract tests for every source type and invalid shape.
- Schema tests for v4 tables, indexes, and FKs.
- Activation failure tests for non-InnoDB parent tables.
- Language option tests for monotonic IDs and no ID reuse.
- Repository tests for discovery, save, ignore, reset, cascade behavior.
- Runtime tests for post, postmeta, term, gettext, and HTML fallback replacement.
- Admin tests for keyset queue, save/review/ignore/reset, and language siblings.
- Maintenance tests for language delete, age-out, domain cleanup, and deferred index suggestions.

## Assumptions

- Existing translation memory data can be discarded.
- The source locale remains get_locale().
- Existing URL/language routing behavior should remain unless it directly conflicts with lang_id storage.
- The implementation should proceed in the order above; do not build admin/UI behavior before the v4 key and schema contracts are
stable.
