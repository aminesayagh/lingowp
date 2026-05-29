# TranslatePress-like WordPress Translation Plugin Architecture — Revised Version

## 1. Architectural Direction

This architecture follows a **TranslatePress-like model**.

The plugin does not duplicate WordPress posts or pages per language. Instead, WordPress keeps the original content as the source of truth, and the plugin translates the final rendered output.

The architecture is based on these principles:

```txt
Public website language = URL-based
User language preference = optional cookie
WordPress admin = dedicated admin experience
Visual translation = admin-controlled editor with public preview
Translations = stored locally in custom WordPress database tables
```

The approved model is:

```txt
/fr/about
    = canonical French page

/es/about
    = canonical Spanish page

/de/about
    = canonical German page

cookie preferred_lang=fr
    = user preference only

/wp-admin/admin.php?page=mytr-translations
    = translation management

/wp-admin/admin.php?page=mytr-visual-editor&url=/fr/about
    = visual translation editor
```

The cookie should never be the main source of truth for public multilingual pages.  
The URL must define the language for public pages.

---

# 2. Main Plugin Structure

```txt
Translation Plugin
│
├── Public Frontend Layer
│   ├── Language URL resolver
│   ├── Public route mapper
│   ├── Output buffer
│   ├── HTML parser
│   ├── String extractor
│   ├── Translation renderer
│   ├── Link rewriter
│   ├── SEO manager
│   └── Language switcher
│
├── Admin Management Layer
│   ├── Settings page
│   ├── Languages page
│   ├── Translation memory table
│   ├── Automatic translation settings
│   ├── SEO settings
│   ├── Cache settings
│   └── Debug/logs page
│
├── Visual Editor Layer
│   ├── Admin editor shell
│   ├── Preview iframe
│   ├── Clickable DOM markers
│   ├── Translation sidebar
│   └── REST save actions
│
├── Core Translation Engine
│   ├── Normalizer
│   ├── Hash generator
│   ├── Translation memory repository
│   ├── Machine translation provider
│   ├── Glossary engine
│   └── Cache manager
│
└── Integration Layer
    ├── REST API translation
    ├── AJAX translation
    ├── WooCommerce compatibility
    ├── SEO plugin compatibility
    └── Page builder compatibility
```

---

# 3. Language Resolver

## 3.1 Approved Language Resolver Structure

```txt
Language Resolver
├── URL language resolver
│   ├── /fr/about
│   ├── /es/about
│   └── /de/about
│
├── Cookie preference resolver
│   └── preferred_lang=fr
│
└── Fallback resolver
    ├── browser language, optional
    └── default site language
```

## 3.2 Resolver Priority

The resolver must follow a strict priority order.

```txt
1. URL language prefix
2. Explicit admin/preview query parameter
3. Cookie preference
4. Browser language, optional
5. Default site language
```

The URL language has the highest priority.

Example:

```txt
/fr/about
```

must always render French, even if the cookie says:

```txt
preferred_lang=es
```

The cookie is only a preference mechanism, not the canonical language source.

---

## 3.3 Language Resolution Examples

### Case 1 — URL has a language prefix

```txt
Request: /fr/about
Cookie: preferred_lang=es
Result: fr
```

Reason:

```txt
The URL defines the public page language.
```

---

### Case 2 — URL has no language prefix, but cookie exists

```txt
Request: /about
Cookie: preferred_lang=fr
Result: default language for /about
Optional behavior: suggest or redirect to /fr/about
```

Recommended behavior:

```txt
Do not silently serve French at /about.
Either keep /about in the default language
or redirect to /fr/about depending on plugin settings.
```

---

### Case 3 — URL has no prefix and no cookie

```txt
Request: /about
Cookie: none
Browser language: fr
Default language: en
Result: en
```

Optional behavior:

```txt
Show a language suggestion banner:
"This page is available in French."
```

Avoid automatically redirecting every visitor based on browser language by default, because it can create confusing behavior for users and crawlers.

---

## 3.4 Recommended PHP Skeleton

```php
class MyTR_Language_Resolver
{
    public function resolve(): string
    {
        $url_lang = $this->resolve_from_url();

        if ($url_lang !== null) {
            return $url_lang;
        }

        $preview_lang = $this->resolve_from_admin_preview();

        if ($preview_lang !== null) {
            return $preview_lang;
        }

        $cookie_lang = $this->resolve_from_cookie();

        if ($cookie_lang !== null) {
            return $cookie_lang;
        }

        $browser_lang = $this->resolve_from_browser();

        if ($browser_lang !== null && $this->browser_detection_enabled()) {
            return $browser_lang;
        }

        return $this->get_default_language();
    }

    private function resolve_from_url(): ?string
    {
        $lang = get_query_var('mytr_lang');

        if (!$lang) {
            return null;
        }

        if (!$this->is_enabled_language($lang)) {
            return null;
        }

        return sanitize_key($lang);
    }

    private function resolve_from_cookie(): ?string
    {
        if (empty($_COOKIE['preferred_lang'])) {
            return null;
        }

        $lang = sanitize_key($_COOKIE['preferred_lang']);

        if (!$this->is_enabled_language($lang)) {
            return null;
        }

        return $lang;
    }
}
```

---

# 4. Public Frontend Layer

The public frontend layer controls everything visible to website visitors.

It must be fully separated from the WordPress admin experience.

```txt
Public Frontend Layer
├── Language URL resolver
├── Public route mapper
├── Output buffer
├── HTML parser
├── String extractor
├── Translation renderer
├── Link rewriter
├── SEO manager
└── Language switcher
```

---

## 4.1 Public Frontend Responsibility

The frontend layer is responsible for:

```txt
Detecting language from public URL
Mapping translated routes to original WordPress routes
Capturing final HTML output
Extracting translatable strings
Replacing original strings with translations
Rewriting internal links
Injecting SEO tags
Rendering the language switcher
Serving the correct public language page
```

---

## 4.2 Public Request Flow

```txt
Visitor requests /fr/about
        ↓
Language URL resolver detects lang = fr
        ↓
Public route mapper maps /fr/about to original /about
        ↓
WordPress renders /about normally
        ↓
Output buffer captures the final HTML
        ↓
HTML parser parses the page
        ↓
String extractor extracts visible text and attributes
        ↓
Core translation engine looks up translations
        ↓
Translation renderer replaces strings
        ↓
Link rewriter converts /contact to /fr/contact
        ↓
SEO manager injects hreflang and canonical tags
        ↓
Translated HTML is returned to the visitor
```

---

## 4.3 Public Route Mapper

The route mapper converts language-prefixed URLs into original WordPress routes.

Examples:

```txt
/fr/about           → /about
/es/contact         → /contact
/de/products/item   → /products/item
/fr/category/news   → /category/news
```

The public URL remains language-specific, but internally WordPress still resolves the original route.

---

## 4.4 Recommended URL Strategy

For an existing WordPress website, the safest default is:

```txt
/about
/fr/about
/es/about
/de/about
```

The default language keeps its original route.

For a new website, an advanced setting can enable:

```txt
/en/about
/fr/about
/es/about
/de/about
```

Recommended plugin settings:

```txt
Default language prefix:
    none by default

Optional advanced mode:
    prefix all languages, including default language
```

---

## 4.5 Output Buffer

The output buffer captures the final rendered page before it is sent to the browser.

```php
add_action('template_redirect', function () {
    if (is_admin()) {
        return;
    }

    if (wp_doing_ajax()) {
        return;
    }

    if (defined('REST_REQUEST') && REST_REQUEST) {
        return;
    }

    ob_start(function ($html) {
        return MyTR_Public_Output_Buffer::translate($html);
    });
});
```

The output buffer must not run in admin pages.

It should skip:

```txt
/wp-admin/
/wp-login.php
/wp-cron.php
/xmlrpc.php
/admin-ajax.php, unless explicitly handled
REST API requests, unless explicitly handled
static assets
```

---

## 4.6 HTML Parser

The parser receives the full HTML output and prepares it for string extraction.

It should skip:

```txt
<script>
<style>
<code>
<pre>
<noscript>
<svg>
<canvas>
[data-no-translate]
.notranslate
.wp-admin-bar
```

Recommended parser options:

```txt
DOMDocument for MVP
Masterminds/html5-php for stronger HTML5 parsing
Symfony DomCrawler for advanced traversal
```

---

## 4.7 String Extractor

The string extractor identifies translatable content.

It should extract:

```txt
Text nodes
Button labels
Menu items
Form labels
Input placeholders
Image alt attributes
Title attributes
Meta title
Meta description
Open Graph title
Open Graph description
WooCommerce visible strings
```

Example:

```html
<h1>About us</h1>
<p>We help brands grow.</p>
<input placeholder="Enter your email">
<img alt="Team photo">
```

Candidates:

```txt
About us
We help brands grow.
Enter your email
Team photo
```

---

## 4.8 Translation Renderer

The renderer replaces extracted source strings with translations.

Flow:

```txt
Receive DOM
Receive extracted candidates
Normalize candidates
Generate hashes
Query translation memory in bulk
Replace known translations
Keep missing strings in source language
Insert missing strings as discovered
Return translated HTML
```

The renderer should never do one SQL query per string.

---

## 4.9 Link Rewriter

The link rewriter keeps the visitor inside the selected language.

Example:

```html
<a href="/contact">Contact</a>
```

When current language is French:

```html
<a href="/fr/contact">Contact</a>
```

Links to rewrite:

```txt
Internal page links
Menu links
Category links
Product links
Pagination links
Search result links
Form actions
WooCommerce cart and checkout links
```

Links to skip:

```txt
External URLs
mailto:
tel:
Anchor-only links
Admin URLs
Login/logout URLs
Static assets
API URLs, unless explicitly supported
```

---

## 4.10 SEO Manager

The SEO manager makes translated pages understandable for search engines.

It should handle:

```txt
hreflang tags
Canonical URLs
Translated titles
Translated meta descriptions
Open Graph metadata
Twitter card metadata
Translated sitemap URLs
SEO plugin compatibility
```

Example:

```html
<link rel="alternate" hreflang="en" href="https://example.com/about" />
<link rel="alternate" hreflang="fr" href="https://example.com/fr/about" />
<link rel="alternate" hreflang="es" href="https://example.com/es/about" />
<link rel="alternate" hreflang="x-default" href="https://example.com/about" />
```

For:

```txt
/fr/about
```

canonical should usually be:

```txt
https://example.com/fr/about
```

not:

```txt
https://example.com/about
```

---

## 4.11 Language Switcher

The public language switcher allows visitors to move between language versions.

Example:

```html
<ul class="mytr-language-switcher">
    <li><a href="/about">English</a></li>
    <li><a href="/fr/about">Français</a></li>
    <li><a href="/es/about">Español</a></li>
</ul>
```

Switcher behavior:

```txt
Current page: /fr/about
Click English → /about
Click Spanish → /es/about
Click German → /de/about
```

The switcher should also set:

```txt
cookie preferred_lang=selected_language
```

But the redirect target remains the language-specific URL.

---

# 5. Admin Management Layer

The admin management layer is dedicated to the WordPress backend.

It must not depend on public routes like `/fr/about`.

```txt
Admin Management Layer
├── Settings page
├── Languages page
├── Translation memory table
├── Automatic translation settings
├── SEO settings
├── Cache settings
└── Debug/logs page
```

---

## 5.1 Admin Responsibility

The admin layer is responsible for:

```txt
Configuring languages
Managing translation memory
Editing translations manually
Configuring automatic translation providers
Managing SEO options
Managing cache behavior
Viewing logs and debugging translation issues
Importing/exporting translations
```

---

## 5.2 Admin URLs

Recommended admin pages:

```txt
/wp-admin/admin.php?page=mytr-settings
/wp-admin/admin.php?page=mytr-languages
/wp-admin/admin.php?page=mytr-translations
/wp-admin/admin.php?page=mytr-auto-translation
/wp-admin/admin.php?page=mytr-seo
/wp-admin/admin.php?page=mytr-cache
/wp-admin/admin.php?page=mytr-debug
```

---

## 5.3 Settings Page

The settings page should include:

```txt
Default language
Enabled languages
URL format
Cookie preference behavior
Browser language detection
Auto-redirect behavior
Fallback language behavior
```

Recommended defaults:

```txt
Default language has no prefix
URL-based language detection enabled
Cookie preference enabled
Browser detection disabled by default
Auto-redirect disabled by default
```

---

## 5.4 Languages Page

This page manages available languages.

Fields:

```txt
Language code
Language name
Native name
Text direction
Status
URL slug
Flag/icon, optional
```

Example:

```txt
en | English  | English  | ltr | active | en
fr | French   | Français | ltr | active | fr
ar | Arabic   | العربية  | rtl | active | ar
```

---

## 5.5 Translation Memory Table

This is the central admin interface.

Columns:

```txt
Original text
Translated text
Source language
Target language
Status
Context
Source URL
Provider
Last seen
Actions
```

Filters:

```txt
Language pair
Status
Provider
Source URL
Search original text
Search translated text
```

Actions:

```txt
Edit
Mark as reviewed
Ignore
Re-translate automatically
Delete
Export
```

---

## 5.6 Automatic Translation Settings

This page controls machine translation.

Options:

```txt
Provider
API key
Daily quota
Monthly quota
Auto-translate missing strings
Translate only when admin visits
Translate when visitor visits
Block crawlers from triggering auto-translation
Glossary enforcement
```

Recommended safe default:

```txt
Auto-translate when admin/editor visits
Do not auto-translate on crawler visits
Do not auto-translate unlimited strings on public traffic
```

---

## 5.7 SEO Settings

Options:

```txt
Enable hreflang
Enable translated canonical URLs
Translate title
Translate meta description
Translate Open Graph metadata
Translate Twitter card metadata
Enable multilingual sitemap
SEO plugin integration
```

---

## 5.8 Cache Settings

Options:

```txt
Enable translation lookup cache
Enable full-page translated cache
Clear cache
Clear cache for one language
Clear cache for one page
Cache debug mode
```

---

## 5.9 Debug / Logs Page

Useful logs:

```txt
Missing strings discovered
Automatic translation calls
Provider errors
Quota exceeded events
HTML parser errors
Route mapping errors
Cache hits/misses
SEO injection issues
REST/AJAX translation issues
```

---

# 6. Visual Editor Layer

The visual editor is a controlled editing experience.

It should be managed from WordPress admin but preview the public translated page.

```txt
Visual Editor Layer
├── Admin editor shell
├── Preview iframe
├── Clickable DOM markers
├── Translation sidebar
└── REST save actions
```

---

## 6.1 Recommended Visual Editor Architecture

Instead of exposing editor mode directly to public visitors, use an admin shell:

```txt
/wp-admin/admin.php?page=mytr-visual-editor&url=/fr/about
```

Inside that admin page, render the public translated page in an iframe:

```txt
iframe src="/fr/about?mytr_preview=1"
```

This keeps:

```txt
Permissions inside WordPress admin
Editor UI separate from public UI
REST authentication easier
Nonces easier
Public page cleaner
Editor scripts hidden from normal visitors
```

---

## 6.2 Visual Editor Layout

```txt
WordPress Admin Shell
├── Top bar
│   ├── Current language
│   ├── Current page
│   ├── Save status
│   └── Exit editor
│
├── Sidebar
│   ├── Original string
│   ├── Translated string
│   ├── Status
│   ├── Context
│   ├── Save button
│   ├── Mark as reviewed
│   └── Auto-translate button
│
└── Preview iframe
    └── /fr/about?mytr_preview=1
```

---

## 6.3 Clickable DOM Markers

In preview mode, the renderer should add metadata to translatable nodes.

Normal public mode:

```html
<h1>À propos</h1>
```

Visual editor preview mode:

```html
<h1 data-mytr-string-id="123" data-mytr-hash="abc123">
    À propos
</h1>
```

Then JavaScript can detect clicked nodes and load the translation data.

---

## 6.4 Visual Editor Flow

```txt
Admin opens visual editor
        ↓
Admin shell loads
        ↓
Preview iframe loads /fr/about?mytr_preview=1
        ↓
Renderer adds data-mytr-* markers to translatable nodes
        ↓
Admin clicks a text block
        ↓
Sidebar loads original and translated text
        ↓
Admin edits translation
        ↓
REST API saves translation
        ↓
Translation memory is updated
        ↓
Related cache is cleared
        ↓
Iframe preview updates
```

---

## 6.5 REST Save Actions

Suggested endpoints:

```txt
GET    /wp-json/mytr/v1/string/{id}
POST   /wp-json/mytr/v1/string/{id}
POST   /wp-json/mytr/v1/string/{id}/review
POST   /wp-json/mytr/v1/string/{id}/ignore
POST   /wp-json/mytr/v1/bulk-translate
POST   /wp-json/mytr/v1/cache/clear
```

Security requirements:

```txt
User must be logged in
User must have edit_posts or manage_options capability
Nonce must be valid
Input must be sanitized
Output must be escaped
```

---

# 7. Core Translation Engine

The core engine should be independent from public routes, admin screens, and visual editor UI.

```txt
Core Translation Engine
├── Normalizer
├── Hash generator
├── Translation memory repository
├── Machine translation provider
├── Glossary engine
└── Cache manager
```

---

## 7.1 Core Responsibility

The core engine is responsible for:

```txt
Normalizing strings
Generating stable hashes
Looking up translations
Creating missing translation records
Saving manual translations
Calling machine translation providers
Applying glossary rules
Managing translation cache
```

The core engine should be reusable by:

```txt
Public frontend rendering
Visual editor
Admin translation table
REST API translation
AJAX translation
WooCommerce integration
```

---

## 7.2 Normalizer

The normalizer makes strings stable before storage and hashing.

Example:

```php
function mytr_normalize_string(string $text): string
{
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = trim($text);
    $text = preg_replace('/\s+/', ' ', $text);

    return $text;
}
```

Without normalization, these become different records:

```txt
"About us"
" About us "
"About   us"
```

After normalization, they become one record:

```txt
"About us"
```

---

## 7.3 Hash Generator

The plugin should not identify strings only by raw text comparison.

Use a hash.

```php
$hash = hash('sha256', mytr_normalize_string($text));
```

Recommended hash context:

```txt
source language
target language
normalized original text
optional domain/context
```

For MVP:

```txt
hash = sha256(normalized original text)
```

For a more advanced version:

```txt
hash = sha256(source_lang + target_lang + context + normalized original text)
```

---

## 7.4 Translation Memory Repository

This is the database access layer.

It should provide methods like:

```php
find_many_by_hashes(array $hashes, string $source_lang, string $target_lang): array;

insert_missing_strings(array $candidates, string $source_lang, string $target_lang): void;

save_translation(int $string_id, string $translated_text, int $status): void;

mark_reviewed(int $string_id): void;

ignore_string(int $string_id): void;
```

Important rule:

```txt
Use bulk database operations.
Never query once per string.
```

---

## 7.5 Machine Translation Provider

The machine translation provider should be abstracted behind an interface.

```php
interface MyTR_Translation_Provider_Interface
{
    public function translate_batch(
        array $strings,
        string $source_lang,
        string $target_lang
    ): array;
}
```

Possible implementations:

```txt
DeepL provider
Google Translate provider
OpenAI-compatible provider
Microsoft Translator provider
Manual-only provider
```

---

## 7.6 Glossary Engine

The glossary controls words that should always be translated in a specific way.

Examples:

```txt
Hypeo → Hypeo
Creator → Créateur
Campaign → Campagne
Marketplace → Marketplace
```

The glossary can be applied:

```txt
Before machine translation
After machine translation
Before saving translation
During manual review
```

---

## 7.7 Cache Manager

Caching is essential.

Cache levels:

```txt
Translation lookup cache
Provider response cache
Full-page translated HTML cache
Route mapping cache
SEO metadata cache
```

MVP cache:

```txt
Bulk database lookup
Object cache for translations
No full-page cache at first
```

Advanced cache:

```txt
Full translated page cache per language and page
Automatic invalidation on content update
Automatic invalidation on translation update
```

---

# 8. Database Design

## 8.1 Main Translation Table

```sql
CREATE TABLE wp_mytr_strings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_lang VARCHAR(10) NOT NULL,
    target_lang VARCHAR(10) NOT NULL,
    original_hash CHAR(64) NOT NULL,
    original_text LONGTEXT NOT NULL,
    normalized_text LONGTEXT NULL,
    translated_text LONGTEXT NULL,
    context VARCHAR(255) NULL,
    source_url TEXT NULL,
    status TINYINT UNSIGNED NOT NULL DEFAULT 0,
    provider VARCHAR(50) NULL,
    last_seen_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uniq_translation (
        source_lang,
        target_lang,
        original_hash
    ),
    KEY idx_lang_pair (source_lang, target_lang),
    KEY idx_status (status),
    KEY idx_last_seen_at (last_seen_at)
);
```

---

## 8.2 Translation Status Values

```txt
0 = discovered, not translated
1 = machine translated
2 = manually reviewed
3 = ignored
4 = needs review
```

Recommended rendering behavior:

```txt
discovered         → show original text
machine translated → show translated text
manually reviewed → show translated text
ignored            → show original text
needs review       → show translated text, but mark in admin
```

---

## 8.3 Optional Context Table

Useful for knowing where a string appears.

```sql
CREATE TABLE wp_mytr_string_contexts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    string_id BIGINT UNSIGNED NOT NULL,
    source_url TEXT NULL,
    element_type VARCHAR(100) NULL,
    element_selector TEXT NULL,
    first_seen_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,

    PRIMARY KEY (id),
    KEY idx_string_id (string_id)
);
```

---

## 8.4 Optional Glossary Table

```sql
CREATE TABLE wp_mytr_glossary (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_lang VARCHAR(10) NOT NULL,
    target_lang VARCHAR(10) NOT NULL,
    source_term VARCHAR(255) NOT NULL,
    target_term VARCHAR(255) NOT NULL,
    case_sensitive TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,

    PRIMARY KEY (id),
    KEY idx_lang_pair (source_lang, target_lang)
);
```

---

# 9. Integration Layer

The integration layer handles cases that are not fully covered by the initial output buffer.

```txt
Integration Layer
├── REST API translation
├── AJAX translation
├── WooCommerce compatibility
├── SEO plugin compatibility
└── Page builder compatibility
```

---

## 9.1 REST API Translation

Needed for modern WordPress sites using dynamic blocks, frontend apps, or headless components.

Examples:

```txt
/wp-json/wp/v2/posts
/wp-json/wp/v2/pages
/wp-json/wc/store/products
```

Fields to translate:

```txt
title.rendered
content.rendered
excerpt.rendered
yoast_head_json.title
yoast_head_json.description
```

---

## 9.2 AJAX Translation

Needed for:

```txt
WooCommerce cart fragments
Search suggestions
Filtering plugins
Form responses
Dynamic content loaders
```

MVP can skip advanced AJAX translation, but the architecture should leave a clean integration point.

---

## 9.3 WooCommerce Compatibility

WooCommerce support should be phased.

MVP:

```txt
Translate product page HTML
Translate cart/checkout visible HTML
Translate WooCommerce gettext strings
Rewrite cart and checkout links with language prefix
```

Advanced:

```txt
Translate product slugs
Translate product attributes
Translate variation labels
Translate transactional emails
Translate AJAX cart fragments
Translate product schema markup
```

---

## 9.4 SEO Plugin Compatibility

Integrations to consider:

```txt
Yoast SEO
Rank Math
All in One SEO
SEOPress
WooCommerce SEO
```

The SEO manager should be able to translate:

```txt
SEO title
Meta description
Open Graph title
Open Graph description
Twitter title
Twitter description
Canonical URL
Breadcrumb labels
Schema text fields
```

---

## 9.5 Page Builder Compatibility

The output-buffer model is naturally compatible with most page builders because they eventually render HTML.

Examples:

```txt
Elementor
Divi
WPBakery
Gutenberg
Bricks
Oxygen
Beaver Builder
```

Extra support may be needed for builders that render content dynamically through JavaScript or REST calls.

---

# 10. Plugin File Organization

Recommended structure:

```txt
my-translation-plugin/
├── my-translation-plugin.php
├── uninstall.php
│
├── includes/
│   ├── bootstrap.php
│   ├── public/
│   │   ├── class-language-url-resolver.php
│   │   ├── class-public-route-mapper.php
│   │   ├── class-output-buffer.php
│   │   ├── class-html-parser.php
│   │   ├── class-string-extractor.php
│   │   ├── class-translation-renderer.php
│   │   ├── class-link-rewriter.php
│   │   ├── class-seo-manager.php
│   │   └── class-language-switcher.php
│   │
│   ├── admin/
│   │   ├── class-settings-page.php
│   │   ├── class-languages-page.php
│   │   ├── class-translation-memory-table.php
│   │   ├── class-auto-translation-settings.php
│   │   ├── class-seo-settings-page.php
│   │   ├── class-cache-settings-page.php
│   │   └── class-debug-logs-page.php
│   │
│   ├── editor/
│   │   ├── class-visual-editor-page.php
│   │   ├── class-preview-iframe.php
│   │   ├── class-dom-marker-injector.php
│   │   ├── class-editor-sidebar.php
│   │   └── class-editor-rest-controller.php
│   │
│   ├── engine/
│   │   ├── class-normalizer.php
│   │   ├── class-hash-generator.php
│   │   ├── class-translation-memory-repository.php
│   │   ├── class-machine-translation-manager.php
│   │   ├── providers/
│   │   │   ├── interface-translation-provider.php
│   │   │   ├── class-deepl-provider.php
│   │   │   ├── class-google-provider.php
│   │   │   └── class-manual-provider.php
│   │   ├── class-glossary-engine.php
│   │   └── class-cache-manager.php
│   │
│   └── integrations/
│       ├── class-rest-api-translation.php
│       ├── class-ajax-translation.php
│       ├── class-woocommerce-integration.php
│       ├── class-seo-plugin-integration.php
│       └── class-page-builder-integration.php
│
├── assets/
│   ├── public/
│   │   └── language-switcher.css
│   ├── admin/
│   │   ├── admin.css
│   │   └── admin.js
│   └── editor/
│       ├── visual-editor.css
│       └── visual-editor.js
│
└── templates/
    ├── language-switcher.php
    ├── admin-settings.php
    ├── translation-memory-table.php
    └── visual-editor-shell.php
```

---

# 11. MVP Scope

## 11.1 Version 1 — Core Public Translation Layer

Version 1 should focus on proving the engine.

```txt
Language settings
URL language resolver
/fr/ route support
Public route mapper
Output buffer
HTML parser
String extractor
Translation memory table
Manual admin translation table
Translation renderer
Internal link rewriting
Basic language switcher
Basic hreflang
```

Do not include too much in v1.

---

## 11.2 Version 1 Should Not Include

```txt
Translated slugs
Visual editor
Advanced WooCommerce support
REST API translation
AJAX translation
Full multilingual sitemap
Glossary
Team workflows
Translation export/import
Full-page cache
```

---

# 12. Version 2 — Admin + Visual Editor

Version 2 should improve usability.

```txt
Admin translation memory filters
Admin inline editing
Visual editor shell
Preview iframe
Clickable DOM markers
Translation sidebar
REST save endpoints
Translation statuses
Machine translation provider
Cache invalidation
SEO metadata translation
```

---

# 13. Version 3 — Advanced Compatibility

Version 3 should improve production readiness.

```txt
WooCommerce advanced support
REST API translation
AJAX translation
Page builder dynamic content support
Translated slugs
Multilingual sitemap
Glossary
CSV import/export
Full-page translated HTML cache
SEO plugin integrations
Provider quota dashboard
Debugging tools
```

---

# 14. Development Order

Recommended implementation order:

```txt
1. Plugin bootstrap
2. Activation hook and database table creation
3. Admin language settings
4. URL language resolver
5. Rewrite rules for /fr/
6. Public route mapper
7. Output buffer
8. HTML parser
9. String extractor
10. Normalizer
11. Hash generator
12. Translation memory repository
13. Bulk lookup and missing string insertion
14. Manual translation admin table
15. Translation renderer
16. Link rewriter
17. Language switcher
18. Basic hreflang
19. Cache manager
20. Machine translation provider
21. Visual editor shell
22. Preview iframe
23. DOM markers
24. REST save actions
25. SEO plugin integration
26. WooCommerce integration
27. REST/AJAX translation
28. Translated slugs
29. Multilingual sitemap
```

---

# 15. Final Request Flow

## Public Visitor Flow

```txt
Visitor opens /fr/about
        ↓
URL resolver detects lang = fr
        ↓
Route mapper maps /fr/about to /about
        ↓
WordPress renders original page
        ↓
Output buffer captures HTML
        ↓
HTML parser parses the document
        ↓
String extractor extracts candidates
        ↓
Core engine normalizes strings
        ↓
Hash generator creates hashes
        ↓
Translation memory repository performs bulk lookup
        ↓
Renderer replaces known strings
        ↓
Missing strings are inserted as discovered
        ↓
Link rewriter keeps links under /fr/
        ↓
SEO manager adds hreflang/canonical metadata
        ↓
Visitor receives translated French page
```

---

## Admin Translation Flow

```txt
Admin opens /wp-admin/admin.php?page=mytr-translations
        ↓
Admin selects language pair: English → French
        ↓
Admin filters status = discovered
        ↓
Admin edits missing translations
        ↓
Translation memory repository saves translations
        ↓
Cache manager clears affected entries
        ↓
Public translated pages update automatically
```

---

## Visual Editor Flow

```txt
Admin opens /wp-admin/admin.php?page=mytr-visual-editor&url=/fr/about
        ↓
Admin shell loads
        ↓
Iframe loads /fr/about?mytr_preview=1
        ↓
Preview renderer injects data-mytr markers
        ↓
Admin clicks a translated string
        ↓
Sidebar loads original and translated text
        ↓
Admin edits translation
        ↓
REST save action updates translation memory
        ↓
Cache is cleared
        ↓
Iframe preview updates
```

---

# 16. Key Architectural Rules

## Rule 1 — Public language must be URL-based

```txt
/fr/about = French
/es/about = Spanish
/de/about = German
```

Do not rely on cookies as the main public language source.

---

## Rule 2 — Cookie is only a preference

```txt
preferred_lang=fr
```

The cookie can help redirect or suggest the preferred language, but it should not make `/about` randomly serve different languages.

---

## Rule 3 — Admin is separate from public routing

Do not make:

```txt
/fr/wp-admin/
```

part of the architecture.

Admin remains:

```txt
/wp-admin/
```

---

## Rule 4 — Visual editor is an admin experience

Preferred:

```txt
/wp-admin/admin.php?page=mytr-visual-editor&url=/fr/about
```

Not preferred as the main architecture:

```txt
/fr/about?mytr_editor=1
```

The second can exist only as an internal iframe preview mode.

---

## Rule 5 — Core engine must be reusable

The core translation engine should not know whether the request comes from:

```txt
Public page
Admin table
Visual editor
REST API
AJAX response
WooCommerce
```

It should only know:

```txt
source string
source language
target language
context
translation status
```

---

# 17. Final Summary

The revised architecture is:

```txt
URL-based multilingual public pages
+ optional cookie preference
+ separate WordPress admin management
+ admin-controlled visual editor
+ local translation memory
+ reusable core translation engine
+ integration layer for dynamic WordPress behavior
```

The cleanest product model is:

```txt
Public visitors use /fr/about
Admins manage translations in /wp-admin/
Visual editor runs from /wp-admin/ and previews /fr/about in an iframe
The core engine stores and retrieves translations from custom DB tables
```

This structure keeps the plugin clean, SEO-friendly, cache-friendly, and maintainable.
