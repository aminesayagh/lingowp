# TransLangs — Split-Field Handling (post_content & rich HTML) — Implementation Spec

Audience: the engineer implementing translation of `post_content` and other
rich/HTML fields. Pairs with `translangs_architecture_v4.md` (schema, keys),
`translangs_type5_parsing_spec.md` (the shared unit extractor), and
`translangs_pipeline_v4.md` (discovery/render). Reuses the shared normalizer
and key chokepoint defined in the architecture — never reimplement them.

---

## 0. The problem this solves

A field like `post_content` is not text — it is block/builder HTML:

```html
<!-- wp:paragraph --><p>This is an example page…</p><!-- /wp:paragraph -->
<!-- wp:quote --><blockquote class="wp-block-quote">
<!-- wp:paragraph --><p>Hi there! I'm a bike messenger…</p><!-- /wp:paragraph -->
</blockquote><!-- /wp:quote -->
<!-- wp:paragraph --><p>…go to <a href="…">your dashboard</a> to delete…</p><!-- /wp:paragraph -->
```

Treating the whole blob as one translation unit is wrong: a translator can't
work on it, machine translation mangles the `<!-- wp:* -->` markers, and a
one-paragraph edit would invalidate the entire body. The body must be broken
into its translatable pieces (each paragraph / heading / list item / quote
paragraph), translated independently, and reassembled — **without depending on
the builder** (Gutenberg vs Elementor vs Classic structure differently).

---

## 1. Two identity strategies — atomic vs split fields

The plugin now classifies every translatable field as one of two kinds:

### Atomic fields (unchanged behavior)
Short, single-value fields: `post_title`, `post_excerpt`, term `name` /
`description`, scalar post meta, attachment title/caption/alt.
- **Identity = locational**, zero tail: `[type][owner][H(ref1|ref2)][0×15]`.
- Edit → translation **preserved**, flagged needs_review via
  `current_hash` ≠ `translated_against_hash` (architecture D26/D27).

### Split fields (NEW — this spec)
Rich/HTML fields whose value is a document of many pieces: `post_content`,
and (per §9 decision) optionally rich postmeta (ACF WYSIWYG/HTML).
- The field is **broken into N sub-strings** (one per block-level text unit).
- **Each sub-string is its own source row**, identity = **text-tailed**, like
  type 4: `[type][owner][H(ref1|ref2)][text_tail:15]` where `text_tail` =
  first 15 bytes of the SHA-256 of the normalized sub-string text.
- No block position, no block path, no builder-specific anchor — the parser
  only has to *find* text pieces, never *name their position*.
- Edit a paragraph → its key changes → that piece falls back to original
  until re-translated; **sibling pieces are untouched**. (Scoped re-work, not
  whole-body re-work. This is the accepted tradeoff for builder-independence —
  same model as type 4 gettext.)

`ref1` for split `post_content` = `post_content`; `ref2` = NULL (the tail, not
ref2, distinguishes the pieces). The full source_key stays unique because the
tail disambiguates within the shared `[type][owner][H(ref1)]` prefix.

---

## 2. The tail — sizing and collision scope (do not "optimize" away)

The 15-byte text tail is sufficient **because of the scope it operates in**,
and this reasoning must be preserved:

- The tail only ever disambiguates sub-strings that **already share**
  `[type][owner_id][H(ref1|ref2)]` — i.e. pieces of the *same field of the
  same post*. That scope is tiny (dozens of paragraphs at most), so 120 bits
  is overwhelming margin against collision.
- Global uniqueness of the full 32-byte `source_key` is carried by the
  `owner_id` segment (different posts → different keys) — NOT by the tail.
- **Therefore the `owner_id` segment must never be shrunk or dropped for
  split fields.** Doing so would widen the tail's real collision scope to the
  whole table and break the safety argument. (Note this in code near the key
  chokepoint.)

### Same text twice in one field = one row (by design)
Two identical paragraphs ("Coming soon.") in one body produce the same tail →
same key → **one source row, one translation, both occurrences render
translated.** This is a deliberate merge, not a bug. The only thing it cannot
express is translating two identical paragraphs *differently* — impossible
under text identity, consistent with type 4. Accepted.

---

## 3. Extraction — reuse the type-5 unit extractor

Splitting a field into sub-strings is the **same operation** as type-5
translation-unit extraction (type-5 spec §2–§5). One encoder, now two callers.

For each block-level text unit in the field's HTML:
- Block-level tags (`p`, `h1`–`h6`, `li`, `blockquote`'s inner paragraphs,
  `td`, etc.) each start one sub-string. (Block list: type-5 spec §3.)
- Inline tags inside a unit (`a`, `strong`, `em`, `code`, `span`, `br`, …)
  become numbered `{n}` placeholders — **the paragraph stays ONE unit**, the
  `<a href="…">your dashboard</a>` does not split the sentence.  [CONFIRMED]
- Block scaffolding is never a sub-string and never translated:
  - Gutenberg block comments `<!-- wp:* -->` / `<!-- /wp:* -->`
  - block wrapper elements and their attributes (`<blockquote class="wp-block-quote">`)
  - empty/whitespace-only nodes
- Normalize each sub-string with the shared normalizer before hashing.

The encoder returns, per sub-string: the normalized unit_string (with `{n}`
markers) and a tag_map (inline tag name + attributes) for render-time
rebuild. tag_map is derived live at render (architecture type-5 spec §6
option B), not stored.

### Where extraction runs (differs from type 5)
- **Type 5** parses the *rendered page* in the output buffer.
- **Split fields** parse the field value at the **hook / save layer**, where
  the post ID and field are known — no buffer, no DOM-of-whole-page.
  - Discovery: at `wp_after_insert_post` (pipeline Path A), extract
    `post_content` → N sub-strings → upsert N source rows.
  - Render: in `the_content` (and the rich-meta filter), extract the live
    value → N keys → look up translations → reassemble.

---

## 4. Discovery (split field)

```txt
on wp_after_insert_post (post passes guards):
  html = the field's stored value (e.g. $post->post_content)
  units = extract(html)                       # shared extractor, §3
  current_keys = []
  for each unit:
     tail = first15( sha256( normalize(unit.text) ) )
     key  = [type=1][pack owner][H("post_content"|"")][tail]
     current_keys[] = key
     INSERT IGNORE wp_tl_source
        (source_key=key, type=1, post_id, ref1='post_content', ref2=NULL,
         current_hash = sha256(normalize(unit.text)),   # = tail's full hash
         original_text = unit.unit_string, status=0)
  # cleanup pieces that no longer exist in the body (edited/removed paragraphs):
  DELETE FROM wp_tl_source
   WHERE post_id = :id AND type=1 AND ref1='post_content'
     AND source_key NOT IN (current_keys)
   # FK cascade removes their translations (genuinely-gone paragraphs)
```

Note the asymmetry vs atomic fields: for split fields, an **edited** paragraph
is a removed-key + new-key pair here (not an in-place text update), because
the key embeds the text. That is intentional — it is what scopes re-work to
the changed paragraph. `current_hash` is still written (equals the tail's
source hash); it is not used for staleness on split fields (a changed text is
a different key, not a flagged same key).

---

## 5. Render (split field)

```txt
in the_content (target language, not source):
  html  = live field value
  units = extract(html)                       # same extractor, same order
  for each unit, in document order:
     tail = first15( sha256( normalize(unit.text) ) )
     key  = [type=1][pack owner][H("post_content"|"")][tail]
     hit  = translationMap[key]               # from the page preload
     if hit:
        rebuilt = decode(hit.translated_text, unit.tag_map)   # {n} → real tags
        replace this unit's inner content with rebuilt
     else:
        leave the unit unchanged (untranslated piece renders original)
  return reassembled html
```

- Block scaffolding (`<!-- wp:* -->`, wrappers) is passed through untouched —
  only the text inside each unit is swapped.
- Placeholder decode restores inline tags around translated text (the
  `<a href>` lands back in the right spot even if word order changed).
- Preload: the page's split-field translations come in the **same range query
  already used** — `source_key >= lo AND < hi AND lang_id = :lang` over
  `[type][owner]` — the tail is inside the range, so all body pieces load with
  the title and excerpt in one scan (architecture §6.2). No special query.
- Fail-open: any extraction/parse error on a unit → leave that unit as-is;
  never emit broken HTML. Whole-field parse failure → return original body.

---

## 6. Admin presentation

- Split-field sub-strings appear in the list (admin plan A2) as **separate
  rows under their post**, each one a paragraph/heading/etc., with the inline
  tags shown as readable chips (⟦link⟧ for the dashboard link). The
  translator works paragraph by paragraph, not on the raw blob.
- They group under the same post block as the title/excerpt (grouped by
  owner_id — admin plan UC-AD3), so a post shows: title, excerpt, then each
  body paragraph, in order.
- Ordering in the admin list: present body pieces in **document order**. Since
  the key tail is text-based (not positional), store/return document order
  from the extractor at discovery and sort the admin query by the source row
  `id` (insertion order ≈ document order on the scan that created them). If
  strict re-ordering after edits matters, the admin can re-derive order by
  running the extractor on the live body when opening the post (cheap, admin
  path only).
- "⚠ changed" needs_review does NOT apply to split-field pieces (a changed
  paragraph is a new untranslated piece, shown in the "To translate" tab, not
  "To review"). Atomic fields keep the ⚠ changed behavior.

---

## 7. Edit behavior summary (what the user experiences)

| Action on a body | Result |
|---|---|
| Edit one paragraph's text | That paragraph → new key → appears untranslated (To translate); its old translation is removed by the discovery cleanup; **all other paragraphs untouched** |
| Reorder paragraphs (no text change) | Keys unchanged (text-based) → **all translations stay attached**; only display order shifts |
| Add a paragraph | New key appears in To translate; existing ones untouched |
| Delete a paragraph | Its key removed at discovery → cascade removes its translation |
| Inline link/format changed inside a paragraph | Counts as a text change of that paragraph (tag_map differs / text differs) → that paragraph re-translates; siblings untouched |

This is strictly better than the old whole-body-as-one-string model (any edit
= redo everything) and is builder-independent.

---

## 8. What is explicitly NOT done

- **No block position / block path / Gutenberg clientId in the key.** Tried
  and rejected: builder-dependent and fragile to reordering.
- **No staleness flag for split-field pieces.** Text identity makes a changed
  piece a new key; the needs_review/current_hash machinery stays for atomic
  fields only.
- **No storing of tag_map.** Derived live at render from the current markup.
- **No translating of block scaffolding or attributes** (except inline tag
  attributes preserved verbatim inside tag_map for rebuild).

---

## 9. Decisions to confirm

| # | Question | Recommended default |
|---|---|---|
| SF1 | Which fields are split fields in v1? | `post_content` only; rich postmeta (ACF WYSIWYG/HTML) added later behind the same extractor when needed |
| SF2 | Inline tags inside a paragraph → `{n}` placeholders, paragraph stays one unit? | **Yes** (CONFIRMED by you) |
| SF3 | Admin order of body pieces after edits | document order via live extractor when opening the post (admin path only) |

---

## 10. Decisions log (split-field specific)

| # | Decision | Reason |
|---|---|---|
| SF-D1 | `post_content` is a SPLIT field: extracted into N block-level sub-strings, each its own source row | It is builder HTML, not text; whole-blob translation is unusable and edit-fragile |
| SF-D2 | Split-field identity = text tail (like type 4), NOT locational, NOT block-path | Builder-independent (Gutenberg/Elementor/Classic); parser finds text, never names position |
| SF-D3 | 15-byte tail is sufficient; owner_id segment carries global uniqueness | Tail's collision scope is one field of one post (tiny); never shrink owner_id |
| SF-D4 | Same text twice in a field = one merged row | Text identity; matches type 4; almost always desirable; can't express divergent translations of identical text (accepted) |
| SF-D5 | Reuse the type-5 unit extractor; run it at the hook/save layer (not the page buffer) | One encoder, two callers; hook layer has post_id + field and the field's own HTML |
| SF-D6 | Edited paragraph = remove-key + new-key (not in-place update); siblings untouched | Scopes re-work to the changed paragraph; reordering stays invisible (keys are text-based) |
| SF-D7 | No needs_review/staleness for split pieces; tag_map derived live; scaffolding never translated | Consistent with text identity; avoids storing builder structure |
