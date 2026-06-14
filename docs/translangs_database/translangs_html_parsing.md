# TransLangs — Type-5 HTML Parsing & Translation Units (Implementation Spec)

Audience: the engineer/agent implementing the type-5 (rendered-HTML
fallback) layer. Companion to `translangs_architecture_v4.md` and
`translangs_pipeline_v4.md`; this document is self-contained for the parsing
work specifically. Where it says "the chokepoint" or "the normalizer," those
are the shared functions defined in the architecture doc §0 — type 5 MUST
reuse them, never reimplement.

---

## 0. What type 5 is (and is not)

Types 1–4 (post fields, post meta, terms, gettext) are replaced by
**WordPress filter hooks**, before the page is assembled. That stays exactly
as it is. **Do not move types 1–4 to HTML parsing.** Hooks are cheaper, know
the source location, and are immune to WordPress's post-filter text changes.

Type 5 is the **fallback for hardcoded text that no hook ever touches** —
strings baked into theme templates / builder output that never pass through
post fields or gettext. HTML parsing is the *only* way to reach them, so it
is the *only* job of the parser.

Execution order is load-bearing and free: hooks run first and replace all
type 1–4 text in the page; the type-5 parser runs **after**, on the
already-hook-translated HTML. So the parser never has to coordinate with the
hooks or "know" what they did — it just sees a finished page (§3).

---

## 1. Two operations, one DOM walk

The parser does at most two things, both in a single pass over the document:

- **REPLACE** (runs on frontend render, when there is anything to replace):
  swap hardcoded text that has a type-5 translation.
- **DISCOVER** (runs ONLY in marking mode / translator session): record
  hardcoded text that has no source row yet, so a translator can be asked to
  translate genuinely-unowned text.

Never two passes. Within the one walk, for each translation unit: try to
replace it, and (in marking mode) check whether it needs saving.

```txt
FRONTEND RENDER (visitor):
  - REPLACE only, and only if the per-URL type-5 map is non-empty
    (skip the whole parse otherwise — most visits do zero DOM work)
  - never DISCOVER, never write

MARKING MODE (translator/admin session):
  - REPLACE + DISCOVER in one walk
  - DOM work is acceptable here; this is the only place it runs at full cost
```

---

## 2. The central problem: a translation unit is NOT a text node

Inline markup splits one human sentence into many DOM text nodes. Example:

```html
<p>The hook is <strong><code>gettext</code></strong> (and its siblings
<code>gettext_with_context</code>). Every time WordPress renders a
<code>__()</code> string, this hook fires.</p>
```

A naive parser sees separate fragments: "The hook is", "gettext", "(and its
siblings", "gettext_with_context", … Translating fragments is wrong: they're
meaningless alone, and word order changes between languages so the pieces
can't be reassembled. The translator must see and translate the **whole
sentence**, with the inline tags preserved in place.

### Solution: block = unit; inline tags → numbered placeholders

A **translation unit = the text content of one block-level element**, with
its inline tags flattened into reversible numbered placeholders.

Encode the example to ONE unit string:

```txt
The hook is {1}gettext{/1} (and its siblings {2}gettext_with_context{/2}).
Every time WordPress renders a {3}__(){/3} string, this hook fires.
```

That single placeholder-string is what you **hash, store as one type-5
source, translate, and store the translation of**. The translator (or
machine) translates the sentence and keeps `{n}…{/n}` wherever they belong in
the target language. At render you reverse the placeholders back to the real
tags. One unit in, one unit out, tags preserved, word order free.

This is the standard approach (WPML/TranslatePress/XLIFF call them inline
tags / markup placeholders).

---

## 3. Block vs inline: where to cut

Walk the DOM. Each **block-level element** that directly contains text starts
one translation unit; all **inline** descendants inside it become
placeholders within that unit.

**BLOCK tags — break units (each is its own unit, recurse into them):**

```txt
p · div · li · ul · ol · h1 h2 h3 h4 h5 h6 · td · th · tr · table ·
section · article · header · footer · main · aside · nav · blockquote ·
figure · figcaption · dd · dt · dl · form · fieldset · address
```

**INLINE tags — become placeholders (stay inside the unit):**

```txt
a · span · strong · b · em · i · u · code · kbd · samp · var · mark ·
small · sub · sup · abbr · cite · q · time · label · br · wbr · del · ins ·
bdi · bdo · s
```

Rules:
- A block element whose children are only inline + text → one unit.
- A block element that contains other **block** elements → do NOT make it a
  unit; recurse and let each inner block be its own unit. (A `<div>`
  wrapping three `<p>`s yields three units, not one.)
- A block with mixed direct text and child blocks → the loose direct text
  forms its own unit at that level; child blocks recurse. (Rare; handle by
  treating contiguous inline+text runs between block children as units.)
- `<br>` is inline → it becomes a placeholder inside the unit (it does not
  split a unit).

---

## 4. Pruning — what the walk skips entirely

Before extracting units, skip whole subtrees. Same mechanism as the existing
type-5 exclusion pipeline.

**Skip subtree and everything under it:**

```txt
script · style · noscript · template · textarea · iframe ·
pre · code (when standalone block of code, not inline) · svg · canvas
```

**Skip if the element or any ancestor carries a skip marker:**

```txt
data-tl-skip   ·   translate="no"   ·   class="notranslate"
```

**Skip the post-body region (the big one):**
The `the_content` hook wraps post body output in a region marker:

```html
<div data-tl-region="content"> … post body … </div>
```

The parser skips everything inside `data-tl-region`. The post body is type-1
content, already handled by hooks, and is where most page text lives —
skipping it removes the bulk of would-be duplicates in one rule. (Only the
body can be wrapped this way; titles, button labels, etc. cannot be wrapped
because the same text also goes into `<title>`, attributes, and JS — see §7.)

---

## 5. The placeholder contract (must be exactly reversible)

The encode/decode pair lives in ONE function (chokepoint discipline). Hash
drift = silent match failure, so determinism is mandatory.

**Encoding `block element → (unit_string, tag_map)`:**
1. Walk the block's children in document order.
2. Text node → append its text to `unit_string`.
3. Inline element → assign the next number `n` (1, 2, 3… in document order),
   append `{n}` … recurse into its children … append `{/n}`. Self-closing
   inline (`<br>`) → append `{n/}`.
4. Record in `tag_map[n]` everything needed to rebuild the tag exactly: tag
   name + full attribute list (e.g. `code` + `class="bg-text-200/5 …"`).
5. **Normalize `unit_string` with the shared normalizer** before hashing /
   storing (same normalize used everywhere — this is what closes most text
   drift).

**The stored type-5 source unit is the normalized `unit_string`** (with the
`{n}` markers in it). Its `source_key` is `H(selector)` of the **block
element** (§6). `original_text` = the unit_string. The `tag_map` is needed
only at render to rebuild tags; store it with the source row (e.g. a JSON
column / serialized in an existing text column) OR reconstruct it from the
live DOM at render time — see §6 decision.

**Decoding `(translated_unit_string, tag_map) → HTML`:**
1. Replace each `{n}…{/n}` with `<tag attrs>…</tag>` from `tag_map[n]`.
2. `{n/}` → the self-closing tag.
3. The text between markers is the translated text; tags slot back around it.
4. Set the block element's inner HTML to the result.

Determinism rules:
- Numbering is strictly document order, every time. Same block → same
  numbering → same hash.
- Attribute serialization for the hash must be stable, BUT: attributes are
  NOT part of the translatable text. Two paragraphs identical in words but
  differing only in a tag's class are the same translation unit for the
  translator. **Therefore hash the unit_string only (text + `{n}`
  structure), NOT the attribute values.** Attributes live in tag_map for
  rebuild, outside the hash. (This prevents a CSS class change from
  orphaning a translation.)

---

## 6. Type-5 identity & storage (fits the existing schema)

- `source_key = [type=5][owner=0][H(selector)][zero tail]` — the block
  element's structural selector, per architecture §1. One block = one
  selector = one source row.
- Selector format: structural path, capped ≤100 chars (existing D15 rule),
  e.g. `main>section:nth-of-type(2)>p:nth-of-type(1)`. Built from stable
  structural landmarks, not volatile auto-generated classes.
- `original_text` = the normalized unit_string (with `{n}` markers).
- `tag_map`: **Decision for the implementer** — two options, pick one and
  note it:
  - (A) **Store tag_map** with the source row (JSON). Render reads it. Pro:
    render doesn't depend on the live DOM matching. Con: a column to carry;
    stale if markup changes (but selector changes too, so it self-heals).
  - (B) **Reconstruct tag_map from the live DOM at render** by re-encoding
    the block, then apply the stored translation's text into the freshly
    derived structure. Pro: no stored tag_map, always matches current
    markup. Con: render must re-encode each translated block.
  Recommendation: **(B)** — simpler storage, always consistent with current
  markup, and at render you are already at the block element anyway. Store
  only the unit_string; derive structure live.

---

## 7. Why only the body is wrapped (and titles/labels are not)

The post body can be wrapped in `<div data-tl-region>` because it is body
HTML in the content area — a wrapping `<div>` is harmless there. Titles,
excerpts, term names, and gettext labels **cannot** be wrapped, because the
same filtered text also lands in places a `<div>`/`<span>` would corrupt:

```txt
the_title  → <title>…</title> in <head>, and title="…" attributes
gettext    → button labels, placeholder="…", aria-label="…", inline JS, JSON
excerpt    → meta description, og: tags
```

So there is exactly one safe region marker (the body), and it happens to
cover most of the text. The scattered leftovers (menus, footer, chrome) are
handled by discovery + the gettext set (§8), with rare harmless duplicates.

---

## 8. Avoiding duplicates at discovery (marking mode only)

When the walk DISCOVERS a unit (no source row for its selector yet), two
guards stop it minting a duplicate of something a hook already owns:

### 8.1 Order handles most of it (no work needed)
Hooks ran first. A gettext or title string that was already translated now
shows the TRANSLATED text in the DOM; its normalized text won't match any
English type-5 source, so it isn't recognized and isn't re-saved.

### 8.2 The per-request gettext set (catches untranslated gettext)
Problem case: a gettext string that is discovered but NOT yet translated
still shows its ORIGINAL text in the DOM, so the parser might save it as
type 5. To prevent that:

- The `gettext` / `gettext_with_context` / `ngettext` hooks already fire as
  the page builds. Each time, push the **normalized original string** into a
  per-request in-memory set `$gettextSeen` (no DB, request-scoped).
- During the walk, before saving a discovered unit, normalize its text and
  check `$gettextSeen`. Present → it's a gettext string, **skip** (do not
  save). Absent → genuine leftover, save it.
- Discard the set at end of request.

This is the cheap, exact-ish dedup: built from a hook you already run, no
query, checked in memory. **Both sides normalized with the shared
normalizer** so they compare equal despite WordPress's post-filter tweaks.

### 8.3 Accepted residual (do not over-engineer)
If WordPress alters text between the gettext call and the final DOM in a way
normalization doesn't fully cover, a string may still slip through as a
duplicate type-5 row. This is HARMLESS: same visible text, never a wrong
translation; the type-4/5 age-out job (architecture §8.2) reaps it if it
goes stale. Do not add DB lookups or fuzzy matching to chase these — the
cost outweighs a tidy-up issue. As the site gets translated, §8.1 makes
these vanish anyway.

Also apply the existing content filters before saving (architecture §9.3):
must contain letters; not a pure date/time/currency/URL/email/phone; minimum
letter ratio; per-request circuit-breaker cap on new type-5 rows.

---

## 9. Render-time replacement for composed units (type 5)

Per-URL type-5 translation map is preloaded as for any type (keyed by
selector-based source_key). For each block unit the walk reaches on a
frontend render:

```txt
1. compute source_key = [5][0][H(selector)][0]
2. look up the per-URL type-5 map
   miss  → leave the block unchanged
   hit   → there is a translated unit_string for this block:
3. derive the live tag_map by encoding the current block (option B, §6)
4. decode (translated_unit_string, tag_map) → rebuilt inner HTML
5. set the block's inner HTML to the rebuilt result
   (staleness applies as everywhere: if the source's current_hash differs
    from the translation's translated_against_hash, show translation +
    flag needs_review per architecture §4 — composed units are no exception)
```

Only blocks with a hit are touched; everything else is left exactly as
rendered. On any parser/DOM failure, fail open: leave the block unchanged
(never emit broken HTML). Use `WP_HTML_Processor` first, Masterminds
HTML5 as fallback, and if both fail, leave the page untouched and log.

---

## 10. Step-by-step summary (what to build)

```txt
PARSE (single DOM walk):
  for each node, depth-first:
    if node/ancestor is pruned (§4)           → skip subtree
    if node is a BLOCK with only inline+text  → it is one UNIT:
        encode → (unit_string, tag_map), normalize unit_string   (§5)
        selector = structural path of this block, ≤100 chars     (§6)
        source_key = [5][0][H(selector)][0]
        REPLACE (frontend, map non-empty):
            map hit → derive live tag_map → decode → set inner HTML  (§9)
        DISCOVER (marking mode only):
            already a source row for this key?      → skip
            text in $gettextSeen (normalized)?      → skip            (§8.2)
            fails content filters / breaker?        → skip            (§8.3)
            else → collect candidate (unit_string)  → batch insert on shutdown
    if node is a BLOCK containing child BLOCKS → recurse (no unit here)

GETTEXT SET (fills as page builds, marking mode):
  gettext/_with_context/ngettext hooks → push normalized original into
  per-request $gettextSeen ; discard at request end                  (§8.2)

WRITES:
  discovery candidates → ONE batched INSERT … ON DUPLICATE KEY UPDATE
  on shutdown, after response sent (never inline)                    (pipeline Path C)
```

---

## 11. Decisions specific to type 5 (log)

| # | Decision | Reason |
|---|---|---|
| P1 | Translation unit = one block-level element; inline tags → numbered `{n}` placeholders | A DOM text node is a fragment, not a translatable sentence; word order changes between languages — the whole block must translate as one unit with tags preserved |
| P2 | Hash the unit_string (text + `{n}` structure) only; attributes live in tag_map, OUTSIDE the hash | A CSS class change must not orphan a translation; attributes aren't translatable text |
| P3 | tag_map derived live at render (option B), not stored | Simpler storage; always consistent with current markup; render is already at the block |
| P4 | type-5 source_key = `H(block selector)`; one block = one source | Selector is the stable, drift-immune identity for hardcoded chrome (text drifts, position doesn't) |
| P5 | Only `post_content` is region-wrapped (`data-tl-region`) and pruned; other types are not wrapped | Body is safely wrappable and holds most text; title/labels/gettext also render into `<title>`/attributes/JS where a wrapper corrupts output |
| P6 | Per-request in-memory `$gettextSeen` set (from the gettext hooks) skips untranslated-gettext duplicates; both sides normalized | Cheap exact-ish dedup with no DB query; normalization closes most WP post-filter drift |
| P7 | Remaining drift duplicates accepted, not chased | Harmless (same visible text, never wrong); age-out reaps them; §8.1 eliminates them as translation progresses; chasing them costs more than it saves |
| P8 | type-5 discovery runs ONLY in marking mode; frontend does replace-only and skips the parse when the per-URL map is empty | DOM work is the one heavy step; confine its full cost to translator sessions; visitors pay ~nothing |
| P9 | Composed-unit render: derive tag_map live → decode → set inner HTML; fail-open on any parse error | Reversible placeholder swap restores tags around translated text; never emit broken HTML |