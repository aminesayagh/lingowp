# TransLangs — Text Resolution Pipeline (v5)

How stored translations come back **out** of the database and onto the page.
Companion to `database_structure.md` (what's stored) and
`text_extraction_pipeline.md` (how it's filled). Source of truth:
`StructuredRenderResolver`, `GettextResolver`, `PublicRenderPipeline` +
`HtmlRenderer`, and the repository `fetch*` methods.

---

## 1. Overview

Resolution mirrors extraction: the same five kinds, the same two lookup models, the
same normalizer. Each render channel hooks WordPress where the text appears, looks
up a translation for the **current request language**, and substitutes it — falling
back to the original text on a miss.

| Channel | Hooks / entry | Reads | Lookup model |
|---------|---------------|-------|--------------|
| Structured: post | `the_title`, `the_content`, `get_the_excerpt` | `tl_post_translation` | hash-link |
| Structured: term | `get_term`, `get_terms`, `get_the_terms` | `tl_term_translation` | hash-link |
| Structured: meta | `get_post_metadata` | `tl_meta_translation` | parent-id |
| gettext | `gettext` / `gettext_with_context` | `tl_staticText_translation` | parent-id (by reference) |
| html | output buffer (preview) | `tl_html_translation` | hash-link |

Three invariants:
- **Per-request, batched.** Maps are preloaded in batches (one query per WP_Query /
  per content block), never one query per string.
- **Fallback to original.** A missing map entry = untranslated *or* ignored (ignore
  deletes the child) ⇒ the original text renders unchanged.
- **Any child renders.** Render reads filter on `lang_code` only — machine,
  reviewed, and needs_review translations all show. No status filter.

---

## 2. Activation gate

Every resolver computes "should I act?" once per request (`isActive`):

```
shouldTranslate()          // normal frontend GET, not admin/ajax/rest/cron/feed/...
&& isTranslatedRequest()   // the resolved language is a target (not the source)
```

Only then is the target `lang` resolved and the filters do work. On a
source-language view the resolvers short-circuit and return input untouched.
(gettext additionally defers until the `wp` action, because its conditional-tag
checks read the main query.)

---

## 3. Structured render — post / term / meta

`StructuredRenderResolver` registers all structured hooks. It keeps two in-memory
maps for the request:

- **`hashMap`** — `"tableKey|hex(original_hash)" => translated_text` (post, term).
- **`metaMap`** — `IdentityKey(location) => translated_text` (meta).

Each has a companion "loaded" set acting as a negative cache, so a hash/location is
queried at most once per request.

### 3.1 Preload (one batch per query)

`the_posts` → `preloadPosts()` runs before any post renders. For every `WP_Post` in
the result it collects:
- `currentHash(post_title)`, `currentHash(post_excerpt)`,
- `currentHash(unit)` for each `post_content` block unit
  (`HtmlUnitExtractor::units`),
- a meta location lookup per allow-listed key.

Then one `ensureHashLoaded('tl_post', …)` + one `ensureMetaLoaded(…)` populate the
maps for the whole page in two queries.

### 3.2 Post fields (hash-link)

- `the_title` / `get_the_excerpt` → `hashValue('tl_post', $text)`: hash the incoming
  text, read `hashMap`, fall back to original.
- `the_content` → split into block units (`HtmlUnitExtractor::render`); each unit is
  hashed, looked up, and the block's inner HTML rebuilt from the translated unit
  (placeholders decoded). Scaffolding/wrappers pass through. This matches the
  split-field discovery exactly, so units line up.

Because the original text is right there on the filter, post/term need **no parent
join** — the hash *is* the key.

### 3.3 Terms (hash-link)

`get_term` / `get_terms` / `get_the_terms` → `resolveTerms()`: batch-hash every
`WP_Term`'s `name` + `description`, load `tl_term`, then mutate each term object's
`name` / `description` in place when a translation exists.

### 3.4 Meta (parent-id)

`get_post_metadata` is a **short-circuit** filter: it must answer without the
original value (returning non-null skips the DB read). So meta is matched by
**location**, not text:

```
lookup = (post_id, meta_key_hash, context_hash = hash(''))   // empty context
```

`ensureMetaLoaded` → `fetchParentTranslations('tl_meta', …)` joins each discovered
parent to its child and keys the result by `IdentityKey`. On a hit, the value is
returned wrapped in an array (core unwraps for `$single`). Never calls
`get_post_meta()` → no recursion.

Only allow-listed keys (+ attachment ALT) are resolvable. **Note:** the render
lookup uses an *empty* context hash, so it resolves the scalar top-level meta value;
nested/array meta leaves (discovered with a context path) are not substituted by
this short-circuit.

### 3.5 Tier-1 ownership marking

`ensureHashLoaded` calls `pageOwned->mark($hash)` for every structured hash it
touches. That is what lets gettext capture later skip a string the page already
rendered as structured content (see extraction doc, Path C).

---

## 4. gettext resolution — staticText

`GettextResolver` on the `gettext` / `gettext_with_context` filters, only on a
translated request:

- Build the same lookup discovery used:
  `(source=gettext, reference_hash = hash(domain|context), original_hash =
  currentHash(text))`.
- `ensureLoaded` → `fetchParentTranslations('tl_staticText', …)`, key by
  `IdentityKey`, return the translation or the incoming string.

Singular only (`ngettext` passes through). Resolution and the separate capture pass
(Path C) share the exact lookup shape, so a captured string later resolves by the
same key.

---

## 5. html render — page buffer

`PublicRenderPipeline` opens an output buffer on a translated request. At
`finish()`:

1. **html REPLACE pass — preview only.** The DOM parse is expensive, so it runs only
   for a logged-in editor with `?translangs_preview`. `htmlTranslations($lang)`
   loads the whole per-language map via `fetchAllHashTranslations('tl_html', $lang)`
   (object-cached 300s; empty array is a valid "nothing to do" cached value).
   `HtmlRenderer::render` walks the body block units and replaces each whose
   `currentHash(unit)` is in the map.
2. **Link rewriting — everyone.** `PublicLinkRewriter` rewrites internal URLs to
   their localized form for all visitors, translated or preview.

Public visitors thus get structured translations (applied by the §3/§4 hooks during
render) + link rewriting, but never pay for the html DOM parse.

---

## 6. Repository read methods

| Method | Used by | Shape |
|--------|---------|-------|
| `fetchHashTranslations($tableKey, $hashes, $lang)` | post, term render | `WHERE original_hash IN (…) AND lang_code = ?` → `hex(hash) => text` |
| `fetchAllHashTranslations($tableKey, $lang)` | html page map | all rows for a language → `hex(hash) => text` |
| `fetchParentTranslations($tableKey, $lookups, $lang)` | meta, gettext | join parent on identity tuples (`WHERE p.status = 0`) → `IdentityKey => text` |

All three filter by `lang_code` and **not** by child status, so every translation
state renders. `fetchParentTranslations` requires the parent to be discovered
(`status = 0`), so an ignored parent yields no translation even if a stale child
lingered.

---

## 7. Caching & fallbacks

- **Per-request maps** (`hashMap` / `metaMap` / gettext map) with negative-cache
  "loaded" sets — each key hits the DB at most once per request.
- **html map** is object-cached per language for 300s (`tl:html:{lang}`).
- **Miss = original.** No map entry ⇒ original text renders. This covers
  untranslated, ignored (child deleted), and source-language requests alike — the
  page is always renderable.
- **Batching.** `preloadPosts` collapses a page's post/meta lookups into two
  queries; `the_content` loads its block units in one.

```
WP_Query ─► the_posts (preload) ─► hashMap + metaMap filled in 2 queries
the_title / excerpt ─► hash text ─► hashMap ─► translated | original
the_content ─► split units ─► hash each ─► hashMap ─► rebuilt block | original
get_term(s) ─► hash name/desc ─► hashMap ─► mutate WP_Term in place
get_post_metadata ─► location lookup ─► metaMap ─► [translation] | $value
gettext ─► (domain|context, text) lookup ─► staticText map ─► translation | original
output buffer ─► [preview] html map ─► replace units ─► link rewrite (all)
```
