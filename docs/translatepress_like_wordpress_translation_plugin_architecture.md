# TranslatePress-like WordPress Translation Plugin Architecture

## 1. Goal

The goal is to design a WordPress translation plugin inspired by the **TranslatePress approach**:

- Keep the original WordPress content as the source of truth.
- Do not duplicate every page/post for each language.
- Let WordPress render the original page normally.
- Capture the final HTML output.
- Extract translatable strings from the rendered page.
- Store translations in custom WordPress database tables.
- Replace original strings with translated strings at render time.
- Provide a visual front-end editor for manual correction.
- Optionally use automatic translation providers such as Google Translate, DeepL, or an AI translation API.

This architecture is best suited for a plugin that wants to stay **WordPress-native**, self-hosted, and developer-controllable.

---

## 2. Core Concept

The plugin works as a **translation layer above the final rendered page**.

Instead of creating separate WordPress posts like this:

```txt
/about
/a-propos
/sobre-nosotros
```

The plugin keeps only the original WordPress page:

```txt
/about
```

Then it exposes translated versions through language-aware URLs:

```txt
/fr/about
/es/about
/de/about
```

Internally, `/fr/about` still loads the original `/about` page, but the plugin intercepts the final HTML and replaces the visible strings with their French translations.

---

## 3. High-Level Request Lifecycle

```txt
Visitor requests /fr/about
        ↓
Plugin detects target language = fr
        ↓
Plugin maps /fr/about to original WordPress route /about
        ↓
WordPress renders the page normally in the default language
        ↓
Plugin captures final HTML using output buffering
        ↓
Plugin parses the HTML
        ↓
Plugin extracts text nodes, attributes, metadata, links, and form labels
        ↓
Plugin checks local translation database
        ↓
If translations exist:
    replace original strings
Else:
    store missing strings as "discovered"
    optionally call automatic translation provider
        ↓
Plugin rewrites internal links to preserve /fr/
        ↓
Plugin injects hreflang and multilingual SEO tags
        ↓
Translated HTML is returned to the browser
```

---

## 4. Main Architectural Modules

```txt
my-translation-plugin/
├── plugin.php
├── includes/
│   ├── class-language-resolver.php
│   ├── class-url-router.php
│   ├── class-output-buffer.php
│   ├── class-html-parser.php
│   ├── class-string-extractor.php
│   ├── class-translation-memory.php
│   ├── class-translation-renderer.php
│   ├── class-machine-translator.php
│   ├── class-seo-manager.php
│   ├── class-link-rewriter.php
│   ├── class-visual-editor.php
│   ├── class-rest-api.php
│   ├── class-cache-manager.php
│   └── class-admin-settings.php
├── assets/
│   ├── editor.js
│   ├── editor.css
│   └── language-switcher.css
├── templates/
│   ├── language-switcher.php
│   └── editor-sidebar.php
└── uninstall.php
```

---

# 5. Module 1 — Language Resolver

## Responsibility

The language resolver decides which language should be active for the current request.

Possible language sources:

```txt
URL prefix: /fr/about
Query parameter: ?lang=fr
Cookie: user selected language
Browser Accept-Language header
Default site language
```

For an MVP, the cleanest approach is URL-based language detection.

Example:

```txt
/about       → default language
/fr/about    → French
/es/about    → Spanish
/de/about    → German
```

## Suggested Behavior

```php
class Language_Resolver {
    public function get_current_language(): string {
        $lang = get_query_var('mytr_lang');

        if (!empty($lang)) {
            return sanitize_key($lang);
        }

        return $this->get_default_language();
    }

    public function get_default_language(): string {
        return get_option('mytr_default_language', 'en');
    }

    public function get_enabled_languages(): array {
        return get_option('mytr_enabled_languages', ['en', 'fr']);
    }

    public function is_translated_request(): bool {
        return $this->get_current_language() !== $this->get_default_language();
    }
}
```

---

# 6. Module 2 — URL Router

## Responsibility

The URL router allows translated URLs such as:

```txt
/fr/about
/es/products
/de/contact
```

to resolve internally to the original WordPress routes:

```txt
/about
/products
/contact
```

## Rewrite Rule Example

```php
add_action('init', function () {
    add_rewrite_rule(
        '^([a-z]{2})/(.*)?',
        'index.php?mytr_lang=$matches[1]&pagename=$matches[2]',
        'top'
    );
});

add_filter('query_vars', function ($vars) {
    $vars[] = 'mytr_lang';
    return $vars;
});
```

## Important Notes

The router must support:

```txt
/fr/
/fr/about
/fr/category/news
/fr/product/my-product
/fr/blog/my-post
/fr/?s=query
/fr/wp-json/...
```

In a serious version, routing cannot only depend on `pagename`. It must handle posts, pages, categories, taxonomies, products, archives, and search pages.

---

# 7. Module 3 — Output Buffer

## Responsibility

The output buffer captures the final HTML generated by WordPress before it is sent to the browser.

This is the heart of the TranslatePress-like model.

## Simplified Flow

```php
add_action('template_redirect', function () {
    if (is_admin()) {
        return;
    }

    if (wp_doing_ajax()) {
        return;
    }

    ob_start(function ($html) {
        return MyTR_Output_Buffer::translate_output($html);
    });
});
```

## Output Translation

```php
class MyTR_Output_Buffer {
    public static function translate_output(string $html): string {
        $resolver = new Language_Resolver();
        $target_lang = $resolver->get_current_language();

        if (!$resolver->is_translated_request()) {
            return $html;
        }

        $renderer = new Translation_Renderer();

        return $renderer->render_translated_html($html, $target_lang);
    }
}
```

## Why Output Buffering Works Well

It captures content generated by:

```txt
WordPress posts
Pages
Themes
Menus
Widgets
Shortcodes
WooCommerce templates
Page builders
Forms
Plugin-generated HTML
Headers and footers
```

This is why this model is flexible.

---

# 8. Module 4 — HTML Parser

## Responsibility

The parser converts the raw HTML string into a structure that can be safely analyzed and modified.

Avoid using regex as the main parser.

Use one of:

```txt
DOMDocument
Masterminds HTML5 parser
Symfony DomCrawler
simple_html_dom
```

For a professional plugin, an HTML5-compatible parser is better than raw `DOMDocument`, because modern WordPress pages often contain imperfect or complex HTML.

## Parser Flow

```txt
Input: full HTML
        ↓
Load HTML into parser
        ↓
Find translatable nodes
        ↓
Skip non-translatable sections
        ↓
Return a list of translation candidates
```

## Elements to Skip

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

## Texts to Skip

```txt
Empty strings
Whitespace-only strings
Numbers only
Currency amounts only
Dates only, at first
Very short symbols
Tracking IDs
CSS classes
JavaScript code
```

---

# 9. Module 5 — String Extractor

## Responsibility

The string extractor identifies everything that should be translated.

## Extractable Content

### Text nodes

```html
<h1>About us</h1>
<p>We help brands grow.</p>
<button>Contact us</button>
```

### Attributes

```html
<img alt="Team photo">
<input placeholder="Enter your email">
<a title="Read more about us">
```

### SEO metadata

```html
<title>About us - My Company</title>
<meta name="description" content="We help brands grow.">
<meta property="og:title" content="About us">
<meta property="og:description" content="We help brands grow.">
```

### Form labels

```html
<label>Your name</label>
<input value="Send message">
```

### WooCommerce strings

```html
<button>Add to cart</button>
<span>In stock</span>
<span>Checkout</span>
```

## Translation Candidate Object

Internally, each string can be represented like this:

```php
[
    'original_text' => 'About us',
    'normalized_text' => 'About us',
    'hash' => 'sha256_hash_here',
    'type' => 'text_node',
    'context' => 'h1',
    'node_reference' => $node,
]
```

## Normalization

Before hashing and storing strings, normalize them.

Example:

```php
function normalize_string(string $text): string {
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = trim($text);
    $text = preg_replace('/\s+/', ' ', $text);

    return $text;
}
```

Without normalization, these could be treated as different strings:

```txt
"About us"
" About us "
"About   us"
```

---

# 10. Module 6 — Translation Memory

## Responsibility

The translation memory stores discovered original strings and their translated versions.

This is the most important internal database module.

## Suggested Database Table

```sql
CREATE TABLE wp_mytr_strings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_lang VARCHAR(10) NOT NULL,
    target_lang VARCHAR(10) NOT NULL,
    original_hash CHAR(64) NOT NULL,
    original_text LONGTEXT NOT NULL,
    translated_text LONGTEXT NULL,
    context VARCHAR(255) NULL,
    source_url TEXT NULL,
    status TINYINT UNSIGNED NOT NULL DEFAULT 0,
    provider VARCHAR(50) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uniq_translation (
        source_lang,
        target_lang,
        original_hash
    ),
    KEY idx_status (status),
    KEY idx_lang_pair (source_lang, target_lang)
);
```

## Status Values

```txt
0 = discovered, not translated
1 = machine translated
2 = manually reviewed
3 = ignored
4 = needs review
```

## Why Use a Hash?

Do not search only by `original_text`.

Use a hash:

```php
$hash = hash('sha256', normalize_string($original_text));
```

This allows fast lookups and avoids expensive text comparisons.

## Lookup Flow

```txt
Extract strings from page
        ↓
Normalize strings
        ↓
Calculate hashes
        ↓
Bulk query database
        ↓
Return existing translations
        ↓
Insert missing strings as discovered
```

## Important Performance Rule

Never query the database once per string.

Bad:

```txt
100 strings = 100 SQL queries
```

Good:

```txt
100 strings = 1 bulk SQL query
```

---

# 11. Module 7 — Translation Renderer

## Responsibility

The renderer replaces original strings with translated strings in the parsed HTML.

## Rendering Flow

```txt
Receive parsed DOM
        ↓
Receive extracted candidates
        ↓
Get translations from translation memory
        ↓
For each candidate:
    if translation exists:
        replace original content
    else:
        keep original content
        optionally mark as missing
        ↓
Return modified HTML
```

## Pseudo-Code

```php
class Translation_Renderer {
    public function render_translated_html(string $html, string $target_lang): string {
        $parser = new Html_Parser();
        $document = $parser->parse($html);

        $extractor = new String_Extractor();
        $candidates = $extractor->extract($document);

        $memory = new Translation_Memory();
        $translations = $memory->get_translations($candidates, $target_lang);

        foreach ($candidates as $candidate) {
            $hash = $candidate['hash'];

            if (!empty($translations[$hash])) {
                $candidate['node']->nodeValue = $translations[$hash];
            }
        }

        $link_rewriter = new Link_Rewriter();
        $link_rewriter->rewrite_links($document, $target_lang);

        $seo = new Seo_Manager();
        $seo->inject_hreflang($document, $target_lang);

        return $parser->to_html($document);
    }
}
```

---

# 12. Module 8 — Machine Translation Provider

## Responsibility

The machine translator fills missing translations automatically.

Possible providers:

```txt
Google Translate API
DeepL API
OpenAI-compatible translation API
Microsoft Translator
Manual-only mode
```

## Provider Interface

```php
interface Translation_Provider_Interface {
    public function translate_batch(
        array $strings,
        string $source_lang,
        string $target_lang
    ): array;
}
```

## Example Provider Class

```php
class DeepL_Provider implements Translation_Provider_Interface {
    public function translate_batch(
        array $strings,
        string $source_lang,
        string $target_lang
    ): array {
        // Call provider API here.
        // Return array:
        // [
        //   'About us' => 'À propos',
        //   'Contact us' => 'Contactez-nous'
        // ]
    }
}
```

## Auto-Translation Flow

```txt
Page contains missing strings
        ↓
Plugin checks if auto-translation is enabled
        ↓
Plugin sends missing strings in batch to provider
        ↓
Provider returns translated strings
        ↓
Plugin stores translations in local DB
        ↓
Plugin renders translated page
```

## Quota Protection

The plugin should include:

```txt
Daily character limit
Monthly character limit
Provider-specific API key validation
Request batching
Retry control
Error logging
Crawler protection
```

Crawler protection is important because search engines can crawl many translated pages and accidentally trigger large automatic translation costs.

---

# 13. Module 9 — Visual Translation Editor

## Responsibility

The visual editor allows the user to translate the site from the front end.

This is one of the most important user experience features.

## Editor URL

```txt
/fr/about?mytr_editor=1
```

## Editor Flow

```txt
Admin opens visual editor
        ↓
Plugin renders translated page
        ↓
Plugin adds translation metadata to translatable nodes
        ↓
JavaScript detects clickable translation nodes
        ↓
User clicks text on the page
        ↓
Sidebar opens with:
    original text
    current translation
    translation status
        ↓
User edits translation
        ↓
REST API saves translation
        ↓
Preview updates instantly
```

## Example HTML in Editor Mode

Normal visitor mode:

```html
<h1>À propos</h1>
```

Editor mode:

```html
<h1 data-mytr-string-id="123" data-mytr-hash="abc123">
    À propos
</h1>
```

## Sidebar Fields

```txt
Original text
Target language
Current translation
Status
Context
Source URL
Save button
Mark as reviewed
Ignore string
Auto-translate again
```

## REST API Endpoints

```txt
GET    /wp-json/mytr/v1/string/{id}
POST   /wp-json/mytr/v1/string/{id}
POST   /wp-json/mytr/v1/bulk-translate
POST   /wp-json/mytr/v1/mark-reviewed
POST   /wp-json/mytr/v1/ignore
```

## Security

Only authorized users should access the editor.

Required checks:

```php
current_user_can('edit_posts')
current_user_can('manage_options') // for settings
check_ajax_referer()
sanitize input
escape output
```

---

# 14. Module 10 — Gettext String Handling

## Why This Matters

Not all visible text comes from post content.

Many strings come from WordPress themes and plugins using gettext functions:

```php
__('Read more', 'theme-domain');
_e('Add to cart', 'woocommerce');
esc_html__('Checkout', 'woocommerce');
```

Examples:

```txt
Read more
Add to cart
Checkout
Search
No results found
Related products
```

These are dynamic theme/plugin strings.

## Handling Strategy

There are two possible strategies.

---

## Strategy A — Translate Gettext Through WordPress Filters

WordPress exposes filters such as:

```php
gettext
gettext_with_context
ngettext
```

Example:

```php
add_filter('gettext', function ($translated, $text, $domain) {
    $lang = mytr_current_language();

    if ($lang === mytr_default_language()) {
        return $translated;
    }

    return mytr_translate_gettext_string($text, $lang, $domain) ?: $translated;
}, 10, 3);
```

## Strategy B — Let Output Buffer Catch Them

If a gettext string appears in the final HTML, the output buffer can detect it as a normal visible string.

This is easier for an MVP.

However, a dedicated gettext pipeline gives more control and better performance.

---

# 15. Module 11 — Link Rewriter

## Responsibility

When the user is on `/fr/about`, internal links should keep the user in French.

Example:

```html
<a href="/contact">Contact</a>
```

should become:

```html
<a href="/fr/contact">Contact</a>
```

## Link Types to Rewrite

```txt
Internal page links
Menu links
Category links
Product links
Form actions
Canonical alternatives, carefully
Pagination links
Search links
WooCommerce cart/checkout links
```

## Links to Avoid Rewriting

```txt
External links
mailto:
tel:
anchor-only links: #section
admin links
API links, unless explicitly supported
asset URLs: .css, .js, .jpg, .png, .webp
```

## Pseudo-Code

```php
function rewrite_internal_url(string $url, string $lang): string {
    if (is_external_url($url)) {
        return $url;
    }

    if (is_asset_url($url)) {
        return $url;
    }

    if (already_has_language_prefix($url)) {
        return $url;
    }

    return add_language_prefix($url, $lang);
}
```

---

# 16. Module 12 — SEO Manager

## Responsibility

The SEO manager makes translated pages indexable and understandable for search engines.

Minimum required features:

```txt
Translated <title>
Translated meta description
Translated Open Graph title
Translated Open Graph description
hreflang tags
x-default hreflang
Correct canonical behavior
Translated internal links
Translated sitemap URLs
```

## Hreflang Example

```html
<link rel="alternate" hreflang="en" href="https://example.com/about" />
<link rel="alternate" hreflang="fr" href="https://example.com/fr/about" />
<link rel="alternate" hreflang="es" href="https://example.com/es/about" />
<link rel="alternate" hreflang="x-default" href="https://example.com/about" />
```

## Canonical Rule

For a translated page:

```txt
/fr/about
```

the canonical should usually be:

```txt
https://example.com/fr/about
```

not:

```txt
https://example.com/about
```

Otherwise, Google may treat the translated page as a duplicate of the original page.

## SEO Plugin Integration

Later versions should integrate with:

```txt
Yoast SEO
Rank Math
All in One SEO
SEOPress
WooCommerce SEO metadata
```

---

# 17. Module 13 — REST API and AJAX Translation

## Why This Matters

Modern WordPress sites often render some content after page load.

Examples:

```txt
Gutenberg dynamic blocks
WooCommerce cart fragments
AJAX filters
Search suggestions
React/Vue widgets
REST API content
Elementor widgets
Form plugins
```

Output buffering only translates the initial HTML response.

For dynamic content, the plugin also needs REST/AJAX translation.

## REST API Flow

```txt
Frontend requests /wp-json/wp/v2/posts
        ↓
WordPress returns JSON
        ↓
Plugin detects target language
        ↓
Plugin translates selected JSON fields
        ↓
Translated JSON is returned
```

## Common Fields to Translate

```txt
title.rendered
content.rendered
excerpt.rendered
yoast_head_json.title
yoast_head_json.description
```

## Example Hook

```php
add_filter('rest_prepare_post', function ($response, $post, $request) {
    $lang = mytr_current_language();

    if ($lang === mytr_default_language()) {
        return $response;
    }

    $data = $response->get_data();

    if (!empty($data['title']['rendered'])) {
        $data['title']['rendered'] = mytr_translate_html_fragment(
            $data['title']['rendered'],
            $lang
        );
    }

    if (!empty($data['content']['rendered'])) {
        $data['content']['rendered'] = mytr_translate_html_fragment(
            $data['content']['rendered'],
            $lang
        );
    }

    $response->set_data($data);

    return $response;
}, 10, 3);
```

---

# 18. Module 14 — Cache Manager

## Why Caching Is Critical

Parsing HTML and replacing strings on every request can be expensive.

Without caching, the plugin may slow down the site.

## Cache Levels

### 1. Translation lookup cache

Cache translations by language pair and hash.

```txt
key: mytr_translation_fr_abc123
value: À propos
```

### 2. Page-level translated HTML cache

Cache the full translated page.

```txt
key: mytr_page_fr_post_42_modified_2026_05_29
value: full translated HTML
```

### 3. Provider response cache

Avoid sending the same string repeatedly to an external translation provider.

```txt
key: mytr_provider_deepl_en_fr_hash
value: À propos
```

## Cache Invalidation

Invalidate cache when:

```txt
Post/page is updated
Menu is updated
Widget is updated
Theme is changed
Plugin settings change
Translation is manually edited
Language settings change
SEO metadata changes
```

## Suggested MVP Cache

For MVP, start with:

```txt
Bulk database lookup
Object cache for translations
No full-page cache at first
```

Then add full-page translated HTML cache in v2.

---

# 19. Module 15 — Admin Settings

## Settings Pages

The plugin should include:

```txt
General Settings
Languages
Automatic Translation
Translation Memory
SEO Settings
Advanced Settings
Debug/Logs
```

## General Settings

```txt
Default language
Enabled languages
Language URL format
Language switcher behavior
Fallback behavior
```

## Automatic Translation Settings

```txt
Provider
API key
Daily/monthly quota
Auto-translate new strings
Translate only when admin visits
Translate when visitor visits
Block auto-translation for crawlers
```

## SEO Settings

```txt
Enable hreflang
Translate metadata
Translate slugs
Generate multilingual sitemap
Integrate with SEO plugins
```

## Advanced Settings

```txt
Exclude selectors
Exclude pages
Exclude post types
Translate REST API
Translate AJAX responses
Enable debug logs
```

---

# 20. Database Design

## Main Translation Table

```sql
CREATE TABLE wp_mytr_strings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_lang VARCHAR(10) NOT NULL,
    target_lang VARCHAR(10) NOT NULL,
    original_hash CHAR(64) NOT NULL,
    original_text LONGTEXT NOT NULL,
    translated_text LONGTEXT NULL,
    normalized_text LONGTEXT NULL,
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

## Optional Context Table

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

## Optional Glossary Table

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

# 21. Admin Translation Memory UI

The admin UI should allow the user to manage translations without using the visual editor.

## Translation List

Columns:

```txt
Original text
Translated text
Source language
Target language
Status
Context
Source URL
Last seen
Provider
Actions
```

## Filters

```txt
Language pair
Status
Provider
Source URL
Post type
Search by original text
Search by translated text
```

## Actions

```txt
Edit translation
Mark as reviewed
Ignore string
Re-translate automatically
Delete translation
Export CSV
Import CSV
```

---

# 22. Language Switcher

## Shortcode

```php
[mytr_language_switcher]
```

## PHP Template Function

```php
mytr_language_switcher();
```

## Gutenberg Block

Later, create a Gutenberg block:

```txt
Language Switcher Block
```

## Output Example

```html
<ul class="mytr-language-switcher">
    <li><a href="/about">English</a></li>
    <li><a href="/fr/about">Français</a></li>
    <li><a href="/es/about">Español</a></li>
</ul>
```

## Required Behavior

When the visitor is on:

```txt
/fr/about
```

and clicks English, they should go to:

```txt
/about
```

When they click Spanish, they should go to:

```txt
/es/about
```

---

# 23. Slug Translation

## MVP Behavior

In MVP, do not translate slugs.

Example:

```txt
/about
/fr/about
/es/about
```

This is simpler and safer.

## Advanced Behavior

Later, allow slug translation:

```txt
/about
/fr/a-propos
/es/sobre-nosotros
```

## Required Slug Table

```sql
CREATE TABLE wp_mytr_slugs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_lang VARCHAR(10) NOT NULL,
    target_lang VARCHAR(10) NOT NULL,
    original_slug VARCHAR(255) NOT NULL,
    translated_slug VARCHAR(255) NOT NULL,
    object_id BIGINT UNSIGNED NULL,
    object_type VARCHAR(50) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uniq_slug (
        target_lang,
        translated_slug,
        object_type
    )
);
```

Slug translation is complex because it affects routing, canonical URLs, redirects, sitemaps, and duplicate detection.

For that reason, it should not be part of v1.

---

# 24. Page Builders Compatibility

The TranslatePress-like approach is strong because it works after page builders render their HTML.

It can support:

```txt
Elementor
Divi
WPBakery
Gutenberg
Bricks
Oxygen
Beaver Builder
```

## Why It Works

Page builders eventually output HTML.

Since the plugin translates final HTML, it does not need to deeply understand each page builder's internal data model at first.

## Limitation

Some builders render dynamic content through JavaScript after page load.

For those cases, the plugin needs:

```txt
REST API translation
AJAX response translation
MutationObserver-based frontend dynamic translation
```

---

# 25. WooCommerce Compatibility

WooCommerce adds special cases.

## Content to Translate

```txt
Product title
Product description
Short description
Product attributes
Variations
Cart
Checkout
Emails
Order statuses
Payment labels
Shipping labels
Coupon messages
Stock messages
```

## MVP WooCommerce Support

For a first version:

```txt
Translate visible product page HTML
Translate cart/checkout visible strings
Translate WooCommerce gettext strings
Preserve cart URLs with language prefix
```

## Advanced WooCommerce Support

Later:

```txt
Translate product slugs
Translate product attributes
Translate transactional emails
Translate checkout AJAX fragments
Translate structured product metadata
Translate product schema markup
```

---

# 26. Security Considerations

## Admin Access

Only trusted users should manage translations.

Recommended capabilities:

```txt
manage_options for global settings
edit_posts for visual translation editor
edit_pages for page-specific translation
```

## Data Sanitization

When saving translations:

```php
$translated = wp_kses_post($_POST['translated_text']);
```

When rendering translations:

```php
echo wp_kses_post($translated_text);
```

For attributes:

```php
esc_attr($translated_text)
```

For URLs:

```php
esc_url($url)
```

## Nonce Protection

Every REST/AJAX write action should verify a nonce.

```php
check_ajax_referer('mytr_editor_nonce');
```

## API Key Storage

Provider API keys should be stored in WordPress options, but not exposed in the frontend.

```txt
Never print API keys in JavaScript
Never expose provider keys in REST responses
Mask keys in admin UI
```

---

# 27. Performance Considerations

## Biggest Risks

```txt
Parsing full HTML on every request
Too many database queries
Calling external translation APIs during visitor requests
Large pages with many repeated strings
WooCommerce cart/checkout fragments
Dynamic content
```

## Performance Rules

```txt
Use bulk extraction
Use bulk database lookup
Avoid one SQL query per string
Avoid one API request per string
Use batch translation
Cache translations
Cache full translated pages when possible
Skip translation for assets and admin pages
Skip bots for automatic translation generation
```

## Good Request Flow

```txt
Request translated page
        ↓
Extract all strings
        ↓
One database query for all hashes
        ↓
One insert for missing strings
        ↓
One provider batch call if needed
        ↓
One bulk update
        ↓
Render page
```

---

# 28. MVP Scope

## Version 1 — Core Translation Layer

Build only the core engine.

```txt
Language settings
/fr/ URL support
Language switcher shortcode
Output buffering
HTML parsing
String extraction
Local translation table
Manual admin translation editor
Basic automatic translation provider
Internal link rewriting
Basic hreflang tags
```

## Version 1 Should Not Include

```txt
Translated slugs
Full visual editor
WooCommerce advanced support
REST API translation
AJAX translation
Multilingual sitemap
Complex SEO plugin integration
Glossary
Team workflow
Translation export/import
Full-page cache
```

---

# 29. Version 2 — Visual Editing and SEO

```txt
Front-end visual editor
Clickable translated strings
REST API save endpoint
Translation status workflow
Meta title/description translation
Open Graph translation
Canonical handling
Better hreflang
SEO plugin compatibility
Object cache support
```

---

# 30. Version 3 — Advanced Compatibility

```txt
WooCommerce advanced support
REST API translation
AJAX translation
Page builder dynamic content support
Translated slugs
Multilingual sitemap
Glossary
CSV import/export
Translation memory suggestions
Full-page translated HTML cache
Provider quota dashboard
Debugging tools
```

---

# 31. Development Order

Recommended implementation order:

```txt
1. Plugin bootstrap
2. Admin settings for languages
3. URL language resolver
4. Rewrite rules for /fr/
5. Output buffering
6. HTML parser
7. String extractor
8. Database translation table
9. Bulk translation lookup
10. Manual translation admin page
11. Renderer replacement
12. Link rewriting
13. Language switcher
14. Basic hreflang
15. Machine translation provider
16. Cache layer
17. Visual editor
18. REST/AJAX support
19. WooCommerce support
20. SEO plugin integrations
```

---

# 32. Minimal MVP Request Flow

```txt
/fr/about requested
        ↓
Rewrite rule sets mytr_lang = fr
        ↓
WordPress loads /about
        ↓
Output buffer starts
        ↓
WordPress renders normal English HTML
        ↓
Plugin captures HTML
        ↓
Plugin parses DOM
        ↓
Plugin extracts strings:
    "About us"
    "We help brands grow"
    "Contact us"
        ↓
Plugin calculates hashes
        ↓
Plugin queries wp_mytr_strings
        ↓
Found:
    "About us" → "À propos"
    "Contact us" → "Contactez-nous"
        ↓
Missing:
    "We help brands grow"
        ↓
Plugin inserts missing string as status = discovered
        ↓
Plugin replaces known strings
        ↓
Plugin rewrites /contact → /fr/contact
        ↓
Plugin returns translated page
```

---

# 33. Key Difference From Polylang/WPML

This plugin does **not** create separate translated posts.

## Polylang/WPML-style model

```txt
Post ID 10 = About us
Post ID 22 = À propos
Post ID 35 = Sobre nosotros
```

## TranslatePress-like model

```txt
Post ID 10 = About us

Translation memory:
"About us" → "À propos"
"About us" → "Sobre nosotros"
```

## Consequence

TranslatePress-like model is easier for:

```txt
Page builders
Theme strings
Shortcodes
Visual editing
Fast setup
Client-friendly workflows
```

But it is harder for:

```txt
Complex editorial workflows
Language-specific page structure
Different content per market
Deep content localization
Highly custom translated URLs
```

---

# 34. Recommended Technical Stack

## WordPress Side

```txt
PHP 8.1+
WordPress hooks/actions/filters
Custom database tables with dbDelta()
REST API endpoints
Admin pages
Shortcodes
Gutenberg block later
```

## Parsing

Recommended:

```txt
Masterminds/html5-php
```

Alternative:

```txt
DOMDocument for MVP
```

## JavaScript

For visual editor:

```txt
Vanilla JS or React
WordPress REST API
wp.apiFetch
MutationObserver for dynamic preview updates
```

## Translation Providers

```txt
DeepL API
Google Cloud Translation
OpenAI-compatible API
Manual-only fallback
```

---

# 35. Final Architecture Summary

The core architecture is:

```txt
Language Resolver
        ↓
URL Router
        ↓
WordPress Normal Rendering
        ↓
Output Buffer
        ↓
HTML Parser
        ↓
String Extractor
        ↓
Translation Memory
        ↓
Machine Translation Provider, optional
        ↓
Renderer
        ↓
Link Rewriter
        ↓
SEO Manager
        ↓
Translated HTML Response
```

The plugin behaves like a self-hosted translation layer.

It keeps the WordPress site simple:

```txt
One original page
Many translated renderings
Translations stored separately
```

This is the right architecture if the goal is to build a developer-controlled, WordPress-native, TranslatePress-like plugin rather than a SaaS connector like Weglot.

---

# 36. Practical Development Philosophy

Start small.

The minimum valuable product is not the visual editor.  
The minimum valuable product is this:

```txt
/fr/ route
+ output buffer
+ string extraction
+ database translation lookup
+ replacement
+ admin translation table
```

Once that engine works, the visual editor becomes a user interface on top of the same translation memory.

The most important thing is to build the translation engine cleanly first.

