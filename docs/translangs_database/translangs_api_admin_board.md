# TransLangs — Admin REST API & Query Spec

Audience: the engineer implementing the admin back end. Pairs with
`translangs_admin_plan.md` (UI/use cases), `translangs_architecture_v4.md`
(schema), and `translangs_pipeline_v4.md` (discovery/render). Every endpoint
lists the UI use case it serves (UC-AD*), the request, the SQL/query to run,
and the response shape.

Conventions used throughout:
- Namespace: `/wp-json/translangs/v1`. All endpoints require an admin
  capability (`manage_options` or a custom `translangs_translate` cap) and a
  REST nonce. Mutations are POST/PUT/DELETE; reads are GET.
- `source_key` travels over the wire as **lowercase hex** (32 bytes → 64
  chars). The server converts hex↔binary; the client never computes keys.
- `lang_id` is the dictionary integer from the config option; the UI usually
  passes the human `code` ("fr") and the server maps it.
- Tables: `s` = `wp_tl_source`, `t` = `wp_tl_translation`. `:lang` = selected
  lang_id. All list endpoints use **keyset pagination**, never OFFSET.
- "Status" mapping (from architecture §4): source 0=discovered, 3=ignored;
  translation 1=machine, 2=reviewed, 4=needs_review. "Original text changed"
  is a *derived* state: `t.translated_against_hash <> s.current_hash`.
- The four product defaults from the admin plan Part C are assumed here
  (apply-everywhere = same words + same type; modal/bulk/import save as
  needs_review; stale = keep-showing; Excel = round trip).

---

## 0. Derived state — the one expression every list reuses

A translation row's effective UI bucket, for the selected language:

```sql
-- effective_state(s,t):
--   no t row .............................. 'to_translate'
--   t.status = 4 .......................... 'review_flagged'      (human)
--   t.translated_against_hash <> s.current_hash .. 'review_changed' (stale)
--   t.status in (1,2) and hashes match .... 'done'
```

Expressed as a CASE the endpoints select:

```sql
CASE
  WHEN t.source_key IS NULL                              THEN 'to_translate'
  WHEN t.status = 4                                      THEN 'review_flagged'
  WHEN t.translated_against_hash <> s.current_hash       THEN 'review_changed'
  ELSE 'done'
END AS effective_state
```

"To review" tab = `review_flagged` ∪ `review_changed`. This is why the list
queries always LEFT JOIN translation and carry both hashes.

---

## 1. Bootstrap & configuration

### GET /config
Serves the language switcher, content-area menu, provider list. (shell, all screens)

```
→ 200
{
  "languages": [
    {"lang_id":3,"code":"fr","name":"French","native":"Français","dir":"ltr"},
    {"lang_id":4,"code":"es","name":"Spanish","native":"Español","dir":"ltr"}
  ],
  "default_lang_id": 3,
  "content_areas": [
    {"key":"page","label":"Pages"},{"key":"post","label":"Posts"},
    {"key":"product","label":"Products"},{"key":"term","label":"Categories"},
    {"key":"media","label":"Media"},{"key":"i18n","label":"Theme & button text"},
    {"key":"html","label":"Templates"}
  ],
  "providers": [{"id":1,"name":"DeepL"},{"id":2,"name":"Google"}]
}
```
Source: the `translangs_languages` / `translangs_providers` options (no DB
query). `content_areas` is static + capability-filtered.

---

## 2. Content lists (the three tabs)  — UC-AD3, AD4, AD7, AD8, AD10, AD11, AD26

### GET /items
The workhorse. Returns records **grouped by owner** with their fields, for
one content area, one tab, one language, with filters and keyset paging.

```
Query params:
  area=page|post|product|term|media|i18n|html      (maps to type / post_type)
  lang=fr
  tab=to_translate|to_review|done
  q_original=...        (optional, search original text)
  q_translated=...      (optional, search translated text)
  origin=all|machine|human          (optional)
  sort=recent|alpha                 (default recent)
  cursor=...            (opaque keyset cursor, optional)
  limit=20              (max 100)
```

**Query — the list (one content area, type 1 example; group by owner):**
The page renders cards per owner, so the API returns rows ordered by owner,
and the client groups — OR the API groups server-side. Group server-side to
keep counts honest. Two-step:

Step 1 — the matching source rows for this area + tab + filters, keyset-paged
by owner then field:

```sql
SELECT
  s.source_key, s.type, s.post_id, s.term_taxonomy_id,
  s.ref1, s.ref2, s.original_text, s.current_hash, s.status AS src_status,
  t.translated_text, t.status AS tr_status,
  t.translated_against_hash, t.provider_id, t.updated_at AS tr_updated,
  CASE
    WHEN t.source_key IS NULL                        THEN 'to_translate'
    WHEN t.status = 4                                THEN 'review_flagged'
    WHEN t.translated_against_hash <> s.current_hash THEN 'review_changed'
    ELSE 'done'
  END AS effective_state
FROM wp_tl_source s
LEFT JOIN wp_tl_translation t
       ON t.source_key = s.source_key AND t.lang_id = :lang
WHERE s.type = :type            -- area → type (media = type 1 attachments + ALT meta)
  AND s.status = 0              -- ignored excluded from all three tabs
  /* tab filter */
  AND ( :tab = 'to_translate' AND t.source_key IS NULL
     OR :tab = 'to_review'    AND (t.status = 4
                                   OR t.translated_against_hash <> s.current_hash)
     OR :tab = 'done'         AND t.source_key IS NOT NULL
                                AND t.status IN (1,2)
                                AND t.translated_against_hash = s.current_hash )
  /* optional filters */
  AND ( :q_original   IS NULL OR s.original_text   LIKE :q_original_like )
  AND ( :q_translated IS NULL OR t.translated_text LIKE :q_translated_like )
  AND ( :origin = 'all'
     OR :origin='machine' AND t.provider_id IS NOT NULL
     OR :origin='human'   AND t.provider_id IS NULL AND t.source_key IS NOT NULL )
  /* keyset: order by owner, then field within owner */
  AND ( :cursor IS NULL OR (s.post_id, s.id) > (:cursor_owner, :cursor_id) )
ORDER BY s.post_id, s.id
LIMIT :limit_plus_one;          -- fetch limit+1 to know if there's a next page
```

Indexes used: `idx_post`/`idx_tt` for the owner ordering + `uniq_translation`
PK for the join. For `to_translate` the `t.source_key IS NULL` branch is an
anti-join — fine at list scale; if it ever lags on huge catalogs, that's the
deferred `idx_admin_queue`-style trigger (architecture §7).

Step 2 — resolve owner display titles in one batched query (so cards show
"About Us (page)"), e.g. for type 1:

```sql
SELECT ID, post_title, post_type FROM wp_posts
WHERE ID IN (:owner_ids);          -- or wp_term_taxonomy join for terms
```

Response (grouped server-side):

```
→ 200
{
  "groups": [
    {
      "owner": {"id":42,"label":"About Us","kind":"page",
                "permalink":"https://site/about"},
      "items": [
        {"key":"a1b2…","field":"post_title","original":"About Us",
         "translation":"À propos","state":"done","origin":"human"},
        {"key":"c3d4…","field":"post_content","original":"We are…",
         "translation":null,"state":"to_translate","origin":null}
      ]
    }
  ],
  "next_cursor": "eyJvIjo0Miwi…",     // null when last page
  "counts": {"to_translate":38,"to_review":12,"done":84}   // see /counts
}
```

### GET /counts  — UC-AD4, AD26 (tab badges + progress bar)
Cheap aggregate for the three numbers + progress, for area+lang+filters.

```sql
SELECT
  SUM(t.source_key IS NULL)                                   AS to_translate,
  SUM(t.status = 4
      OR (t.source_key IS NOT NULL
          AND t.translated_against_hash <> s.current_hash))   AS to_review,
  SUM(t.source_key IS NOT NULL AND t.status IN (1,2)
      AND t.translated_against_hash = s.current_hash)         AS done,
  COUNT(*)                                                    AS total
FROM wp_tl_source s
LEFT JOIN wp_tl_translation t
       ON t.source_key = s.source_key AND t.lang_id = :lang
WHERE s.type = :type AND s.status = 0;
```

Cache per (area, lang) in a transient; invalidate on any write to that area.

---

## 3. Single string — editor load  — UC-AD12, AD13, AD23

### GET /string/{key}?lang=fr
Loads the editor: original, current translation, changed-diff, sibling
languages for "copy from."

```sql
-- the string + selected-language translation
SELECT s.source_key, s.type, s.ref1, s.ref2, s.original_text, s.current_hash,
       t.translated_text, t.status, t.translated_against_hash, t.provider_id
FROM wp_tl_source s
LEFT JOIN wp_tl_translation t
       ON t.source_key = s.source_key AND t.lang_id = :lang
WHERE s.source_key = :key_bin;

-- all other languages (for "copy from another language" + all-langs modal)
SELECT lang_id, translated_text, status FROM wp_tl_translation
WHERE source_key = :key_bin;      -- contiguous PK prefix scan (architecture §6.2)
```

For `review_changed`, the "before" text is the original as of the last
translation. The schema stores only `current_hash`, not the old text — so to
show a real before/after you need the previous original_text. **Decision for
the implementer:** either (a) keep a tiny `prev_original_text` shadow column
updated on edit, or (b) show "original changed" without the literal old words
(diff unavailable). Recommend (a) only if the before/after is must-have;
otherwise (b) and show just the new original prominently.

Response includes `placeholders` for the chip UI (parsed from `{n}` markers,
type-5 spec §5):

```
{ "key":"…","original":"We are a team of {1}designers{/1}…",
  "placeholders":[{"n":1,"label":"bold"}],
  "translation":"Nous sommes…","state":"done",
  "siblings":[{"lang":"es","text":"Somos…"}] }
```

---

## 4. Saving translations  — UC-AD12, AD16, AD20, AD21, AD22, AD23

### PUT /string/{key}/translation   (create or update one language)
Body: `{ "lang":"fr", "text":"…", "mark":"reviewed|needs_review|machine" }`

Server validates: lang_id in config; all `{n}` placeholders present in `text`
that were in `original` (reject otherwise — UC-AD14); capability.

```sql
INSERT INTO wp_tl_translation
  (source_key, lang_id, translated_text, translated_against_hash,
   status, provider_id, created_at, updated_at)
VALUES (:key_bin, :lang, :text, :current_hash, :status, :provider, NOW(), NOW())
ON DUPLICATE KEY UPDATE
  translated_text = VALUES(translated_text),
  translated_against_hash = VALUES(translated_against_hash),  -- stamps against current source
  status = VALUES(status),
  provider_id = VALUES(provider_id),
  updated_at = NOW();
```

`:current_hash` is read from `s.current_hash` server-side (never trust the
client's). This single upsert also implements **Keep & approve** (UC-AD20):
send the existing `text` with `mark=reviewed` — it re-stamps
`translated_against_hash = current_hash`, clearing the changed state.

Concurrent-edit guard (UC-AD31): client sends the `updated_at` it loaded; if
the row's current `updated_at` is newer, return `409 Conflict` with the
server's version; UI offers reload/overwrite.

### DELETE /string/{key}/translation?lang=fr   (Clear — UC-AD22)
```sql
DELETE FROM wp_tl_translation WHERE source_key = :key_bin AND lang_id = :lang;
```

### PUT /string/{key}/translations   (all-languages modal — UC-AD16)
Body: `{ "translations":[{"lang":"fr","text":"…"},{"lang":"es","text":"…"}] }`
Empty texts are skipped (not saved blank). Each becomes the upsert above with
`status = needs_review` [DEFAULT Q2a]. Run as one transaction.

---

## 5. Ignore / Don't-translate  — UC-AD19, AD20(list), AD25

### POST /string/{key}/ignore   (UC-AD19)
Sets source ignored AND deletes its translations (architecture D1), one txn:

```sql
UPDATE wp_tl_source SET status = 3, updated_at = NOW() WHERE source_key = :key_bin;
DELETE FROM wp_tl_translation WHERE source_key = :key_bin;   -- all languages
```

### GET /ignored   (Don't-translate page — UC-AD6)
```sql
SELECT s.source_key, s.type, s.ref1, s.original_text, s.post_id, s.term_taxonomy_id
FROM wp_tl_source s
WHERE s.status = 3
  AND ( :cursor IS NULL OR s.id > :cursor_id )
ORDER BY s.id LIMIT :limit_plus_one;
```

### POST /ignored/restore   (single or bulk — UC-AD20-list, AD25)
Body: `{ "keys":["…","…"] }`
```sql
UPDATE wp_tl_source SET status = 0, updated_at = NOW()
WHERE source_key IN (:key_bins);     -- translations rebuild on demand
```

---

## 6. Apply this translation everywhere  — UC-AD17

### GET /string/{key}/duplicates?lang=fr
Find other records with the **same original words + same type** [DEFAULT Q1]
that could receive this translation. Matching by text needs a comparable
column; `original_text` is longtext (not directly indexable) — so match on
`current_hash` (same normalized text ⇒ same hash), scoped by type:

```sql
SELECT s.source_key, s.type, s.post_id, s.ref1, s.original_text,
       t.translated_text AS existing_translation, t.status
FROM wp_tl_source s
LEFT JOIN wp_tl_translation t
       ON t.source_key = s.source_key AND t.lang_id = :lang
WHERE s.type = :type
  AND s.current_hash = :current_hash      -- same normalized text
  AND s.status = 0
  AND s.source_key <> :key_bin;
```

To make this fast at scale, add (deferred, named-query trigger):
`KEY idx_src_text (type, current_hash)` on `wp_tl_source`. Note in the doc as
the index this feature justifies; ship without it, add if the dialog lags.

### POST /string/{key}/apply-everywhere
Body: `{ "lang":"fr", "text":"…", "targets":["k1","k2"], "overwrite":true }`
Upsert the translation into every target key (the §4 upsert, looping or one
multi-row INSERT … ON DUPLICATE KEY UPDATE). `overwrite=false` skips targets
that already have a translation. All stamped `needs_review`.

---

## 7. Bulk actions  — UC-AD24

### POST /bulk
Body: `{ "action":"machine_translate|mark_reviewed|ignore|clear",
         "lang":"fr", "keys":[…] }`
Large sets → enqueue an **Action Scheduler** job, return `202 Accepted` with a
job id the UI can poll; small sets run inline.

- mark_reviewed:
```sql
UPDATE wp_tl_translation SET status = 2, updated_at = NOW()
WHERE lang_id = :lang AND source_key IN (:key_bins);
```
- clear:
```sql
DELETE FROM wp_tl_translation WHERE lang_id = :lang AND source_key IN (:key_bins);
```
- ignore: per §5 across the key set (txn).
- machine_translate: enqueue; worker calls provider, then the §4 upsert per
  key **in source_key order** (architecture D11), `status=machine`,
  `provider_id` set.

### GET /jobs/{id}   (poll bulk/scan progress)
Returns `{ "state":"running|done|failed", "done":N, "total":M }` from the
Action Scheduler job meta.

---

## 8. Check for new text (scan)  — UC-AD9, AD7

### POST /scan
Body: `{ "area":"page", "scope":{"owner_id":42}|"all" }`
Enqueues discovery: for types 1–3 the save-time re-scan logic over the scope
(architecture pipeline Path A/B1); for type 4–5 it flags that marking-mode
parsing should run. Returns `202` + job id (poll via /jobs/{id}). On
completion the UI refetches /items + /counts.

No direct SQL here beyond the discovery INSERTs already specified in the
pipeline (`INSERT … ON DUPLICATE KEY UPDATE` into `wp_tl_source`).

---

## 9. Machine-translate suggestion (single)  — UC-AD15

### POST /string/{key}/suggest
Body: `{ "lang":"fr" }` → calls the configured provider for `s.original_text`,
returns the draft **without saving** (the human edits then saves via §4).

```
→ 200 { "suggestion":"Nous sommes une équipe de {1}designers{/1}…" }
```
Server strips/re-inserts `{n}` placeholders around the provider call so the
machine never sees or mangles markup (send text with placeholders as opaque
tokens the provider preserves, or split-translate-rejoin).

---

## 10. Preview on site  — UC-AD18

### GET /string/{key}/preview?lang=fr
Returns where to view it; the client opens it.
- types 1–3: build the owner permalink (+ `?translangs_lang=fr` and a
  marking-mode flag so the string is highlighted).
```sql
SELECT post_id, term_taxonomy_id, type FROM wp_tl_source WHERE source_key = :key_bin;
-- → get_permalink(post_id) / get_term_link(...)
```
- type 5: return the stored `source_url` + the selector so the front end can
  scroll/highlight.
```sql
SELECT source_url, ref1 AS selector FROM wp_tl_source WHERE source_key = :key_bin;
```

Response: `{ "url":"https://site/about?translangs_lang=fr&tl_highlight=a1b2",
"selector":null }`.

---

## 11. Media area  — UC-AD29

### GET /items?area=media  (same as §2, plus a preview resolver)
Media = attachment posts (type 1) + ALT meta (type 2), grouped by
attachment_id. Add a batched preview lookup:

```sql
-- thumbnails for the owner attachments on this page
SELECT ID, post_mime_type FROM wp_posts WHERE ID IN (:attachment_ids);
-- thumbnail URL via wp_get_attachment_image_url(ID,'thumbnail') in PHP
```
Each group gains `"preview":{"thumb":"…","mime":"image/jpeg"}` (icon when not
an image).

---

## 12. Menus & Branding  — UC-AD5

A dedicated screen, its own endpoints, because these map to specific sources
(site title/tagline options, nav-menu item labels) rather than content types.

### GET /site-strings?lang=fr
Returns site identity + menu labels with their translations, grouped
(Identity / Main menu / Footer menu). Underlying rows are normal
`wp_tl_source` entries (the appropriate type — typically i18n/option-backed),
filtered to the curated set of site keys; the query is the §2 list scoped to
those keys. Saving reuses §4.

---

## 13. Export / Import (Excel)  — UC-AD27, AD28

### GET /export?area=all&langs=fr,es   → streams .xlsx
One row per source, first column original, one column per requested language.

```sql
SELECT s.source_key, s.type, s.original_text,
       MAX(CASE WHEN t.lang_id = 3 THEN t.translated_text END) AS fr,
       MAX(CASE WHEN t.lang_id = 4 THEN t.translated_text END) AS es
FROM wp_tl_source s
LEFT JOIN wp_tl_translation t ON t.source_key = s.source_key
WHERE s.status = 0 AND ( :area='all' OR s.type = :type )
GROUP BY s.source_key, s.type, s.original_text
ORDER BY s.type, s.id;
```
Include a hidden `source_key` column (hex) so import can match back exactly
without re-hashing/ambiguity.

### POST /import   (multipart .xlsx)  → preview then apply
1. Parse rows; match each to a source by the hidden `source_key` (fallback:
   `current_hash` of normalized original, scoped by type).
2. **Preview** (`?dry_run=1`): return per-row {will_create|will_update|
   unchanged|unmatched} counts — no writes.
3. **Apply**: for each non-empty cell, the §4 upsert, `status=needs_review`
   [DEFAULT Q2a], stamped against current source hash. Batched via Action
   Scheduler for large files; poll /jobs/{id}.

---

## 14. Endpoint → use-case index

| Endpoint | Method | Use cases |
|---|---|---|
| /config | GET | shell, AD1, AD8, AD32 |
| /items | GET | AD3, AD4, AD7, AD8, AD10, AD11, AD29 |
| /counts | GET | AD4, AD26 |
| /string/{key} | GET | AD12, AD13, AD23 |
| /string/{key}/translation | PUT/DELETE | AD12, AD20, AD21, AD22 |
| /string/{key}/translations | PUT | AD16 |
| /string/{key}/ignore | POST | AD19 |
| /ignored, /ignored/restore | GET/POST | AD6, AD20-list, AD25 |
| /string/{key}/duplicates, /apply-everywhere | GET/POST | AD17 |
| /bulk, /jobs/{id} | POST/GET | AD24, AD9 |
| /scan | POST | AD9, AD7 |
| /string/{key}/suggest | POST | AD15 |
| /string/{key}/preview | GET | AD18 |
| /site-strings | GET/PUT | AD5 |
| /export, /import | GET/POST | AD27, AD28 |

---

## 15. Cross-cutting implementation notes

- **Capability + nonce on every route.** Reads need the translate cap; writes
  additionally verify nonce. Reject otherwise (403).
- **Hex keys in/out.** One helper does hex↔binary; endpoints never accept raw
  binary or let the client compute keys.
- **`current_hash` is server-authoritative.** Every save reads it from the
  source row to stamp `translated_against_hash`; never trust a client value.
- **Keyset pagination everywhere.** Cursors are opaque base64 of the last
  (owner_id, id) or (id). No OFFSET.
- **Counts are cached** per (area, lang) and invalidated on writes to that
  area, so tab badges don't re-aggregate on every keystroke.
- **Placeholder validation** (`{n}` present/intact) happens server-side on
  every translation save, not just in the UI (UC-AD14).
- **Big operations return 202 + job id**; the UI polls /jobs/{id}. Inline
  only for small sets.
- **Two indexes this API would justify** if lists/dialogs lag at scale, both
  deferred per architecture §7: `idx_src_text (type, current_hash)` for
  apply-everywhere/import matching, and a translation review-queue index for
  the To-review tab. Ship without; add on measured need.
```