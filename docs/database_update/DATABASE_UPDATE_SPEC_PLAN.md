Execution Plan

## 0. Ground Rules

- No legacy data migration. Drop old wp_translangs_strings.
- No WooCommerce-specific support in this base plugin.
- Products are treated only as normal posts/CPTs: title/content/excerpt.
- WooCommerce-specific fields, attributes, variations, purchase notes, button text, and gettext should be handled later by a separate extension plugin.
- Source language comes from WordPress get_locale(), normalized to short code.
- wp_tl_languages stores target languages only.
- All hashes are raw BINARY(32) in DB; use bin2hex() only for PHP map keys/display.

## 1. Add Core Domain Primitives

  Create:

- src/TranslationMemory/Domain/RowRef.php
- src/ContentSource/Domain/SourceRoute.php
- update src/ContentSource/Domain/SourceString.php
- update src/TranslationMemory/Domain/TranslationSourceIdentity.php
- add src/TranslationMemory/Domain/TranslationMapKey.php

  RowRef must be the only admin row identifier:

  post:123
  postmeta:88
  terms:14
  i18n:902

  No controller or service should parse this manually.

  SourceString should move from:

  sourceId, sourceType, context, text

  to explicit routed shape:

  routeTable, parentId, field, metaKey, context, source, reference, text

  This removes the old polymorphic source_type/context ambiguity.

## 2. Rebuild Schema Infrastructure

  Replace the current TranslationMemorySchema design.

  Create:

- src/TranslationMemory/Infrastructure/Schema/TableDefinitions.php
- src/TranslationMemory/Infrastructure/Schema/ConstraintVerifier.php
- src/TranslationMemory/Infrastructure/Schema/SchemaFailure.php

  Keep:

- src/TranslationMemory/Infrastructure/TranslationMemorySchema.php

  but make it orchestration only.

  Required table helpers:

  tlLanguagesTable()
  tlPostTable()
  tlPostmetaTable()
  tlTermsTable()
  tlI18nTable()
  stringsTable() // old table only, for drop

  Install/upgrade order:

  1. Drop old {$wpdb->prefix}translangs_strings.
  2. Create wp_tl_languages.
  3. Create:
      - wp_tl_translations_post
      - wp_tl_translations_postmeta
      - wp_tl_translations_terms
      - wp_tl_translations_i18n

  4. Verify every FK through information_schema.
  5. If any FK is missing, drop translation tables first, then languages, then fail activation.
  6. Update DB version only after verification passes.

  Do not use dbDelta.

## 3. Language Registry

  Create:

- src/Language/Domain/LanguageRegistry.php
- src/Language/Infrastructure/WpDbLanguageRegistry.php
- src/Language/Domain/LocaleNormalizer.php

  Update:

- src/Language/Application/ResolveRequestLanguage.php
- src/BackOffice/Infrastructure/SettingsPageController.php

  Rules:

- getDefaultLanguage() returns normalized get_locale(), for example en_US -> en.
- getEnabledLanguages() returns [source] + active target languages.
- isEnabledLanguage() accepts source or active target.
- Settings screen stops saving translangs_default_language.
- Target language checkboxes insert/delete rows in wp_tl_languages.
- Existing option translangs_enabled_languages is only used for initial seeding/backward compatibility.

  Audit consumers of getEnabledLanguages():

- LanguageRouteRegistrar
- LanguageSwitcherShortcode
- HreflangPresenter
- LocalizedUrlBuilder
- LinkRewriter
- OutputBufferMiddleware
- DiscoverSourceStrings

  Discovery must exclude source.

## 4. Hashing And Normalization

  Update:

- src/TranslationMemory/Domain/StringNormalizer.php
- src/TranslationMemory/Domain/StringHashGenerator.php

  Normalizer must:

  1. Strip \r.
  2. Decode HTML entities as UTF-8.
  3. Trim.
  4. Collapse internal whitespace.
  5. Return deterministic UTF-8 string.

  Hasher must return raw bytes:

  hash('sha256', $normalized, true)

  For DB insert:

- original_hash: raw bytes.
- meta_key_hash: raw bytes.
- context_hash: raw bytes, always sha256(context ?? '', true).
- reference_hash: raw bytes.

  Never insert NULL into context_hash.

## 5. Split Repository Layer

  Create table-specific repositories:

- src/TranslationMemory/Infrastructure/Repositories/PostTranslationsRepository.php
- src/TranslationMemory/Infrastructure/Repositories/PostmetaTranslationsRepository.php
- src/TranslationMemory/Infrastructure/Repositories/TermsTranslationsRepository.php
- src/TranslationMemory/Infrastructure/Repositories/I18nTranslationsRepository.php
- src/TranslationMemory/Infrastructure/Repositories/AdminTranslationsQuery.php

  Keep:

- src/TranslationMemory/Infrastructure/WpDbTranslationMemoryRepository.php

  but convert it into a facade/router.

  Update:

- src/TranslationMemory/Domain/TranslationMemoryRepository.php

  The facade should expose application-level operations:

  insertMissingStrings(array $identities, string $targetLang): int
  saveTranslation(RowRef $rowRef, string $text, int $status): void
  markDiscovered(RowRef $rowRef): void
  markReviewed(RowRef $rowRef): void
  markNeedsReview(RowRef $rowRef): void
  ignoreString(RowRef $rowRef): void
  find(RowRef $rowRef): ?array
  query(array $filters, int $page, int $perPage): array

  Remove source_lang from repository contracts.

## 6. Update Source Readers

  Update:

- PostTypeSourceReader
- MediaSourceReader
- TaxonomySourceReader
- ScanStructuredSources

  Remove/unregister:

- WooCommerceProductSourceReader from CoreServiceProvider

  You may leave the class file temporarily, but it must not be registered in this base plugin.

  Routing:

- Post/page/CPT fields:
  - route: post
  - fields: post_title, post_content, post_excerpt

- Attachment fields:
  - title → post, field post_title
  - caption → post, field post_excerpt
  - description → post, field post_content
  - alt → postmeta, meta key _wp_attachment_image_alt, empty context

- Taxonomy:
  - route: terms
  - parent ID: term_taxonomy_id
  - fields: name, description, optional later slug
  - no {taxonomy}.name context prefix

## 7. Discovery Pipeline

  Update:

- src/TranslationMemory/Application/DiscoverSourceStrings.php
- src/ContentSource/Application/ScanStructuredSources.php
- src/ContentSource/Application/ScanRenderedArchetypes.php

  Discovery process:

  1. Reader emits routed SourceString.
  2. Scanner normalizes text.
  3. Scanner hashes original text.
  4. Scanner builds TranslationSourceIdentity.
  5. Discovery fans out across active target languages only.
  6. Repository inserts into correct split table with INSERT IGNORE.

  Do not create rows for source language.

## 8. Render Preload Path

  Create:

- src/TranslationMemory/Application/PreloadTranslations.php

  Update:

- src/TranslationMemory/Application/ResolveTranslations.php
- src/PageTranslation/Application/ClassifyRenderedSegments.php
- src/PageTranslation/Application/TranslateHtmlPage.php

  Replace old lookup:

  findManyByHashes(sourceLang, targetLang, sourceId, sourceType, context)

  with preload methods:

  getPostTranslations(string $targetLang, int $postId): array
  getPostmetaTranslations(string $targetLang, int $postId): array
  getTermTranslations(string $targetLang, array $termTaxonomyIds): array
  getI18nTranslations(string $targetLang, string $source): array

  Map keys:

  field:<field>:<original_hash_hex>
  meta:<meta_key_hash_hex>:<context_hash_hex>:<original_hash_hex>
  term:<tt_id>:<field>:<original_hash_hex>
  i18n:<source>:<reference_hash_hex>:<original_hash_hex>

  Only include rows with renderable statuses:

- machine
- reviewed
- needs_review

  Skip:

- discovered
- ignored

  Hard rule: no query per string.

## 9. Admin UI

  Update:

- src/BackOffice/Infrastructure/TranslationMemoryPageController.php
- templates/translations.php
- application services:
  - SaveTranslation
  - ReviewTranslation
  - PutTranslationForReview
  - IgnoreTranslation
  - ResetIgnoredTranslation
  - QueryTranslations

  Changes:

- Remove source-language filter.
- Keep target-language, status, original search, translated search.
- Admin query uses UNION ALL across four tables.
- Every row returned includes row_ref.
- Template hidden field changes from string_id to row_ref.
- Controller parses RowRef::parse() once, then passes RowRef to services.

## 10. Lifecycle Hooks

  Add a focused lifecycle service, for example:

- src/TranslationMemory/Application/CleanupTranslations.php
- or src/TranslationMemory/Infrastructure/TranslationLifecycleHooks.php

  Register from CoreServiceProvider.

  Hooks:

- before_delete_post
  - clear plugin caches for post/postmeta rows.
  - FK cascade deletes DB rows.

- pre_delete_term
  - clear term-related caches.
  - FK cascade handles rows through term_taxonomy_id.

- save_post
  - re-scan post fields/meta.
  - delete stale hashes for that entity.
  - use required re-entrancy guard:

  remove_action('save_post', [$this, 'scanPostFields']);
  // writes / rescan
  add_action('save_post', [$this, 'scanPostFields']);

## 11. Bootstrap Wiring

  Update:

- src/Bootstrap/CoreServiceProvider.php
- src/Bootstrap/PublicSiteServiceProvider.php
- src/Setup/Activator.php

  Dependency order:

  1. TranslationMemorySchema::maybeUpgrade()
  2. LocaleNormalizer
  3. WpDbLanguageRegistry
  4. split repositories
  5. repository facade
  6. normalizer/hasher/map-key services
  7. discovery/preload/resolve services
  8. content source readers
  9. admin/public services
  10. lifecycle hooks

  Remove WooCommerce reader registration from base plugin.

## 12. Uninstall Cleanup

  Update:

- uninstall.php

  Drop order:

  1. wp_tl_translations_i18n
  2. wp_tl_translations_terms
  3. wp_tl_translations_postmeta
  4. wp_tl_translations_post
  5. wp_tl_languages

  Then remove options:

- translangs_db_version
- translangs_default_language obsolete
- translangs_enabled_languages obsolete/backward-compat seed only
- existing behavior options:
  - prefix default language
  - cookie preference
  - browser detection
  - auto redirect

## 13. Manual Validation Checklist

  You should run these manually in Local WordPress:

  1. Clean activation creates all five tables.
  2. FK constraints exist in information_schema.
  3. Old wp_translangs_strings is dropped.
  4. Failed FK verification rolls back all created tables.
  5. Settings insert/delete target rows in wp_tl_languages.
  6. Source language from get_locale() is never inserted into wp_tl_languages.
  7. getEnabledLanguages() includes source and targets.
  8. Switcher, router, hreflang, and localized URLs work with source included.
  9. Structured scan writes:
      - post/CPT fields to _post
      - attachment alt to _postmeta
      - taxonomy rows to _terms by term_taxonomy_id

  10. Flat meta uses context_hash = sha256('').
  11. Admin save/review/ignore/reset works through RowRef.
  12. Public render uses preload maps, not per-string DB queries.
  13. Post deletion cascades _post and_postmeta.
  14. Language deletion cascades all translations for that target.
  15. save_post re-scan removes stale hashes and does not recurse.
  16. Product title/content/excerpt work as normal post fields.
  17. WooCommerce-specific fields are not routed in this plugin.

## Important Current Repo Notes

- DATABASE_UPDATE_SPEC_PLAN.md is empty right now. Populate it before using it as the handoff document.
- TranslationMemorySchema.php is still old dbDelta single-table schema.
- WpDbTranslationMemoryRepository.php is still old single-table SQL.
- SourceString is still old sourceId/sourceType/context/text.
- TaxonomySourceReader still emits term_id, not term_taxonomy_id.
- CoreServiceProvider currently registers WooCommerceProductSourceReader; this must be removed for the base plugin boundary.

---

## Data structures reference

The concrete shapes that flow through the system, so readers, repositories, and
the render path agree on contracts. Field names are illustrative PHP; adapt to
your value objects.

### A. The five tables (column shapes, recap)

```
wp_tl_languages
  code         varchar(10)  PK         -- target language, e.g. 'fr'
  name         varchar(100)            -- "French"
  native_name  varchar(100)            -- "Français"
  direction    varchar(3)              -- 'ltr' | 'rtl'
  status       tinyint(1)              -- 1 active, 0 inactive
  sort_order   int
  created_at, updated_at  datetime
 
wp_tl_translations_post
  id           bigint PK
  post_id      bigint   FK wp_posts.ID            CASCADE
  target_lang  varchar(10) FK languages.code      CASCADE
  field        varchar(32)                         -- post_title|post_content|post_excerpt|title|caption|description
  original_hash    binary(32)                      -- sha256(normalized text), raw
  original_text    longtext
  translated_text  longtext NULL
  status       tinyint  -- 0 discovered,1 machine,2 reviewed,3 ignored,4 needs_review
  provider     varchar(50) NULL
  created_at, updated_at
  UNIQUE (target_lang, post_id, field, original_hash)
 
wp_tl_translations_postmeta
  id           bigint PK
  post_id      bigint   FK wp_posts.ID            CASCADE
  target_lang  varchar(10) FK languages.code      CASCADE
  meta_key     varchar(191)                        -- readable, e.g. _wp_attachment_image_alt
  meta_key_hash    binary(32)                      -- sha256(meta_key)
  context      text NULL                           -- readable path, NULL/'' for flat meta
  context_hash binary(32)                          -- sha256(context ?? ''), NEVER null
  original_hash    binary(32)
  original_text    longtext
  translated_text  longtext NULL
  status, provider, created_at, updated_at
  UNIQUE (target_lang, post_id, meta_key_hash, context_hash, original_hash)
 
wp_tl_translations_terms
  id           bigint PK
  term_taxonomy_id bigint FK wp_term_taxonomy.term_taxonomy_id CASCADE
  target_lang  varchar(10) FK languages.code      CASCADE
  field        varchar(32)                         -- name|description|slug
  original_hash binary(32), original_text longtext, translated_text longtext NULL
  status, provider, created_at, updated_at
  UNIQUE (target_lang, term_taxonomy_id, field, original_hash)
 
wp_tl_translations_i18n
  id           bigint PK
  target_lang  varchar(10) FK languages.code      CASCADE
  source       varchar(20)                         -- 'plugin'|'theme'|'core'
  reference    varchar(191)                        -- readable, e.g. woocommerce/woocommerce.php
  reference_hash binary(32)                        -- sha256(normalized reference)
  original_hash binary(32), original_text longtext, translated_text longtext NULL
  status, provider, created_at, updated_at
  UNIQUE (target_lang, source, reference_hash, original_hash)
```

### B. `RowRef` value object (admin row addressing)

```
RowRef {
    table : enum('post','postmeta','terms','i18n')
    id    : int            // the row's bigint id in that table
}
toString()  -> "post:123" | "postmeta:88" | "terms:14" | "i18n:902"
parse(s)    -> RowRef       // validates the prefix is a known table; throws on garbage
tableName() -> resolves to the real wp_tl_translations_* table name
```

Used by SaveTranslation / review / ignore / reset / find. Never a bare int,
never an ad-hoc string split.

### C. `SourceString` — what a reader emits (pre-hash)

Readers produce these; the discovery service normalizes + hashes + routes them.
The reader does NOT know about target languages or hashes.

```
SourceString {
    routeTable : enum('post','postmeta','terms','i18n')
    parentId   : int        // post_id | term_taxonomy_id | 0 for i18n
    field      : string|null // post: 'post_title'; terms: 'name'; null for postmeta/i18n
    metaKey    : string|null // postmeta only: '_wp_attachment_image_alt'
    context    : string|null // postmeta nested path; null/'' for flat
    source     : string|null // i18n only: 'plugin'|'theme'|'core'
    reference  : string|null // i18n only: 'woocommerce/woocommerce.php'
    text       : string      // raw original text, un-normalized
}
```

Reader → routeTable examples:

```
post_title of post 42        -> {post,      parentId:42, field:'post_title', text:'About us'}
alt of attachment 88         -> {postmeta,  parentId:88, metaKey:'_wp_attachment_image_alt',
                                  context:null, text:'Team photo'}
category 'News' (tt_id 14)   -> {terms,     parentId:14, field:'name', text:'News'}
'Add to cart' (woo gettext)  -> {i18n,      source:'plugin', reference:'woocommerce/woocommerce.php',
                                  text:'Add to cart'}   // (deferred this build)
```

### D. `TranslationSourceIdentity` — post-normalize, what discovery inserts

The discovery service turns each `SourceString` into an identity ready for
insertion (one per active target language).

```
TranslationSourceIdentity {
    routeTable     : enum(...)            // which table to insert into
    parentId       : int
    field          : string|null
    metaKey        : string|null
    metaKeyHash    : binary(32)|null      // sha256(metaKey)        (postmeta)
    context        : string|null
    contextHash    : binary(32)|null      // sha256(context ?? '')  (postmeta) — NEVER null for postmeta
    source         : string|null
    reference      : string|null
    referenceHash  : binary(32)|null      // sha256(norm reference) (i18n)
    originalText   : string               // raw (stored inline)
    normalizedText : string               // transient, used only to hash
    originalHash   : binary(32)           // sha256(normalizedText)
}
```

Inserted as: `status = 0 (discovered)`, `provider = NULL`, for each active
target language. `INSERT IGNORE` on the table's unique key -> idempotent.

### E. Preload maps — what the render path consumes

One bulk query per entity/context returns an associative map; render does array
lookups, never per-string queries. Key formats per table:

```
getPostTranslations(targetLang, postId): array
  key   : "field:<hex_hash>"
  value : translated_text (string)
  e.g.  [ "post_title:9f86d0…" => "À propos", "post_excerpt:5e88…" => "…" ]
 
getPostmetaTranslations(targetLang, postId): array
  key   : "meta:<meta_key_hash_hex>:<context_hash_hex>:<original_hash_hex>"
  value : translated_text
  e.g.  [ "meta:7d79…:e3b0…:1f3a…" => "Photo d'équipe" ]   // alt; context_hash=sha256('')
 
getTermTranslations(targetLang, termTaxonomyIds[]): array
  key   : "term:<tt_id>:<field>:<original_hash_hex>"
  value : translated_text
  e.g.  [ "term:14:name:8b1a…" => "Actualités" ]
 
getI18nTranslations(targetLang, domainOrSource): array   // broad preload
  key   : "i18n:<source>:<reference_hash_hex>:<original_hash_hex>"
  value : translated_text
```

Notes:

- Only rows whose `status` is a "show translation" state (machine / reviewed /
  needs_review) are included; `discovered` and `ignored` are skipped — the
  caller then leaves the original text in place.
- Hashes are stored `BINARY(32)` but expressed as **hex** in array keys (PHP
  array keys are strings; raw bytes are awkward/unsafe as keys). Convert with
  `bin2hex()` when building the map key.
- The map is built once per entity and reused for every segment match in that
  entity during the render.

### F. Render match (how a parsed segment finds its translation)

```
for each segment on the page:
    normalized = normalize(segment.text)
    hashRaw    = sha256(normalized, raw)
    hashHex    = bin2hex(hashRaw)
    // entity content: look up by the entity's preloaded map
    key = "field:" . hashHex              // (or meta:/term: shape)
    if (map[key] exists) replace segment with map[key]
    else leave original (discovered-but-untranslated, ignored, or chrome)
```

Identity is structure + text together; the map key encodes both, so the same
text under a different field/context never collides.

### G. Status enum (shared across all tables)

```
0  discovered      -> show original (not yet translated)
1  machine         -> show translated_text
2  reviewed        -> show translated_text
3  ignored         -> show original (deliberately not translated)
4  needs_review    -> show translated_text (flagged in admin)
```
