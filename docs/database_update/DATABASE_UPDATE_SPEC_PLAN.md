  # TransLangs Database Rebuild — Code Organization Addendum

  ## Summary

  Your refined execution plan is sound. I would keep it as the base plan and add this code-organization layer so the implementation stays understandable and does not turn the current TranslationMemory
  repository into one oversized class.

  WooCommerce remains out of scope for this plugin version. Products are handled only as normal WordPress posts/CPTs through post fields. WooCommerce-specific product data, attributes, purchase notes, button
  text, variation/meta behavior, and gettext flows should live in a separate extension plugin later.

  ## Target Repo Organization

  ### Schema And Database Infrastructure

  Keep schema ownership in src/TranslationMemory/Infrastructure, but split responsibilities:

  - TranslationMemorySchema.php
      - Owns install/upgrade orchestration.
      - Drops the old single table.
      - Calls table creators.
      - Verifies FK constraints.
      - Rolls back on failure.
      - Exposes table-name helpers.

  - Schema/TableDefinitions.php
      - Returns SQL strings for the five tables.
      - Keeps DDL readable and isolated.

  - Schema/ConstraintVerifier.php
      - Checks information_schema.TABLE_CONSTRAINTS and KEY_COLUMN_USAGE.
      - Reports exactly which FK is missing.

  - Schema/SchemaFailure.php
      - Small exception/value object for activation failure messages.

  Do not keep all DDL, FK verification, rollback, and migration logic in one long schema class.

  ### Language Registry

  Add a small language module instead of mixing language table writes into settings/controllers:

  - src/Language/Domain/LanguageRegistry.php
      - Interface for active target languages.

  - src/Language/Infrastructure/WpDbLanguageRegistry.php
      - Reads/writes wp_tl_languages.

  - src/Language/Domain/LocaleNormalizer.php
      - Converts get_locale() values like en_US to en.

  - ResolveRequestLanguage.php
      - Uses LocaleNormalizer.
      - Uses LanguageRegistry.
      - Keeps request resolution only.

  SupportedLanguages.php remains the static catalog for labels/native names/direction.

  ### Row References

  Add the real value object in the domain layer:

  - src/TranslationMemory/Domain/RowRef.php
      - parse(string): self
      - toString(): string
      - table(): string
      - id(): int
      - Valid table values: post, postmeta, terms, i18n.

  Application services should accept RowRef, not raw strings or ints:

  - SaveTranslation
  - ReviewTranslation
  - PutTranslationForReview
  - IgnoreTranslation
  - ResetIgnoredTranslation

  ### Routed Source Model

  Replace the old generic SourceString(sourceId, sourceType, context, text) shape with an explicit routed source model:

  - src/ContentSource/Domain/SourceString.php
      - Add routeTable, parentId, field, metaKey, context, source, reference, text.

  - src/ContentSource/Domain/SourceRoute.php
      - Constants or enum-like class: post, postmeta, terms, i18n.

  - src/TranslationMemory/Domain/TranslationSourceIdentity.php
      - Mirror the routed identity shape after normalization/hashing.

  This prevents implicit source_type/context interpretation from leaking into the new split-table design.

  ### Repository Split

  Replace the current single WpDbTranslationMemoryRepository implementation with smaller table-specific repositories plus a facade:

  - src/TranslationMemory/Domain/TranslationMemoryRepository.php
      - Keep as the application-facing facade interface.

  - src/TranslationMemory/Infrastructure/WpDbTranslationMemoryRepository.php
      - Facade/router only.
      - Delegates inserts, preload, admin queries, and row writes.

  - src/TranslationMemory/Infrastructure/Repositories/PostTranslationsRepository.php
  - src/TranslationMemory/Infrastructure/Repositories/PostmetaTranslationsRepository.php
  - src/TranslationMemory/Infrastructure/Repositories/TermsTranslationsRepository.php
  - src/TranslationMemory/Infrastructure/Repositories/I18nTranslationsRepository.php
  - src/TranslationMemory/Infrastructure/Repositories/AdminTranslationsQuery.php

  The facade prevents every service from knowing about four tables, while the per-table classes keep SQL simple.

  ### Render Preload Layer

  Add a focused read model for hot-path rendering:

  - src/TranslationMemory/Application/PreloadTranslations.php
      - Coordinates post, postmeta, term, and i18n preload calls.
      - Returns maps keyed with the agreed hex-key formats.

  - src/TranslationMemory/Domain/TranslationMapKey.php
      - Builds keys:
          - field:<field>:<hash>
          - meta:<meta_key_hash>:<context_hash>:<hash>
          - term:<tt_id>:<field>:<hash>
          - i18n:<source>:<reference_hash>:<hash>

  Avoid embedding map-key string construction across renderer, repository, and classifier.

  ### Source Readers

  Keep current readers, but update their emitted route shape:

  - PostTypeSourceReader
      - Emits only _post field rows.
      - Includes products only as normal posts/CPTs.

  - MediaSourceReader
      - Emits attachment title/caption/description to _post.
      - Emits attachment alt to _postmeta.

  - TaxonomySourceReader
      - Emits term_taxonomy_id.
      - Fields are name, description, optionally slug.

  - WooCommerceProductSourceReader
      - Remove from this plugin version or leave unregistered.
      - WooCommerce-specific handling belongs to a future extension plugin.

  ### Bootstrap Wiring

  Update CoreServiceProvider.php to assemble dependencies in this order:

  1. Schema upgrade.
  2. Language registry + locale normalizer.
  3. Per-table repositories.
  4. Translation memory facade.
  5. Discovery/resolve/preload services.
  6. Content source readers.
  7. Render/admin services.

  This keeps the dependency direction clean: controllers and render services depend on application services, not table-specific SQL classes.

  ## WooCommerce Boundary

  For this plugin version:

  - product is treated like a normal post type.
  - Product title/content/excerpt are stored in _post.
  - No WooCommerce-specific product fields are routed.
  - No product attributes, purchase notes, external button text, variations, pricing text, checkout strings, or WooCommerce gettext capture are implemented here.

  Future extension plugin:

  - Registers its own WooCommerce readers.
  - Emits explicit routed SourceString records into this plugin’s public discovery contract.
  - Provides an explicit field-to-table map for WooCommerce data.
  - Can use _postmeta, _terms, and _i18n without changing the base schema.

  ## Implementation Order By Code Area

  1. Add domain primitives: RowRef, SourceRoute, routed SourceString, updated TranslationSourceIdentity, map-key helper.
  2. Add language registry and locale normalizer.
  3. Rebuild schema infrastructure and FK verification.
  4. Split repository implementation by table and expose facade methods.
  5. Update discovery routing and source readers.
  6. Update render preload and classifier/resolve flow.
  7. Update admin query/actions/templates to use RowRef.
  8. Add lifecycle hooks and uninstall cleanup.
  9. Remove or unregister WooCommerce-specific reader from this base plugin.

  ## Assumptions

  - Keep namespaces aligned with current bounded areas: Language, ContentSource, TranslationMemory, PageTranslation, BackOffice.
  - Prefer several small SQL classes over one large repository.
  - The base plugin owns generic WordPress translation storage; WooCommerce depth is delegated to a future extension plugin.