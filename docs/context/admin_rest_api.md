# TransLangs — Admin REST API (v5)

The admin board SPA talks to one namespace: **`translangs/v1`**. Source of truth:
`src/BackOffice/Infrastructure/Rest/AdminRestController.php`.

---

## Conventions

- **Base:** `/wp-json/translangs/v1`
- **Auth:** every route requires `manage_options`. The SPA sends the WordPress REST
  cookie nonce as the `X-WP-Nonce` header (`wp_rest`). No token/keys.
- **SourceRef (`{key}`):** a source is addressed as `{kind}:{id}` — `kind` is
  `[a-zA-Z]+`, `id` is the parent row id, e.g. `post:123`, `staticText:9`,
  `html:4`. Kinds: `post`, `meta`, `term`, `staticText`, `html`. A malformed ref →
  `400 invalid_key`.
- **Language (`lang`):**
  - On writes and list/counts it must be an **active target** language, else
    `400 invalid_lang`.
  - On `GET /string/{key}` and `/preview` it's taken as-is (no validation).
- **`mark` → child status** (`markStatus`): `machine` → `1`, `needs_review` → `-1`,
  anything else (incl. omitted) → `reviewed` (`2`). So a plain save lands as Done.
- **Placeholders:** when an original carries `{n}` / `{/n}` / `{n/}` tokens, every
  token must appear in the submitted translation, else `400 placeholder_mismatch`.
- **Cursors:** opaque strings (`KeysetCursor`) — `items` uses an owner+id cursor,
  `ignored` uses an id cursor. Pass the previous response's `next_cursor` back
  verbatim; `null` means no more pages.
- **Errors:** `WP_Error` with an HTTP `status` in its data — `400` (validation),
  `404 not_found`, `409 conflict`.

---

## Route index

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/config` | languages, content areas, defaults (bootstraps the SPA) |
| GET | `/items` | paginated board rows for an area + language + tab |
| GET | `/counts` | `{to_translate, to_review, done, total}` for one area |
| GET | `/counts-all` | `{done, total}` per area (sidebar badges) |
| GET | `/ignored` | paginated ignored sources across all tables |
| POST | `/ignored/restore` | un-ignore a set of sources |
| POST | `/scan` | re-run discovery (whole site, or one owner) |
| GET | `/string/{key}` | one source + its per-language siblings |
| PUT | `/string/{key}/translation` | save one translation |
| DELETE | `/string/{key}/translation` | delete one translation |
| PUT | `/string/{key}/translations` | save many languages at once (as needs_review) |
| POST | `/string/{key}/ignore` | mark source ignored (deletes its children) |
| GET | `/string/{key}/duplicates` | other sources with identical text |
| POST | `/string/{key}/apply-everywhere` | copy a translation to chosen duplicates |
| GET | `/string/{key}/preview` | front-end preview URL for the source |

---

## Reads

### `GET /config`
Bootstraps the SPA. No params.
```jsonc
{
  "languages": [{ "code": "fr", "name": "French", "native": "Français", "dir": "ltr" }],
  "default": "en",
  "content_areas": [
    { "key": "page", "label": "Pages" }, { "key": "post", "label": "Posts" },
    { "key": "product", "label": "Products" }, { "key": "term", "label": "Categories" },
    { "key": "media", "label": "Media" }, { "key": "staticText", "label": "Static text" },
    { "key": "html", "label": "Templates" }
  ],
  "providers": []
}
```

### `GET /items`
Paginated board rows.
**Params:** `area` (required: page/post/product/media/term/staticText/html),
`lang` (required target), `tab` (`to_translate`|`to_review`|`done`, default
`to_translate`), `q_original`, `q_translated`, `origin` (`all`|`machine`|`human`),
`cursor`, `limit` (default 20).
```jsonc
{
  "groups": [
    {
      "owner": { "id": 42, "label": "About us", "kind": "page" },
      "items": [
        { "key": "post:42", "field": "post_title", "context": null,
          "original": "About us", "translation": "À propos",
          "state": "done", "updated_at": "2026-06-18 10:00:00",
          "preview": true, "origin": "human" }
      ]
    }
  ],
  "next_cursor": "…|null",
  "counts": { "to_translate": 3, "to_review": 1, "done": 5, "total": 9 }
}
```
Rows are grouped by owner: post/meta by post, term by term; staticText/html are one
card each. (Hash-link kinds are deduped by text upstream — one row per unique
string.)

### `GET /counts`
**Params:** `area` (required), `lang` (required target).
→ `{ "to_translate": int, "to_review": int, "done": int, "total": int }`

### `GET /counts-all`
**Params:** `lang` (required target).
→ `{ "page": {"done":int,"total":int}, "post": {...}, … }` for every area.

### `GET /ignored`
**Params:** `cursor`, `limit` (default 50).
→ `{ "rows": [ {source_key, kind, ref1, ref2, original_text, …} ], "next_cursor": "…|null" }`

### `GET /string/{key}`
One source detail + every language's translation.
**Params:** `lang` (unvalidated; the language whose translation is inlined).
→ `{ "string": { key, kind, field, context, original, translation, state,
updated_at, placeholders:[{n}] }, "siblings": [ {lang_code, translated_text,
status, updated_at} ] }`. `404 not_found` if the ref doesn't exist.

### `GET /string/{key}/duplicates`
**Params:** `lang` (required target).
→ array of other sources of the same kind with identical original text
(`source_key, kind, id, ref1/ref2, original_text, existing_translation, tr_status`).
Mostly meaningful for parent-id kinds — hash-link positions already share one
translation.

### `GET /string/{key}/preview`
Builds a front-end URL that opens the source highlighted in the target language.
**Params:** `lang`.
- html → `{ "url": "<source_url>?translangs_preview=<lang>&translangs_focus=<ref>", "selector": "<css>" }`
- term → term link; post/meta → permalink; both with the same preview query args →
  `{ "url": "…", "selector": null }`
- `404 not_found` if the source is gone; `url` may be `""` if no link exists.

---

## Writes

### `PUT /string/{key}/translation`
Save one translation.
**Params:** `lang` (required target), `text` (required), `mark`
(`machine`|`needs_review`|else→reviewed), `updated_at` (optional, optimistic-lock).
- `404 not_found` if the source is gone.
- `400 placeholder_mismatch` if a token is dropped.
- `409 conflict` (with `server` = current row) if `updated_at` no longer matches.
→ `{ "string": <fresh presentString> }`

### `DELETE /string/{key}/translation`
Delete the translation for one language.
**Params:** `lang` (required target). → `{ "ok": true }`

### `PUT /string/{key}/translations`
Save several languages at once (bulk paste). Each saved as **`needs_review`**.
**Params:** `translations: [ { lang, text } ]`. Entries with empty text or a
non-target lang are skipped; a placeholder mismatch aborts with `400`.
→ `{ "saved": int }`

### `POST /string/{key}/ignore`
Mark the source ignored (`status = -1`) and delete its children across languages.
→ `{ "ok": true }`

### `POST /ignored/restore`
Un-ignore sources (back to discovered).
**Params:** `keys: [ "{kind}:{id}", … ]`. A malformed ref → `400 invalid_key`.
→ `{ "ok": true, "restored": int }`

### `POST /string/{key}/apply-everywhere`
Copy a translation onto chosen duplicate sources. The copies land at the **same
status the user picked for the primary** (default Reviewed/Done) — applying *is* the
confirmation, so they aren't parked in "to review".
**Params:** `lang` (required target), `text`, `targets: [ "{kind}:{id}" ]`, `mark`,
`overwrite` (bool — replace an existing translation on a target or not).
→ `{ "applied": int }`

### `POST /scan`
Re-run discovery (Path A/B).
**Params (optional):** `scope: { owner_id: <postId> }` to re-scan a single post.
- with `owner_id` → `{ "ok": true, "scope": "owner" }`
- otherwise full site scan → `{ "ok": true, "sources": int, "inserted": int, "skipped": int }`

---

## Notes for clients

- **State is derived, not stored**: `state` is `to_translate` / `review_flagged`
  (a.k.a. needs_review) / `done`, computed from child status (`done = status > 0`).
- **`origin`** on a row is `machine` when `provider` is set, `human` when it's a
  human save, `null` when untranslated.
- **`updated_at`** is the child's; pass it back on `PUT …/translation` to get
  optimistic-concurrency protection.
- All list endpoints are keyset-paginated; never assume offsets.
