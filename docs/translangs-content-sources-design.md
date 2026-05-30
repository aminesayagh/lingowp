# TransLangs — Content Source Architecture

> Design record for how translatable content is **discovered**, **stored**, and
> **replaced**, across the different kinds of content in a WordPress site.
> This captures decisions made jointly; it is the reference to build against.

---

## 1. The problem we are solving

The original engine discovers strings as a **side effect of rendering a
translated page**. That has two painful consequences:

1. **Discovery only fires on non-default-language URLs.** A page visited in the
   default language never adds its strings to the table.
2. **Discovery is per-page-render and per-language-pair.** With 4 languages, an
   admin would have to surf the whole site in 3 languages to populate the
   table — and imported content (e.g. 1000 products from a CSV) would never be
   discovered until each product page is actually viewed.

This is not an acceptable authoring experience. The fix is to **discover content
from where it actually lives**, not only from rendered HTML.

---

## 2. Core principle: discover by source, not by page

Text lives in different places. We discover it from the most authoritative
source for each kind, instead of re-scraping it from every rendered page.

| Content kind | Canonical home in WordPress | How we discover it |
|---|---|---|
| Media metadata (alt, title, caption, description) | The attachment (`_wp_attachment_image_alt`, post fields) | Read the attachment directly |
| Posts / Products / CPT fields (title, content, excerpt) | `wp_posts` / `wp_postmeta` | Read the entity record directly |
| Taxonomy (term name, description) | `wp_terms` / `wp_term_taxonomy` | Read the term directly |
| Template / theme chrome ("Add to cart", widget headings, menu labels) | No single DB home — produced by templates | HTML parsing (the existing output-buffer path) |

**Key insight:** Don't discover the same text 1000 times from 1000 rendered
pages when it lives once in a table. Read structured content once, keyed to the
entity it belongs to.

---

## 3. Discovery vs. Replacement are separate concerns

This distinction is essential and easy to conflate.

- **Discovery** = finding a translatable string and creating its row(s) in the
  translation memory (status `discovered`). Source-driven (DB or parser).
- **Replacement** = at render time, swapping the original text in the HTML for
  its stored translation.

The two are **joined at render time by structural identity** (see §5), not by
re-discovering during the render.

### Behaviour per content kind

| Content kind | Discovery | Replacement |
|---|---|---|
| **Media** | From the attachment | From the attachment — parser no longer touches `<img alt>` at all |
| **Posts / Products / CPT** | From the entity record (DB) | Still happens in the rendered HTML, matched back to the entity |
| **Taxonomy** | From the term record (DB) | In rendered HTML, matched to the term |
| **Template chrome** | HTML parsing | HTML parsing |

> Media is the one kind that leaves the parser **entirely** (both discovery and
> replacement), because the `<img alt>` in the HTML comes *from* the attachment
> meta — so we can replace it by reading the attachment, never by parsing.

### The render-time classifier (the keystone rule)

When a singular entity page renders (e.g. `/fr/product/widget`), the parser sees
every text node. For each node we ask:

> **Does this text match a known field of the current entity?**
> (the product's title, excerpt, content, a translated meta field)

- **Match → structured content.** Use the **entity-scoped** translation
  (`source_id` = this product). The product translates independently.
- **No match → template chrome.** It did not come from the entity's own fields,
  so it belongs to the **template bucket** (`source_id` = NULL), shared across
  that context.

The entity's own field values *are* the classifier. No guessing.

The render path therefore needs the current entity in context, available via
`get_queried_object()` on singular requests.

---

## 4. Discover on every visit, write all languages

Two behavioural changes to the discovery trigger:

1. **Discover on every front-end visit — including the default language.**
   Visiting `/about` (default English) still parses the page and discovers its
   template strings. The output buffer must stop early-returning on
   default-language requests.

2. **Each discovered string gets a row for every enabled non-default language
   at once.** With `en` default and `fr`/`es`/`ar` enabled, discovering a new
   string inserts **3 rows** (`en→fr`, `en→es`, `en→ar`), all status
   `discovered`, in bulk.

**Consequence:** a single normal visit to a page — in *any* language, including
the default — populates the whole language matrix for that page. The admin never
has to surf the site in multiple languages.

**The AJAX / dynamic-content gap self-heals.** If a piece of content is loaded
dynamically and missed on one visit, the next visit that renders it (in any
language) discovers it for all languages. Discovery does not need to be perfect
on the first try.

### Acknowledged tradeoff

Removing the default-language skip means **every front-end page is now buffered
and parsed**, including default-language pages that previously had zero overhead.
Discovery is a cheap `SELECT` + idempotent `INSERT IGNORE`, but the parse runs on
every page load. Acceptable for the discovery model we want; a cache layer later
makes it moot.

---

## 5. Identity model (the schema change)

### Why `source_url` was wrong

The old uniqueness was `(source_lang, target_lang, original_hash)` — text-based.
Two products with the same title would **collide** and silently share one row
(via `INSERT IGNORE`), which breaks per-entity scoping. And `source_url` as a
locator is fragile: it changes when a title/slug changes.

### The model: structure **and** text together

A row is identified by **structure plus text**:

```
(source_lang, target_lang, source_id, source_type, context, original_text)
```

| Column | Meaning |
|---|---|
| `source_id` | Post / page / product / attachment / term ID. **NULL ⇒ template chrome.** |
| `source_type` | One of the enumerated types below. **NULL ⇒ template.** |
| `context` | Field name for entities (`title`, `alt`, …). **Empty (`''`) for template chrome.** |
| `original_text` | The source string itself. Part of identity — see below. |

**Why text is part of identity (not structure alone):**

We considered structure-only identity (`source_id` + `context`) but it fails on
both ends:

- *Template chrome:* there is no reliable per-slot `context`. Two different menu
  links can land on the same breadcrumb; a precise DOM selector is fragile and
  per-page. So structure alone can't separate template strings. **Text can** —
  and that matches the desired behaviour (below).
- *Entities:* `source_id` + field name already separates fields. Text is not
  needed to disambiguate *across* entities, but including it is harmless and
  keeps one uniform key.

So one key shape covers both kinds, with `context` carrying the field name for
entities and being empty for template.

### `source_type` enumeration

```
source_type:
  - media        (attachments: alt, title, caption, description)
  - taxonomy     (terms: name, description)
  - menu         (nav menu items)
  - widget       (widget titles, labels)
  - page
  - post
  - product
  - cpt          (any other custom post type)
  - NULL/template  (theme/template chrome — implied by source_id IS NULL)
```

### The invariant

> **`source_id IS NULL` ⇒ the content is template chrome.**

A column check, not a string-prefix convention. Clean and queryable.

### `source_url` is removed

The URL is **derived** from `source_id` + `source_type` at render time (via
permalink / attachment / term-link helpers), so a title or slug change never
invalidates stored data. A `deriveSourceUrl(source_id, source_type)` helper
returns the URL on demand; `NULL` source_id ⇒ no single URL (template).

---

## 6. How identity behaves per content kind

### Template chrome (`source_id` NULL, `context` empty) → keyed by text

Within the template bucket, **identical text shares one translation.** If a page
has the same call-to-action text 3 times, they collapse to **one row, one
translation.**

This is a deliberate simplicity decision, not a limitation:

> If an admin wants three same-text buttons translated differently, they should
> change the **original** text first. Forcing per-occurrence template
> translations is over-engineering a need that almost never exists, and it would
> require fragile positional context or manual disambiguation. If three buttons
> accidentally share text, one shared translation for all three is the **correct
> and expected** outcome.

Context is therefore **not** part of template identity. (It may still be stored
as informational metadata — e.g. a breadcrumb hint showing where the string was
first seen — but it does not affect which row a string maps to.)

### Entities (media, posts, products, CPT, taxonomy) → keyed by field

Identity is `source_id` + `source_type` + `context` (the **field name**) +
text. The field name is essential: a product whose `title` and `excerpt` happen
to hold the same text still needs **two separate rows**, because each field is
translated independently.

```
title
content
excerpt
alt
caption
description
name            (taxonomy term name)
```

Field names are unambiguous because they are read directly from the entity's
record, never inferred from DOM position.

### Worked examples

| Case | source_id | context | text | Result |
|---|---|---|---|---|
| 3 identical CTAs on one page | NULL | '' | "Buy now" | **1 shared row** |
| Two menu links, same text | NULL | '' | "About us" | **1 shared row** (share by design) |
| Product title vs excerpt, same text | 1234 | `title` / `excerpt` | "Premium Widget" | **2 rows** (per field) |
| Two products' titles, same text | 10 / 20 | `title` | "Widget" | **2 rows** (per entity) |
| Same image used on 5 pages | 42 | `alt` | "Team photo" | **1 row** (per media item) |

---

## 7. The render-time lookup (why this is correct)

At render, a string is looked up by its **full identity**, never by text alone:

- **Entity content:** the renderer knows the current entity
  (`get_queried_object()`), so it looks up
  `(langs, source_id, source_type, context=field, text)` → exactly one row.
- **Template chrome:** looked up by `(langs, source_id=NULL, text)` within the
  template bucket → one shared row.

This is why the earlier "look up by hash alone" idea was **wrong**: text alone is
ambiguous when the same words exist in different entities/fields. Identity always
combines structure **and** text, so the lookup is unambiguous.

### The role of `original_hash` (it returns, honestly)

`original_text` is `longtext` and cannot sit in a unique index directly. So we
keep a short companion column:

```
original_hash = sha256(normalized(original_text))
```

Its **only** job is to be the indexable stand-in for the text inside the unique
key and the render lookup. It is **not** identity on its own and is **never**
queried alone — always together with the structural columns. This is the honest,
non-load-bearing role we landed on after rejecting hash-only identity.

Practical unique key (longtext-safe, within MySQL's 3072-byte index cap via
prefix indexing):

```
UNIQUE (source_lang, target_lang, source_id, source_type(20), context(191), original_hash)
```

Normalization (collapse whitespace, decode entities, trim) still applies before
hashing, so `"About   us"` and `"About us"` are the same string.

---

## 8. Admin experience implications

- Products / posts / media become **enumerable** in the admin — the table can
  list "all products and their translation status" without anyone visiting the
  pages. No more surfing the site in 3 languages.
- Each entity is translated as a **unit** (its fields grouped), separate from
  template chrome.
- Media alt/title/caption/description is translated **once per media item** and
  applies everywhere that image is used.
- A **scan / refresh button** (discussed) remains useful as an explicit
  "discover everything now" action that fetches and reads sources server-side,
  reusing the same discovery pipeline — handling the 90% static case, with
  dynamic content still self-healing on real visits.

---

## 9. Build order

1. **Schema migration** — add `source_id` + `source_type`, drop `source_url`,
   new unique key `(source_lang, target_lang, source_id, source_type(20),
   context(191), original_hash)`, set `context` `NOT NULL DEFAULT ''` and widen
   it, keep `original_hash` purely as the indexable stand-in for `original_text`
   (computed `sha256(normalized(text))`), bump DB version + `maybeUpgrade()`
   path (explicitly drop the old `uniq_translation` key and `source_url`
   column), add `deriveSourceUrl()` helper.
2. **Media reader** — discover + replace alt/title/caption/description from the
   attachment; parser stops handling `<img alt>` entirely. (Sets the
   entity-keyed pattern the others follow.)
3. **Structured-content reader** — posts / products / CPT (and taxonomy):
   discover entity fields from the DB keyed to the entity; at render, classify
   text nodes against the current entity's fields → entity-scoped; unmatched →
   template bucket.
4. **Routing fix** — stop forcing `pagename` in `LanguageRouteRegistrar`; let
   WordPress resolve the stripped path so posts/products/archives/CPT URLs work
   and the buffer engages on them.

---

## 10. Open / deferred

- **Translated slugs** (`/fr/a-propos` vs `/fr/about`) — pure SEO polish,
  deferred until the above is solid.
- **MySQL unique-index byte cap** (3072 bytes, utf8mb4) — `source_type` and
  `context` are prefix-indexed in the unique key so it fits.
- **Background batching** for the scan button on large sites — synchronous is
  fine to start; batch later if needed.

---

## 11. Glossary of decisions (quick reference)

| Decision | Choice |
|---|---|
| Discover on default-language visit? | **Yes** |
| Rows written per discovery | **One per enabled non-default language** |
| Same text in product vs menu | **Scoped per entity (independent)** |
| Store `source_url`? | **No — derive from `source_id` + `source_type`** |
| Template chrome identity | **By text (within the template bucket); context not part of key** |
| Same text repeated in template | **Shares one translation; change the source to differ** |
| Entity identity | **`(langs, source_id, source_type, context=field, text)`** |
| Identity = structure or text? | **Both together — never text alone, never structure alone** |
| `original_hash` role | **Indexable stand-in for `original_text` in the key/lookup only** |
| `source_id IS NULL` | **Means template chrome** |
| Media in the HTML parser | **Removed entirely (discovery + replacement)** |