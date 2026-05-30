# Parsing Implementation Plan

This plan adapts the parsing architecture to the current TransLangs codebase and
the final source identity rules.

## Goals

- Discover strings from structured WordPress sources before relying on rendered HTML.
- Use the HTML parser as a final audit layer, not the primary discovery engine.
- Classify every discovered string with `source_type`, `source_id`, `context`, and `original_hash`.
- Avoid requiring admins to manually visit every page, product, post, or CPT entry.
- Keep source identity explicit and stable across the repository.

## Source Identity Rules

Use fixed source types only where the source type is known in advance:

```txt
media
taxonomy
menu
widget
page
post
product
```

For custom post types, use the real post type name at runtime:

```txt
portfolio
event
service
{custom_post_type_name}
```

Do not use these source types:

```txt
template
cpt
cpt_portfolio
```

Rendered/template-level text still needs a required `source_type`. It should use
the current area type with `source_id = 0`.

```txt
source_type = current area type, e.g. page/product/post/portfolio
source_id   = 0
context     = parser DOM context or archetype context
```

Example:

```txt
source_type = product
source_id   = 0
context     = single_product.related_products_title
text        = Related products
```

---

## Phase 1: Fix Candidate Identity

Current lookup still mostly returns translations by hash. That is not enough
long-term because the same text can exist in different source identities.

Add a stronger candidate model:

```txt
ClassifiedTranslationCandidate
```

Fields:

- `sourceId`
- `sourceType`
- `context`
- `originalText`
- `normalizedText`
- `originalHash`
- `locators`

Then change the translation resolver to resolve by full identity, not by hash
alone.

This is the foundation. Without it, media, product, and rendered/template text
can collide.

---

## Phase 2: Create A Discovery Application Service

Add:

```txt
TranslationMemory/Application/DiscoverSourceStrings.php
```

Responsibilities:

- Receive `TranslationSourceIdentity[]`.
- Insert rows for every enabled non-default language.
- Set `status = discovered`.
- Set `provider = null`.

Centralized rule:

```txt
discover once -> create rows for fr/es/ar/etc.
```

The repository can still insert per language internally, but the application
service should own the "all enabled target languages" behavior.

---

## Phase 3: Add Structured Source Readers

Add a new module:

```txt
src/ContentSource/
├── Domain/
│   ├── SourceString.php
│   └── SourceReader.php
├── Application/
│   └── ScanStructuredSources.php
└── Infrastructure/WordPress/
    ├── MediaSourceReader.php
    ├── TaxonomySourceReader.php
    ├── MenuSourceReader.php
    ├── PostTypeSourceReader.php
    └── WooCommerceProductSourceReader.php
```

### Media

```txt
source_type = media
source_id   = attachment ID
context     = alt/title/caption/description
```

### Taxonomy

```txt
source_type = taxonomy
source_id   = term ID
context     = {taxonomy}.name / {taxonomy}.description
```

### Posts, Pages, Products, And CPTs

```txt
source_type = page/post/product/{custom_post_type_name}
source_id   = post ID
context     = post_title/post_content/post_excerpt/custom field name
```

Important rule:

- Do not scan referenced media or taxonomies again as product text.
- Product-owned fields belong to the product.
- Referenced entities stay owned by their own source.

---

## Phase 4: Move Image Alt Out Of Generic HTML Parsing

Current parser still extracts:

```php
'img' => ['alt']
```

But media `alt`, `title`, `caption`, and `description` should belong to the
attachment.

After the media reader exists:

- Stop generic parser discovery for `img@alt`.
- Add a dedicated media replacement step.
- Detect image attachment from `src`.
- Look up `media + attachment_id + alt`.
- Replace the rendered `alt` attribute if translated.

This avoids treating image alt text as page, product, or rendered/template text.

---

## Phase 5: Add Render-Time Classification

Current `TranslateHtmlPage` assigns one source identity to the whole page. That
is too simple for the parsing plan.

Add:

```txt
PageTranslation/Application/ClassifyRenderedSegments.php
```

Inputs:

- Current WordPress object.
- Parser segments.
- Known structured source strings.

Output:

```txt
ClassifiedTranslationCandidate[]
```

Classification priority:

1. Current entity field match.
2. Referenced media, taxonomy, or menu match.
3. Rendered/template text for the current source type with `source_id = 0`.

Example on a product page:

```txt
"Black Shoes"      -> product, 123, post_title
"Shoes"            -> taxonomy, 44, product_cat.name
"Related products" -> product, 0, single_product.related_products_title
```

---

## Phase 6: Change The Render Pipeline

Target pipeline:

```txt
OutputBufferMiddleware
-> TranslateHtmlPage
-> HtmlParserFactory
-> Extract segments
-> Classify segments
-> Resolve translations by identity
-> Build locator replacements
-> Rewrite links
-> Render HTML
```

This is where rendered parsing becomes the final auditor instead of the primary
source of truth.

---

## Phase 7: Discover On Default-Language Visits

Current file:

```txt
src/PublicSite/Infrastructure/OutputBufferMiddleware.php
```

Currently skips default-language requests:

```php
if (!$this->resolver->isTranslatedRequest()) {
    return;
}
```

For this plan, that should become more nuanced.

Default language:

- Parse and discover.
- Do not replace text.

Translated language:

- Parse and discover.
- Replace text.

The pipeline should support two modes:

```txt
discoverOnly
translateAndDiscover
```

---

## Phase 8: Add URL Sampling / Archetype Scanner

Add:

```txt
src/ContentSource/Application/BuildScanTargets.php
src/ContentSource/Infrastructure/WordPress/ArchetypeUrlSampler.php
```

It should generate representative URLs:

- Front page.
- All static pages, if the count is reasonable.
- 1 to 3 posts.
- Product archive.
- Sample simple product.
- Sample variable product.
- Sample sale product.
- Sample out-of-stock product.
- CPT single and archive samples.
- 404, search, and archive pages.

This avoids rendering 1000 products.

---

## Phase 9: Add Admin Scan Entry Point

Later, add a button or page:

```txt
BackOffice/Infrastructure/SourceScanPageController.php
```

Actions:

- Scan structured sources.
- Scan rendered archetypes.
- Show discovered count.
- Show errors.

Start synchronously for MVP. If the site is large, move to batched WP-Cron
later.

---

## Validation Order

Validate in this order:

1. Schema and repository identity lookup.
2. Source readers insert correct `source_id`, `source_type`, and `context`.
3. Parser no longer creates media alt rows.
4. Current product/page render classifies own fields correctly.
5. Rendered/template strings use `source_id = 0`.
6. Default-language visit discovers but does not translate.
7. Translated-language visit discovers and translates.
8. Admin table still filters, saves, and reviews correctly.

## Recommended Execution Order

The best practical order from the current repo is:

1. Identity-based candidate and lookup refactor.
2. Discovery service for all enabled target languages.
3. Media reader and media replacement.
4. Post, page, product, and CPT structured reader.
5. Rendered segment classifier.
6. Default-language discovery mode.
7. URL/archetype scanner.
8. Admin scan button or page.

This keeps the work stable: first make identity correct, then add sources, then
make the parser an auditor.
