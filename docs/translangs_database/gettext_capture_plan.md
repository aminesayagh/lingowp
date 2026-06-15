# TransLangs — Gettext Capture Guard (Implementation Plan)

> Hand-off plan for Claude Code. Implements frontend-only gettext capture: hook
> the four gettext filters, gate every call through a cheap request-context
> guard, buffer in memory, flush via the background queue. No client header, no
> AJAX/REST capture in v1 — the rendered-HTML parser already covers
> dynamically-inserted frontend strings.

---

## 1. Goal & decision summary

**Goal:** capture theme/plugin UI strings that are shown to *frontend visitors*,
without polluting the table with admin / editor / cron / API strings.

**Decisions (locked):**
- Capture gettext **only on real frontend page loads**.
- **Do NOT capture from AJAX or REST** in v1. (`admin-ajax.php` and the REST API
  serve both admin and frontend with no reliable built-in discriminator.)
- **No `X-TRANSLANGS-CONTEXT` header.** Rejected: we don't control third-party
  AJAX/REST requests (WooCommerce fragments, theme/plugin calls won't send it),
  and it's client-spoofable pollution. It would capture the strings we least
  need and miss the ones it was meant for.
- **The rendered-HTML parser is the safety net** for dynamically-inserted
  frontend text — it sees strings once they land in the DOM/output, regardless
  of which request delivered them. No cooperation or trust required.
- **Accepted v1 gap:** strings that exist *only* in JSON/AJAX responses and never
  render as visible HTML are not captured. Tiny set; revisit later with
  server-side route allow-listing (never a client header) if a real case proves
  it matters.

---

## 2. The capture guard (cheap, request-scoped, fail-closed)

Runs on every gettext call (hundreds per page) → must be O(1) and cached for the
request. Uses **only request-context checks** — never query conditional tags
(`is_page()`, `is_singular()`), because gettext fires before the main query.

```php
/**
 * Should the current request capture frontend gettext strings?
 * Cheap, memoized per request. Frontend page loads only.
 */
function tl_should_capture_gettext(): bool {
    static $should = null;
    if ( $should !== null ) {
        return $should;
    }

    // Too early to know the context reliably → don't capture, and DON'T cache
    // (let a later call decide once WP is bootstrapped).
    if ( ! did_action( 'plugins_loaded' ) ) {
        return false;
    }

    // System / non-frontend request types.
    if (
        wp_doing_cron()
        || ( defined( 'WP_CLI' ) && WP_CLI )
        || ( defined( 'WP_INSTALLING' ) && WP_INSTALLING )
        || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST )
    ) {
        return $should = false;
    }

    // Login page (is_login() is NOT core — check pagenow instead).
    if ( isset( $GLOBALS['pagenow'] ) && $GLOBALS['pagenow'] === 'wp-login.php' ) {
        return $should = false;
    }

    // AJAX and REST are ambiguous (admin vs frontend) and not controllable.
    // Skip them in v1; the HTML parser covers frontend dynamic strings.
    if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
        return $should = false;
    }

    // wp-admin screens.
    if ( is_admin() ) {
        return $should = false;
    }

    // A standard, non-admin frontend HTML page view.
    return $should = true;
}
```

**Why each piece:**
- `static $should` — memoize; the answer can't change within one request.
- `did_action('plugins_loaded')` guard — gettext can fire during plugin load
  before `wp_doing_ajax()`/`REST_REQUEST` are set; fail closed and don't cache an
  early wrong answer.
- `WP_INSTALLING` / `XMLRPC_REQUEST` — extra non-frontend contexts that can emit
  gettext.
- `pagenow === 'wp-login.php'` — `is_login()` does not exist in core; calling it
  is a fatal error. Use `pagenow`.
- AJAX/REST → false — the locked decision.

---

## 3. The four gettext filters (all required)

gettext has four separate filters with **different signatures**. Hooking only
`gettext` silently misses contextual (`_x()`) and plural (`_n()`) strings. Hook
all four; each callback gates on the guard and returns the text **unchanged**
(capture is a side effect — never alter the string).

```php
add_filter( 'gettext',               'tl_capture_gettext',               10, 3 );
add_filter( 'gettext_with_context',  'tl_capture_gettext_with_context',  10, 4 );
add_filter( 'ngettext',              'tl_capture_ngettext',              10, 5 );
add_filter( 'ngettext_with_context', 'tl_capture_ngettext_with_context', 10, 6 );
```

Signatures (return the first arg untouched in every case):

```php
// gettext( $translation, $text, $domain )
function tl_capture_gettext( $translation, $text, $domain ) {
    if ( tl_should_capture_gettext() ) {
        TL_Gettext_Buffer::add( $translation, $domain, null, null );
    }
    return $translation;
}

// gettext_with_context( $translation, $text, $context, $domain )
function tl_capture_gettext_with_context( $translation, $text, $context, $domain ) {
    if ( tl_should_capture_gettext() ) {
        TL_Gettext_Buffer::add( $translation, $domain, $context, null );
    }
    return $translation;
}

// ngettext( $translation, $single, $plural, $number, $domain )
function tl_capture_ngettext( $translation, $single, $plural, $number, $domain ) {
    if ( tl_should_capture_gettext() ) {
        TL_Gettext_Buffer::add( $translation, $domain, null, $plural );
    }
    return $translation;
}

// ngettext_with_context( $translation, $single, $plural, $number, $context, $domain )
function tl_capture_ngettext_with_context( $translation, $single, $plural, $number, $context, $domain ) {
    if ( tl_should_capture_gettext() ) {
        TL_Gettext_Buffer::add( $translation, $domain, $context, $plural );
    }
    return $translation;
}
```

> Capture the **already-translated** `$translation` value (what the theme/plugin
> renders in the site's source language) as the source string — that is the text
> that appears on the page and that our pipeline translates into the target
> languages.

---

## 4. Where to register (bootstrap)

- Register the four filters on **`plugins_loaded`** (or the plugin's main boot),
  so they're attached before frontend rendering begins but after WP core is far
  enough along for the guard's checks to be valid.
- The guard's own `did_action('plugins_loaded')` check makes any gettext call
  that fires *before* that point fail closed.

```php
add_action( 'plugins_loaded', function () {
    add_filter( 'gettext',               'tl_capture_gettext',               10, 3 );
    add_filter( 'gettext_with_context',  'tl_capture_gettext_with_context',  10, 4 );
    add_filter( 'ngettext',              'tl_capture_ngettext',              10, 5 );
    add_filter( 'ngettext_with_context', 'tl_capture_ngettext_with_context', 10, 6 );
} );
```

---

## 5. Buffer → flush (memory now, DB later)

The filter callbacks **never touch the database** (gettext is hot-path).

```
gettext fires → guard passes → TL_Gettext_Buffer::add() (in-memory, de-duped)
            ↓  (many times per request)
request ends → shutdown: serialize buffer → transient + enqueue ONE
              Action Scheduler job   (NO DB writes at shutdown)
            ↓
Action Scheduler worker: bulk INSERT IGNORE into the i18n parent table
   (source = plugin|theme|core, reference + reference_hash, original_text +
    original_hash, status = discovered); translations (children) are created
    later when actually translated.
```

**Buffer rules:**
- In-memory array keyed by the i18n identity (`source` + `reference_hash` +
  `original_hash`) so duplicates within a request collapse to one entry.
- Normalize text with the **shared normalizer** (same one discovery + parser +
  ownership use) before hashing — identical normalization everywhere or matches
  silently fail.
- `shutdown` only buffers + enqueues. Never `INSERT` at shutdown (managed hosts
  kill the worker on response flush → silent data loss).

---

## 6. Resolving `reference` and `source` (which plugin/theme)

Each captured string needs `source` (plugin|theme|core) and `reference` (the
textdomain or file). Derive from `$domain`:
- Map `$domain` → the registered textdomain path (WordPress tracks loaded
  textdomains). `source='theme'` if the domain belongs to the active theme,
  `source='plugin'` if it maps to a plugin, `source='core'` for `default`.
- `reference` = the textdomain string (e.g. `woocommerce`) or its file path;
  `reference_hash = sha256(normalized reference)`.
- Keep it best-effort: if the domain can't be resolved, store the domain string
  as `reference` and `source` as the best guess. Capture should never fail hard.

---

## 7. Implementation order (for Claude Code)

```
1. tl_should_capture_gettext() guard (Section 2) — unit-test the branches:
   cron/cli/installing/xmlrpc/login/ajax/rest/admin → false; plain frontend → true;
   pre-plugins_loaded → false and not cached.
2. TL_Gettext_Buffer (in-memory, de-dupe by identity, uses shared normalizer).
3. The four filter callbacks (Section 3) + registration on plugins_loaded (Section 4).
4. shutdown handler: buffer → transient + enqueue Action Scheduler job (no DB).
5. Worker job: bulk INSERT IGNORE into i18n parent (Section 5) with source/
   reference resolution (Section 6).
6. Verify against the pipeline: captured strings land as i18n PARENT rows,
   status=discovered, no child rows until translated.
```

---

## 8. Test checklist (manual, local WordPress)

- Load a frontend page with a theme using `__()`/`_x()`/`_n()` → strings captured.
- Open wp-admin, edit a post → NO admin strings captured.
- Trigger a frontend AJAX (e.g. WooCommerce add-to-cart) → NOT captured via
  gettext (and confirm the visible result text is instead caught by the HTML
  parser on a normal page render).
- WP-CLI command that emits translated strings → nothing captured.
- Confirm the guard evaluates once per request (memoized), not per gettext call.
- Confirm `_x()` (context) and `_n()` (plural) strings are captured, not just
  plain `__()` — proves all four filters are hooked.
- Confirm nothing is written at `shutdown`; writes happen in the worker.
- Kill-the-worker host simulation: captured strings still persist (queue, not
  shutdown).

---

## 9. Explicitly out of scope (v1)

- AJAX/REST gettext capture (and therefore the `X-TRANSLANGS-CONTEXT` header) —
  rejected; parser covers frontend dynamic strings.
- Full gettext domain/plural *translation* modelling beyond storing the source —
  the i18n table stays minimal.
- PHP-file `.pot`/`.mo` scanning for guaranteed coverage — different mechanism;
  runtime capture intentionally records only what actually renders.
