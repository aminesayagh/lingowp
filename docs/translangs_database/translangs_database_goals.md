# TransLangs Database v4 Refactor Plan

## Summary

Refactor the plugin from the current split-table translation memory model to the frozen v4 database standard in docs/
translangs_database/translangs_database_architecture.md.

The implementation goal is a clean v4 reset: no migration of existing translation memory data. Existing old tables may be dropped/
ignored during the upgrade path, and the plugin will rebuild source discovery into the new tl_source / tl_translation model.

Primary goals:

- Make source_key the single stable identity for every translatable string.
- Separate discovered source strings from produced translations.
- Remove language/provider SQL tables and use option-backed tinyint dictionaries.
- Move render resolution to computed-key lookups instead of final-HTML hash replacement.
- Keep the hot path join-free, batched, and index-driven.

## Key Changes

- Add a TL_Key chokepoint responsible for normalization, type validation, selector length enforcement, and deterministic 32-byte
binary key generation.

- Replace the current schema with:
    - tl_source: one row per source string across post fields, postmeta, terms, gettext, and HTML fallback.
    - tl_translation: one row per produced (source_key, lang_id) translation.

- Replace tl_languages with translangs_languages option:
    - monotonic next_lang_id
    - permanent lang_id
    - no ID reuse
    - soft delete by config status

- Add translangs_providers option using the same monotonic tinyint dictionary pattern.
- Update repository contracts from row-id/table routing to source-key based operations:
    - discover/upsert source rows
    - save/update/delete translation rows
    - fetch render maps by computed keys
    - query admin queue via tl_source LEFT JOIN tl_translation

- Replace current RowRef semantics with a source-key based admin handle, e.g. hex source_key plus target lang_id where needed.
- Rebuild public rendering around v4 order:
    - WP filters resolve structured strings first.
    - Hooks compute keys and consult request-local maps.
    - Output buffer handles only HTML fallback.
    - HTML fallback skips request-local owned hashes.

- Keep deferred discovery writes through Action Scheduler, but write source rows, not per-language placeholder translation rows.
- Implement lifecycle jobs:
    - language hard-delete by lang_id
    - type 4/5 age-out
    - gettext domain cleanup
    - deferred index suggestion thresholds

- Update activation diagnostics:
    - verify wp_posts and wp_term_taxonomy are InnoDB
    - create v4 tables with raw DDL
    - verify fk_src_post, fk_src_tt, and fk_tr_source
    - rollback and show specific activation failure messages

## Implementation Phases

1. Core identity and schema
    Build TL_Key, normalization contract, v4 DDL, table helpers, activation checks, uninstall cleanup, and default options.

2. Language/provider config
    Replace WpDbLanguageRegistry behavior with option-backed language dictionaries while keeping the app-facing language registry
    API stable where practical.

3. Persistence layer
    Replace split-table repositories with a v4 repository that reads/writes tl_source and tl_translation.

4. Render and discovery pipeline
    Add structured hook resolution for posts, postmeta, terms, and gettext. Keep output buffering only for HTML fallback and link
    rewriting.

5. Admin workflows
    Update translation listing, save/review/ignore/reset actions, language sibling lookup, same-text application, and focus/marking
    mode to use source keys.

6. Maintenance
    Add language delete, source age-out, domain cleanup, Site Health diagnostics, and deferred-index recommendation logic.

## Test Plan

- Unit-level checks for TL_Key:
    - every source type
    - big-endian owner packing
    - ref1/ref2 hashing
    - selector length cap
    - UTF-8 and whitespace normalization

- Schema verification:
    - expected tables and indexes exist
    - old split-table assumptions are gone
    - all three v4 FKs are verified
    - activation fails clearly when parent tables are not InnoDB

- Repository scenarios:
    - source discovery is idempotent
    - untranslated source has no translation row
    - save creates/updates translation row
    - ignore deletes translation children
    - post deletion cascades source and translation rows

- Render scenarios:
    - post title/content/excerpt replacement
    - postmeta replacement without recursive get_post_meta() calls
    - term replacement
    - gettext replacement and capture
    - HTML fallback only handles unowned text
    - missing translation renders original

- Admin scenarios:
    - keyset queue pagination
    - language filter by lang_id
    - reviewed / needs-review / machine statuses
    - source-key editor save
    - reset ignored returns to untranslated state

- Maintenance scenarios:
    - hard language delete removes translation rows before config removal
    - type 4/5 age-out deletes source rows and cascades translations
    - deferred index recommendation appears only past thresholds

## Assumptions

- Existing translation memory data does not need to be migrated.
- Existing user-facing settings such as URL prefixes, cookie preference, and browser detection remain in place unless directly
affected by language registry changes.

- The source locale continues to come from get_locale() and is never stored as a target language.
- The refactor should prefer direct feature-local flow over new generic infrastructure.
- Tests will be proposed but not run by the agent unless explicitly requested later.