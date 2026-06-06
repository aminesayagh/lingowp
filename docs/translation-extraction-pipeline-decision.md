# Translation Extraction Pipeline Decision

This document summarizes the architecture discussion around content discovery,
render-time parsing, gettext capture, and where each string should be stored.

It is a decision draft. It describes the pipeline to build toward; it does not
describe the current implementation as fully correct.

## 1. Current Problem

The extraction process has become too broad and too hard to reason about.
Rendered HTML parsing is being used as both:

- a discovery mechanism
- a fallback for unknown strings
- a render-time replacement mechanism

That creates incorrect behavior. In particular, HTML parsing can discover text
that already belongs to a post, term, or post meta field, then save the same
text again as `i18n`.

Example:

1. A post title exists in `wp_tl_translations_post`.
2. The title is still status `discovered`, so it is not preloaded as a
   renderable translation.
3. The HTML parser sees the title in the final page.
4. The render classifier treats it as missed.
5. The same text may be inserted into `wp_tl_translations_i18n`.

That is a duplicate ownership bug. The string belongs to the post table, not to
generic `i18n`.

## 2. Translation Tables and Ownership Rules

The database has four translation memory tables:

| Table | Ownership meaning |
| --- | --- |
| `wp_tl_translations_terms` | Text owned by real WordPress terms and taxonomy records |
| `wp_tl_translations_post` | Text owned by a real `wp_posts.ID` |
| `wp_tl_translations_postmeta` | Text owned by a real `wp_postmeta` value attached to a post |
| `wp_tl_translations_i18n` | Text with no reliable post, term, or postmeta owner |

Decision:

- Do not save template or unknown rendered text into `wp_tl_translations_post`
  with `post_id = 0`.
- `wp_tl_translations_post.post_id` must always refer to a real `wp_posts.ID`.
- If a template, block, navigation, or reusable block exists as a real row in
  `wp_posts`, use its real post ID.
- If the text has no real source owner, save it into `wp_tl_translations_i18n`.

## 3. Source Scan Order

The source scan should be deterministic. The proposed order for `wp_posts`
content is:

1. `attachment`
2. `nav_menu_item`
3. `wp_navigation`
4. `wp_block`
5. `wp_template_part`
6. `wp_template`
7. `page`
8. `post`
9. public custom post types
10. WooCommerce product post types
11. internal/system post types to skip

The order matters because global or reusable objects should be discovered
before ordinary pages and posts that reference them.

## 4. Structured Discovery Pipeline

Structured discovery is the source of truth. It reads content from the database
where WordPress actually stores it.

### Terms

Process:

1. Loop over `wp_terms` and related taxonomy data.
2. Normalize translatable term values.
3. Insert missing rows into `wp_tl_translations_terms`.
4. Register hooks for term create, update, and delete.

Examples:

- term name
- term description
- taxonomy labels that are stored as term data

### Posts

Process:

1. Loop over `wp_posts` in the decided post type order.
2. Skip internal/system post types that are not frontend content.
3. Insert owned post fields into `wp_tl_translations_post`.
4. Register hooks for post create, update, trash, restore, and delete.

Examples:

- `post_title`
- `post_content`
- `post_excerpt`
- block template content when it exists as a real `wp_posts` row
- navigation content when it exists as a real `wp_posts` row

### Post Meta

Process:

1. Loop over `wp_postmeta`.
2. Keep only meta keys that are frontend translatable.
3. Insert owned meta strings into `wp_tl_translations_postmeta`.
4. Register hooks for postmeta add, update, and delete.

Examples:

- attachment alt text
- selected SEO meta values
- selected WooCommerce/product meta values
- selected custom fields

Important: this table is for post-owned metadata only. It should not be used for
generic rendered strings.

## 5. Gettext Capture Pipeline

Frontend gettext strings are a separate source. They should not be discovered
by scanning PHP files because scanning code tells us what exists, not what is
actually used on the frontend.

Decision:

Use WordPress gettext hooks as lightweight collectors:

- `gettext`
- `gettext_with_context`
- `ngettext`
- `ngettext_with_context`

The hook callback must not write to the database.

Runtime flow:

```txt
Frontend request starts
  -> gettext hooks run many times
  -> plugin validates request area
  -> plugin stores normalized strings in memory only
  -> request finishes
  -> shutdown enqueues captured batch
  -> cron/background/admin process bulk-inserts into i18n table
```

Request areas to capture:

- `frontend`
- `ajax_frontend`
- selected `rest` requests when they serve frontend UI

Request areas to skip:

- `admin`
- `ajax_admin`
- `cli`
- `cron`
- `login`

This avoids slowing every gettext call with SQL or heavy logic.

## 6. Rendered HTML Parsing Pipeline

Rendered HTML parsing is still useful, but it should not be the primary source
of truth.

New role:

- It proves what text was actually used on a frontend page.
- It helps with render-time replacement.
- It acts as a fallback collector for strings that have no structured owner and
  were not captured through gettext.

It should not blindly insert every missed HTML segment as `i18n`.

## 7. Render-Time Page Process

For a frontend page request:

1. Detect request area.
2. Start gettext collection if the request is frontend.
3. Start output buffering if the response is frontend HTML.
4. Let WordPress render the page normally.
5. Parse the final HTML.
6. Build page context:
   - current post ID, if singular
   - attached term taxonomy IDs
   - current URL
   - request area
7. Preload renderable translations:
   - current post rows
   - current postmeta rows
   - current term rows
   - relevant i18n rows
8. Replace matching text in the HTML.
9. Collect unknown visible HTML segments as fallback candidates.
10. At shutdown, enqueue gettext and fallback candidates for bulk processing.

## 8. Ownership Resolution Before i18n Insert

This is the key rule that prevents duplicates.

Before saving any HTML-parsed text into `wp_tl_translations_i18n`, resolve
ownership:

```txt
HTML segment
  -> normalize text
  -> hash normalized text
  -> check post, postmeta, and terms translation tables
  -> if owned hash exists: do not insert i18n
  -> if gettext capture exists for same request/hash: save as gettext i18n
  -> otherwise save as rendered_html i18n fallback
```

The ownership check must include all statuses, not only renderable statuses.

Statuses that must be considered owned:

- `discovered`
- `machine`
- `reviewed`
- `ignored`
- `needs_review`

Reason: a discovered but untranslated post title is still owned by the post. It
must not be duplicated into `i18n`.

## 9. Owned String Index

To make ownership resolution cheap and explicit, introduce an internal service:

```txt
OwnedStringIndex
```

Responsibilities:

- Given a normalized hash, answer whether this string already exists in:
  - `wp_tl_translations_post`
  - `wp_tl_translations_postmeta`
  - `wp_tl_translations_terms`
- Include all statuses.
- Prefer current page context when available.
- Cache results per request.

Minimum API idea:

```php
interface OwnedStringIndex {
    public function existsOwnedHash(string $originalHashRaw): bool;

    public function existsOwnedHashForPage(
        string $originalHashRaw,
        int $postId,
        array $termTaxonomyIds
    ): bool;
}
```

The first implementation can query the existing split translation tables. If it
becomes too slow, add a dedicated source index table later.

## 10. i18n Storage Rules

Only save to `wp_tl_translations_i18n` when there is no real structured owner.

Valid `i18n` sources:

| Source | Meaning |
| --- | --- |
| `gettext` | Captured from WordPress gettext hooks during a frontend request |
| `rendered_html` | Visible HTML text that has no post, term, postmeta, or gettext owner |
| `theme` | Theme-level fallback, if still needed as a broad source |
| `plugin` | Plugin-level fallback, if domain/source is known |

Recommended metadata for `i18n` rows or a related metadata table:

- `source_type`: `gettext` or `rendered_html`
- `domain`: gettext text domain, when available
- `context`: gettext context or DOM context
- `plural`: plural pair, when available
- `source_url`: current frontend URL where the string was observed
- `selector`: stable selector or DOM path, when available
- `request_area`: `frontend`, `ajax_frontend`, or selected `rest`

The current `source` and `reference` columns are probably not enough for a good
admin experience because they do not identify where a rendered string appeared.

## 11. Bulk Insert Rule

All discovery should fan out to enabled target languages.

If the default language is `en` and target languages are `fr`, `es`, and `ar`,
discovering one source string creates one row per target language.

Do not create translation rows for the default/source language.

## 12. Hooks to Keep Content Fresh

Structured tables need lifecycle hooks so they stay synchronized.

### Terms

Needed events:

- create term
- update term
- delete term

Effect:

- create/update inserts or refreshes current term strings
- delete removes or cascades term-owned translation rows

### Posts

Needed events:

- create post
- update post
- trash post
- restore post
- delete post

Effect:

- create/update discovers current post fields
- update removes stale field hashes for that post
- delete removes or cascades post-owned translation rows

### Post Meta

Needed events:

- add post meta
- update post meta
- delete post meta

Effect:

- add/update discovers selected frontend meta keys
- delete removes stale meta-owned translation rows

### Plugins and Themes

Needed events:

- plugin installed
- plugin updated
- plugin deleted
- theme installed
- theme switched
- theme updated
- theme deleted

Effect:

- clear gettext capture caches for affected domains
- optionally mark old gettext strings as stale
- allow frontend usage to rediscover active strings naturally

Do not scan all PHP files as the primary source of truth. Frontend gettext
capture is more accurate because it records only strings that actually run in
frontend requests.

## 13. Render Replacement Priority

When replacing a rendered HTML segment, use this priority:

1. Current page owned content:
   - current post fields
   - current postmeta
   - visible terms
2. `i18n` gettext strings captured or already known for this frontend source.
3. `i18n` rendered HTML fallback.

If the same normalized string appears in multiple owned sources with different
translations, rendered HTML alone cannot prove the correct owner. In that case,
prefer the current page context, and avoid creating new fallback `i18n`.

## 14. Decisions

1. `post_id = 0` must not be used in `wp_tl_translations_post`.
2. HTML parsing must not directly insert unknown text into `i18n`.
3. HTML parsing must produce fallback candidates.
4. Fallback candidates must pass ownership resolution before `i18n` insert.
5. Gettext capture must be frontend-only and memory-only during the request.
6. Gettext and HTML fallback inserts must be bulk processed after the request.
7. Structured database scans are the source of truth for posts, terms, and
   postmeta.
8. Rendered HTML is the source of truth for "this appeared on the page", not
   for "this is the owner of the text".

## 15. Open Questions

These should be decided before implementation:

1. Should `i18n` metadata be added as new columns or as a separate
   `wp_tl_translations_i18n_meta` table?
2. Should `OwnedStringIndex` use existing translation tables only, or should it
   get a dedicated source index table?
3. Which postmeta keys are frontend translatable by default?
4. How should stale gettext strings be marked when a plugin or theme is removed?
5. Should REST capture be enabled by default, or only for routes known to serve
   frontend UI?

## 16. Implementation Direction

Recommended implementation order:

1. Stop using `post_id = 0` for template/no-owner strings.
2. Add `OwnedStringIndex`.
3. Change rendered HTML discovery to produce fallback candidates.
4. Add ownership resolution before `i18n` discovery.
5. Add frontend gettext collector.
6. Add shutdown queue and background bulk processor.
7. Add lifecycle hooks for terms, posts, postmeta, plugins, and themes.
8. Improve `i18n` metadata for admin "View" and selector scroll behavior.

# The five tables (column shapes, recap)

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