# TransLangs — Text Extraction Pipeline (v5)

How source strings get **into** the five parent tables. Companion to
`database_structure.md` (which describes what the tables hold). Source of truth:
`StructuredSourceScanner`, `SourceLifecycleRefresher`, `InitialDiscoveryScan`,
`GettextResolver` + `GettextCaptureWorker`, `PublicRenderPipeline` + `HtmlRenderer`,
and the repository's `reconcile*` / `upsertSources`.

---

## 1. Overview — four discovery paths, five tables

Each kind of source is discovered by a different channel, but they all converge on
the same writer (`SourceTranslationRepository`) and the same dedupe rule
(`INSERT IGNORE` against each parent's `uniq_source`).

| Path | Channel | Fills | When | Write timing |
|------|---------|-------|------|--------------|
| **A** | WordPress save hooks | `tl_post`, `tl_meta`, `tl_term` | on content save | synchronous |
| **B** | Initial / bulk scan | `tl_post`, `tl_meta`, `tl_term` | once after activation (+ admin "rescan") | async batches |
| **C** | gettext filters | `tl_staticText` | any frontend render | async (off-request) |
| **D** | HTML marking mode | `tl_html` | editor loads `?translangs_mark` | synchronous (editor-only) |

Two invariants across every path:
- **Language-independent.** Discovery writes *sources* only. No child/translation
  rows are created at discovery time.
- **Normalized hash is the identity.** Every candidate's text is run through the
  single `StringNormalizer` (strip `\r` → decode entities → `mb_strtolower` → trim →
  collapse whitespace) and SHA-256'd into `original_hash`. Same normalizer
  everywhere, so discovery and render agree.

---

## 2. Path A — structured save-time (post / meta / term)

`SourceLifecycleRefresher` wires WordPress lifecycle hooks to
`StructuredSourceScanner`. A static re-entrancy guard ensures a discovery write
never triggers discovery again in the same request.

| Hook | Handler → scanner | Scope reconciled |
|------|-------------------|------------------|
| `wp_after_insert_post` | `refreshPost($postId)` | this post's `tl_post` + `tl_meta` |
| `added_post_meta` / `updated_post_meta` | `refreshPostMetaKey($postId,$key)` | one meta key in `tl_meta` |
| `deleted_post_meta` | `refreshPostMetaKey(...)` | the now-empty key (prunes it) |
| `created_term` / `edited_term` | `refreshTerm($ttId,...)` | this term in `tl_term` |

`wp_after_insert_post` fires once, after post + meta + terms are all persisted, and
covers attachments. Autosaves are skipped; a revision id is mapped back to its
parent.

**Gates (what is eligible):**
- Post must be a scannable type **and** status `publish` or `inherit`
  (`isScannableStatus`). Drafts/pending/private are not discovered.
- Meta keys: the allow-list (`TranslatableMetaKeys`), plus `_wp_attachment_image_alt`
  for attachments; slug-like keys excluded.
- Terms: only translatable taxonomies (public, narrowable via the
  `translangs_translatable_taxonomies` filter).

**What gets extracted, per table:**

- **`tl_post`** (`candidatesForPost`)
  - `post_title`, `post_excerpt` — each whole field value is one source
    (`field = post_title|post_excerpt`).
  - `post_content` — the stored markup is walked by `HtmlUnitExtractor::units()`
    into block-level units; **one source per unit** (`field = post_content`). Block
    comments/wrappers are scaffolding and ignored. This is the "split field" model:
    the render hooks match the same units.
  - The post's translatable meta keys are folded in here too (same pass).
- **`tl_meta`** (`metaCandidates` → `PostMetaValuePaths::extract`)
  - The meta value is walked into leaf strings; arrays/serialized structures yield
    one source per leaf, each carrying a **context path** (which position in the
    structure). Identity = `(post_id, meta_key_hash, context_hash, original_hash)`.
- **`tl_term`** (`candidatesForTerm`)
  - `name` and `description`, each a whole-value source
    (`field = name|description`).

Empty-after-normalization candidates are dropped (counted as "skipped").

### 2.1 Reconciliation — upsert + carry-forward + prune

Save-time discovery is not just an insert; it **reconciles** the entity's scope
(`reconcileScope`):

1. **Upsert** the current candidates — `INSERT IGNORE` on `uniq_source`. An
   unchanged location is a no-op; a brand-new one inserts (status
   `SOURCE_DISCOVERED = 0`, stamped with `hash_algorithm_version`).
2. **Find stale parents** — still-discovered rows in the same scope (same post /
   meta key / term) whose identity is *not* in the current candidate set. These are
   positions whose text changed.
3. **Carry-forward** — if a stale position maps to exactly **one** current position
   at the same location, copy its existing translations to the new position as
   `needs_review (-1)` (`INSERT IGNORE`, never clobbering an existing translation).
   hash-link copies by `original_hash`; parent_id by parent `id`.
4. **Delete the stale parents.** hash-link orphan children are reclaimed by the
   background sweep; parent_id children cascade via FK.

Empty candidate set ⇒ the whole scope's discovered parents are deleted (e.g. a meta
key removed). Ignored parents (`status = -1`) are never touched by reconciliation.

> Net effect: editing source text **moves** the translation forward flagged for
> review, instead of leaving a "stale" marker. There is no `translated_against_hash`.

---

## 3. Path B — initial / bulk scan

Save hooks only cover content touched *after* activation. `InitialDiscoveryScan`
backfills everything else:

- On activation a flag (`translangs_needs_initial_scan`) is set; on the next `init`
  the first Action Scheduler batch is enqueued (lazily — the AS store may not be
  ready during activation).
- `runBatch` walks published/inherit posts in keyset batches of 200 (`ORDER BY ID`),
  then all terms, calling the **same** `refreshPost` / `refreshTerm` per entity.
- Idempotent: re-running hits the same `uniq_source` rows and no-ops.

The admin "rescan" action runs `StructuredSourceScanner::scan()`, the synchronous
whole-site variant (every scannable post type + every translatable taxonomy),
deduped in-memory by identity then bulk-upserted per route.

---

## 4. Path C — gettext (staticText), render-time capture

`GettextResolver` hooks the `gettext` / `gettext_with_context` filters. On a real
frontend render it does two things:

- **Resolve** (only on a translated request): look the string up by
  `(source=gettext, reference_hash, original_hash)` and return the translation.
- **Capture** (on any genuine frontend load, default language included): buffer the
  string as a discovery candidate.

Capture rules:
- `reference = domain|context` (truncated to 191), `source = gettext`. Singular only
  — `ngettext` passes through (plurals are deferred).
- Gated to real visitor views (not admin/ajax/rest/cron, and only after `wp`).
  Widget-sidebar strings are paused out.
- **Ownership gate, Tier-1**: a candidate whose hash a structured source already
  rendered this request (`PageOwnedHashes`) is skipped — so post/term text wrapped
  in `__()` doesn't also become a static string.
- Throttled once/day per key (object cache).

At `shutdown` the buffered candidates are parked in a transient and **one** Action
Scheduler job is enqueued. `GettextCaptureWorker::run()` then, off-request:
- **Ownership gate, Tier-2**: `OwnedStringIndex` drops any hash already owned by
  `tl_post`/`tl_meta`/`tl_term`/`tl_html`.
- `upsertSources()` the survivors into `tl_staticText` (`INSERT IGNORE`).

Writes are off-request on purpose — managed hosts kill the process on response
flush, so the bulk insert never runs at shutdown.

---

## 5. Path D — HTML marking mode (`tl_html`)

Hardcoded theme/template text isn't owned by any post — it's discovered by an
explicit editor pass. `PublicRenderPipeline` starts an output buffer when an editor
loads a **source-language** page with `?translangs_mark`. In `finish()`:

- `HtmlRenderer::render($html, [], $onUnit)` walks the page `<body>` block units in
  the same DOM pass it uses for rendering, reporting each unit's
  `(selector, text)`.
- Source language on purpose: the captured text is genuine source, so the ownership
  gate (next) correctly excludes structured content.
- **Ownership gate**: `OwnedStringIndex` drops units already owned by
  `tl_post`/`tl_meta`/`tl_term`/`tl_html` (so post content rendered in the page
  isn't re-stored as html).
- Survivors → `HtmlSource(selector, source_url, text)` → `upsertSources()` into
  `tl_html`. `INSERT IGNORE` on `(selector_hash, original_hash)` makes re-marking
  idempotent.

`selector` is for admin display only — html is hash-link, so the translation key is
`original_hash` alone. The write is synchronous, but it happens inside the buffer
callback (before flush) and is editor-only/low-frequency, so it's host-safe.

---

## 6. Cross-cutting rules

- **Dedup is the unique key.** Every path ends in `INSERT IGNORE` against the
  parent's `uniq_source`. Re-discovering the same string is always a no-op.
- **Insert status.** New parents land `SOURCE_DISCOVERED (0)`; they are never
  inserted ignored. Ignoring is an explicit admin action.
- **Ownership precedence.** Structured (post/meta/term/html) > staticText. The
  two-tier gate (Tier-1 request memory, Tier-2 DB index) keeps a visible string in
  exactly one table.
- **No translations created.** Discovery only writes parents. Children appear later
  via the admin board or MT, seeded as machine/needs_review.
- **Hash version.** Each parent stamps `hash_algorithm_version`; a normalizer change
  requires a version bump + re-hash, or discovery and render stop agreeing.

---

## 7. Per-table extraction cheat-sheet

| Table | Trigger(s) | Extractor | Unit of a source | Identity (`uniq_source`) |
|-------|-----------|-----------|------------------|--------------------------|
| `tl_post` | `wp_after_insert_post`, bulk scan | `candidatesForPost` (+ `HtmlUnitExtractor::units` for `post_content`) | whole title/excerpt; one block per `post_content` unit | `(post_id, field, original_hash)` |
| `tl_meta` | post save, `added/updated/deleted_post_meta`, bulk | `PostMetaValuePaths::extract` | one leaf string per value path | `(post_id, meta_key_hash, context_hash, original_hash)` |
| `tl_term` | `created_term`/`edited_term`, bulk | `candidatesForTerm` | whole name / description | `(term_taxonomy_id, field, original_hash)` |
| `tl_staticText` | `gettext` filter → async worker | `GettextResolver` capture | one gettext string | `(source, reference_hash, original_hash)` |
| `tl_html` | editor `?translangs_mark` | `HtmlRenderer` walk | one block-level page unit | `(selector_hash, original_hash)` |

```
save_post / meta / term ─┐
initial + bulk scan ─────┼─► StructuredSourceScanner ─► reconcile (upsert + carry-forward + prune)
                          │
gettext filters ─► capture buffer ─► (shutdown) AS job ─► OwnedStringIndex gate ─► upsert tl_staticText
?translangs_mark ─► output buffer ─► block units ─► OwnedStringIndex gate ─► upsert tl_html
```
