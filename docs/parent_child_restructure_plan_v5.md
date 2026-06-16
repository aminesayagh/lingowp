  ## Key Implementation Changes

  1. Schema
      - Bump TranslationMemorySchema::DB_VERSION from 12 to 13.
      - Replace TableDefinitions::source() / translation() with table definitions for the five parent and five child tables.
      - Parent tables store readable identity plus original_hash, original_text, status, timestamps.
      - Child tables store parent_id, lang_code, translated_text, translated_against_hash, status, provider, timestamps.
      - Keep translated_against_hash even though the v5 docs understate it, because the current admin UI depends on
        review_changed.

      - Child uniqueness is always (parent_id, lang_code).
      - No tl_languages table and no language FK.

  2. Source Identity Model
      - Retire TL_Key as database identity.
      - Replace it with concrete source identity builders for:
          - post: post_id, field, original_hash
          - meta: post_id, meta_key_hash, context_hash, original_hash
          - term: term_taxonomy_id, field, original_hash
          - staticText: source, reference_hash, original_hash
          - html: selector_hash, original_hash

      - Keep StringNormalizer as the hash source: original_hash = SHA256(normalized original text).
      - For HTML render/discovery, compute original_hash from the encoded unit text, not only from the selector.

  3. Translation Retention On Source Edits
      - Add parent status 5 stale in addition to 0 discovered and 3 ignored.
      - When a source text changes at the same logical location, insert the new parent row and mark the old parent stale instead
        of deleting it.

      - If stale parent children exist, copy them to the new parent as needs_review, preserving translated_against_hash from the
        old source hash. This keeps existing translation work visible as review_changed.

      - Rendering and normal admin queues must only use parent.status = 0.
      - Ignoring a parent sets status = 3 and must not delete child rows.

  4. Repository Refactor
      - Replace WpDbSourceTranslationRepository internals with v5 parent/child operations.
      - Keep one public repository interface if possible, but change method inputs from raw binary source_key to an opaque
        source reference.

      - Use source references shaped as {type}:{parent_id}, for example post:123, meta:45, staticText:9.
      - Replace HexSourceKey with SourceRef parser/formatter.
      - saveTranslation() stamps translated_against_hash from the current parent original_hash.
      - deleteTranslation() deletes only the selected child row.
      - ignoreSource() updates parent status only.
      - restoreSources() returns ignored parents to discovered.

  5. Migration
      - Create v5 tables alongside existing v4 tables.
      - Migrate v4 parents from tl_source:
          - type 1 → tl_post
          - type 2 → tl_meta
          - type 3 → tl_term
          - type 4 → tl_staticText
          - type 5 → tl_html

      - Use v4 current_hash as v5 original_hash.
      - Use v4 ref1 / ref2 as readable identity inputs:
          - meta: ref1 = meta_key, ref2 = context
          - staticText: ref1 = source/domain, ref2 = reference/context
          - html: ref1 = selector, source_url remains on parent

      - Migrate v4 children from tl_translation by resolving each row’s old source_key to the new parent row.
      - Preserve lang_code, translated_text, translated_against_hash, status, provider, and timestamps.
      - Validate no duplicate (parent identity, lang_code) rows before inserting children.
      - Leave old v4 tables in place after successful cutover.

  6. Runtime Discovery
      - Update StructuredSourceScanner to produce v5 parent identities instead of SourceRecord rows with source_key.
      - Rename route/count buckets from postmeta to meta, terms to term, and i18n to staticText.
      - Update GettextSource / GettextResolver naming to StaticTextSource / static text, while keeping WordPress gettext hooks.
      - Update stale pruning so disappeared locations become stale; do not delete parent rows just because source text changed.

  7. Frontend Rendering
      - Update StructuredRenderResolver to batch by v5 identity and call repository methods per source type.
      - Update static text resolution to query tl_staticText + child table by (source, reference_hash, original_hash,
        lang_code).

      - Update HtmlRenderer to resolve by (selector_hash, original_hash, lang_code).
      - Keep current behavior: stale translations may render until reviewed only if intentionally copied forward to the new
        parent as needs_review; ignored parents never render.

  8. Admin REST And UI
      - Change REST route key regex from 64-char hex to source refs like post:123.
      - Update admin API helpers to encodeURIComponent(key) in URL paths.
      - Rename content area i18n to staticText.
      - Keep response field name key for UI compatibility, but its value becomes the source ref.
      - Update duplicate/apply-everywhere flows to return and accept source refs instead of source_key.
      - Keep admin states: to_translate, review_flagged, review_changed, done.

  9. Health And Cleanup
      - Update DatabaseHealthReport and ConstraintVerifier to verify all v5 tables and FKs.
      - Update uninstall to include v5 table names.
      - Keep language options unchanged.
      - Do not remove v4 tables in this refactor; add explicit cleanup only later if you decide it is safe.

  ## Test Plan

  You run these; do not claim pass until you execute them.

  1. Schema
      - Activate from empty database: all ten v5 tables exist, no tl_languages.
      - Upgrade from v4 database: v5 tables created, v4 tables retained, translangs_db_version = 13.

  2. Migration
      - Existing post, meta, term, staticText, and html rows migrate with exact original text.
      - Existing translations migrate with same language code, translated text, status, provider, and timestamps.
      - Disabled languages keep their translated rows.

  3. Rendering
      - Post title/content/excerpt translate on target-language URL.
      - Meta, term, staticText, and html preview translations resolve only in their own context.
      - Same original text in two contexts does not cross-render.

  4. Source Edits
      - Editing source text creates a new discovered parent.
      - Prior translation is copied forward as needs_review / review_changed.
      - Old parent is stale, not deleted.

  5. Admin
      - Lists, counts, filters, save, clear, ignore, restore, preview, all-languages save, and apply-everywhere work with
        {type}:{id} keys.

      - Ignoring a source hides it from normal queues but does not delete child translations.
      - Re-enabling a disabled language shows its previous translations.

  6. Suggested Commands
      - composer dump-autoload if class names change.
      - npm run build after admin UI changes.
      - Your PHP/WordPress test command for schema migration and repository behavior.
      - Manual browser check for settings, translation board, public render, and preview.

  ## Assumptions

  - Use exact table name tl_staticText as agreed, including camelCase.
  - No multisite-specific behavior is required.
  - No tl_languages table is introduced.
  - Language disable is soft only; translation content is retained.
  - v4 tables remain as rollback data after migration.
  - The implementation preserves current review_changed behavior by keeping translated_against_hash.
