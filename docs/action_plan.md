# Action Plan: WordPress Translation Plugin

Step-by-step execution plan for building a TranslatePress-like WordPress translation plugin.

## Phase 0: Project Preparation

### Goal

Prepare the technical foundation before coding features.

### Tasks

Create a local WordPress development environment with:

- WordPress latest stable
- PHP 8.1+
- MySQL/MariaDB
- `WP_DEBUG` enabled
- A simple theme
- A few test pages

Prepare test content:

- `/about`
- `/contact`
- `/blog`
- `/product/example-product`, if WooCommerce is installed later

Create sample texts:

- About us
- Contact us
- We help brands grow.
- Enter your email
- Read more

### Deliverable

A working local WordPress site where the plugin can be activated and tested.

## Phase 1: Plugin Bootstrap

### Step 1: Create the Plugin Skeleton

#### Goal

Create the base plugin folder and class structure.

#### Recommended Structure

```text
my-translation-plugin/
├── my-translation-plugin.php
├── uninstall.php
├── includes/
│   ├── bootstrap.php
│   ├── public/
│   ├── admin/
│   ├── editor/
│   ├── engine/
│   └── integrations/
├── assets/
└── templates/
```

#### Main File

```php
<?php
/**
 * Plugin Name: My Translation Plugin
 * Description: TranslatePress-like WordPress translation plugin.
 * Version: 0.1.0
 * Author: Your Name
 */

if (!defined('ABSPATH')) {
    exit;
}

define('MYTR_PLUGIN_FILE', __FILE__);
define('MYTR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MYTR_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once MYTR_PLUGIN_DIR . 'includes/bootstrap.php';
```

#### Deliverable

The plugin appears in WordPress admin and can be activated.

### Step 2: Add Activation and Deactivation Hooks

#### Goal

Prepare the plugin for database creation and rewrite rule flushing.

#### Use Activation For

- Creating database tables
- Setting default options
- Registering rewrite rules
- Flushing rewrite rules once

#### Important

Do not flush rewrite rules on every request. It should be done on activation or when routing settings change.

#### Deliverable

Plugin activation initializes the first required settings and tables.

## Phase 2: Database and Core Engine Foundation

### Step 3: Create the Translation Memory Table

#### Goal

Create the local translation storage.

Start with only one table:

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

#### Status Values

| Value | Meaning |
| --- | --- |
| `0` | Discovered, not translated |
| `1` | Machine translated |
| `2` | Manually reviewed |
| `3` | Ignored |
| `4` | Needs review |

The revised architecture defines this translation memory as the local storage layer for original strings and translated versions.

#### Deliverable

After activation, the database contains `wp_mytr_strings`.

### Step 4: Build the Normalizer

#### Goal

Normalize strings before hashing and storage.

Create:

```text
includes/engine/class-normalizer.php
```

#### Example

```php
class MyTR_Normalizer
{
    public function normalize(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim($text);
        $text = preg_replace('/\s+/', ' ', $text);

        return $text;
    }
}
```

#### Acceptance Test

These inputs should produce the same normalized string:

```text
"About us"
" About us "
"About   us"
```

Result:

```text
"About us"
```

### Step 5: Build the Hash Generator

#### Goal

Generate stable hashes for translation lookup.

Create:

```text
includes/engine/class-hash-generator.php
```

#### Example

```php
class MyTR_Hash_Generator
{
    public function generate(string $normalized_text): string
    {
        return hash('sha256', $normalized_text);
    }
}
```

#### Deliverable

The same normalized string always produces the same hash.

### Step 6: Build the Translation Memory Repository

#### Goal

Centralize all DB access.

Create:

```text
includes/engine/class-translation-memory-repository.php
```

#### Required Methods

```php
find_many_by_hashes(array $hashes, string $source_lang, string $target_lang): array;

insert_missing_strings(array $candidates, string $source_lang, string $target_lang): void;

save_translation(int $id, string $translated_text, int $status): void;

mark_reviewed(int $id): void;

ignore_string(int $id): void;
```

#### Important Rule

Never query one string at a time. Always bulk lookup.

This is important because the approved architecture explicitly depends on bulk lookup and missing-string insertion before rendering.

#### Deliverable

A PHP unit/manual test can insert strings, lookup strings by hash, and update translations.

## Phase 3: Admin Language Settings

### Step 7: Create the Settings Page

#### Goal

Let the site owner configure languages.

Create:

```text
/wp-admin/admin.php?page=mytr-settings
```

#### Initial Settings

| Setting | Default |
| --- | --- |
| Default language | `en` |
| Enabled languages | `en`, `fr` |
| Default language prefix | Disabled |
| Cookie preference | Enabled |
| Browser detection | Disabled |
| Auto redirect | Disabled |

#### Recommended Initial Behavior

| URL | Meaning |
| --- | --- |
| `/about` | Default language |
| `/fr/about` | French |

#### Deliverable

Admin can configure default language and enabled languages.

### Step 8: Create the Languages Page

#### Goal

Manage active languages.

Create:

```text
/wp-admin/admin.php?page=mytr-languages
```

#### Fields

- Language code
- Language name
- Native name
- Text direction
- Status
- URL slug

#### Example

| Code | Language | Native Name | Direction | Status | URL Slug |
| --- | --- | --- | --- | --- | --- |
| `en` | English | English | `ltr` | Active | `en` |
| `fr` | French | Français | `ltr` | Active | `fr` |
| `ar` | Arabic | العربية | `rtl` | Active | `ar` |

#### Deliverable

The plugin can return:

```php
mytr_get_default_language();
mytr_get_enabled_languages();
mytr_is_enabled_language('fr');
```

## Phase 4: Public URL Language Routing

### Step 9: Implement the URL Language Resolver

#### Goal

Detect language from the URL first.

#### Resolver Priority

1. URL language prefix
2. Explicit admin/preview query parameter
3. Cookie preference
4. Browser language, optional
5. Default site language

#### Important

The URL language must always win over cookies. For example, `/fr/about` must render French even if the cookie says `preferred_lang=es`.

#### Deliverable

The resolver correctly returns:

| URL | Language |
| --- | --- |
| `/fr/about` | `fr` |
| `/es/about` | `es` |
| `/about` | `en` |

### Step 10: Add Rewrite Rules for Language Prefixes

#### Goal

Make WordPress understand `/fr/about`.

Use WordPress rewrite rules. WordPress provides `add_rewrite_rule()` for adding custom rewrite rules, and this is the correct mechanism for clean plugin routes in WordPress.

#### Initial Simplified Rule

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

#### Important

This first version will work mainly for pages. Later, improve it for:

- Posts
- Categories
- Archives
- Search
- WooCommerce products
- Custom post types

#### Deliverable

Opening `/fr/about` loads the original WordPress `/about` page internally.

### Step 11: Build the Public Route Mapper

#### Goal

Map public language URLs to original WordPress URLs.

#### Examples

| Public URL | Internal URL |
| --- | --- |
| `/fr/about` | `/about` |
| `/es/contact` | `/contact` |
| `/de/products/item` | `/products/item` |
| `/fr/category/news` | `/category/news` |

#### Deliverable

The user sees `/fr/about` in the browser, but WordPress internally resolves `/about`.

## Phase 5: Output Buffer and HTML Parsing

### Step 12: Add the Output Buffer

#### Goal

Capture final HTML before it is sent to the browser.

Create:

```text
includes/public/class-output-buffer.php
```

#### Example

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

#### Skip

- `/wp-admin/`
- `/wp-login.php`
- `/wp-cron.php`
- `/xmlrpc.php`
- REST API requests
- AJAX requests, for now
- Static assets

#### Deliverable

When visiting `/fr/about`, the plugin can capture and return the same HTML unchanged.

### Step 13: Build the HTML Parser

#### Goal

Parse the final HTML safely.

For MVP, use `DOMDocument`. Later, move to a stronger HTML5 parser.

Create:

```text
includes/public/class-html-parser.php
```

#### Skip Nodes

- `script`
- `style`
- `code`
- `pre`
- `noscript`
- `svg`
- `canvas`
- `[data-no-translate]`
- `.notranslate`
- `.wp-admin-bar`

#### Deliverable

The parser can load the HTML and return a DOM object without breaking the page.

### Step 14: Build the String Extractor

#### Goal

Extract translatable strings from the DOM.

#### Start With

- Text nodes
- `input` placeholder
- `textarea` placeholder
- `img` alt
- `title` attributes
- Meta description

#### Ignore

- Empty strings
- Numbers only
- Very short symbols
- `script`, `style`, `code`, and `pre` content

#### Candidate Object

```php
[
    'original_text' => 'About us',
    'normalized_text' => 'About us',
    'hash' => '...',
    'type' => 'text_node',
    'context' => 'h1',
    'node' => $node,
]
```

#### Deliverable

Visiting `/fr/about` inserts missing strings into `wp_mytr_strings` with status `0`.

## Phase 6: First Working Translation Rendering

### Step 15: Implement Bulk Lookup and Missing-String Insertion

#### Goal

When the extractor finds strings, the plugin should:

1. Normalize all strings.
2. Generate hashes.
3. Bulk query `wp_mytr_strings`.
4. Insert missing strings.
5. Return existing translations.

#### Deliverable

First visit to `/fr/about` discovers strings. Second visit does not duplicate them.

### Step 16: Build the Translation Renderer

#### Goal

Replace original strings with translated strings.

Create:

```text
includes/public/class-translation-renderer.php
```

#### Rendering Flow

1. Receive DOM.
2. Receive candidates.
3. Lookup translations.
4. Replace translated strings.
5. Keep missing strings in original language.
6. Return HTML.

#### Manual Test

In DB, manually set:

| Original | Translation |
| --- | --- |
| About us | À propos |
| Contact us | Contactez-nous |

Then visit:

```text
/fr/about
```

Expected result:

```text
À propos
Contactez-nous
```

This gives the first true MVP: a language-prefixed page that renders translations from local DB, without duplicating WordPress posts.

## Phase 7: Admin Translation Memory Table

### Step 17: Create the Translation Memory Admin Page

#### Goal

Allow the admin to edit translations without touching the database.

Create:

```text
/wp-admin/admin.php?page=mytr-translations
```

#### Columns

- Original text
- Translated text
- Source language
- Target language
- Status
- Context
- Source URL
- Last seen
- Actions

#### Filters

- Language pair
- Status
- Search original text
- Search translated text
- Source URL

#### Actions

- Edit
- Save
- Mark as reviewed
- Ignore
- Delete

#### Deliverable

Admin can manually translate discovered strings from the WordPress dashboard.

### Step 18: Add Translation Save Logic

#### Goal

Saving a translation updates:

- `translated_text`
- `status`
- `updated_at`

If manually saved:

```text
status = 2
```

#### Security

Use:

- `current_user_can('manage_options')`
- `check_admin_referer()`
- Sanitized input
- Escaped output

#### Deliverable

Admin can translate a string, refresh `/fr/about`, and see the translated version.

## Phase 8: Link Rewriting and Language Switcher

### Step 19: Implement the Link Rewriter

#### Goal

Keep the visitor inside the selected language.

#### Example

Original:

```html
<a href="/contact">Contact</a>
```

On French page:

```html
<a href="/fr/contact">Contact</a>
```

#### Skip

- External links
- `mailto:`
- `tel:`
- `#anchors`
- `/wp-admin/`
- Assets
- API URLs

#### Deliverable

Menus and internal links on `/fr/about` point to `/fr/...`.

### Step 20: Build the Language Switcher

#### Goal

Let visitors switch language.

#### Shortcode

```text
[mytr_language_switcher]
```

#### Example Output

```html
<ul class="mytr-language-switcher">
    <li><a href="/about">English</a></li>
    <li><a href="/fr/about">Français</a></li>
    <li><a href="/es/about">Español</a></li>
</ul>
```

#### Selection Behavior

When a user selects a language:

1. Redirect to the language-specific URL.
2. Set `preferred_lang=selected_language`.

The cookie remains preference only; the target URL remains canonical.

#### Deliverable

The user can move between `/about`, `/fr/about`, and `/es/about`.

## Phase 9: Basic SEO

### Step 21: Add Hreflang Tags

#### Goal

Tell search engines about language alternatives.

#### Example

```html
<link rel="alternate" hreflang="en" href="https://example.com/about" />
<link rel="alternate" hreflang="fr" href="https://example.com/fr/about" />
<link rel="alternate" hreflang="es" href="https://example.com/es/about" />
<link rel="alternate" hreflang="x-default" href="https://example.com/about" />
```

#### Deliverable

Each translated page includes correct alternate language URLs.

### Step 22: Add Canonical Handling

#### Goal

Avoid making `/fr/about` canonical to `/about`.

#### Correct

```text
/fr/about canonical -> /fr/about
```

#### Not Correct

```text
/fr/about canonical -> /about
```

#### Deliverable

Translated pages have their own canonical URLs.

## Phase 10: MVP Validation

### Step 23: Test the Complete MVP Flow

#### Test Case

Create `/about` with:

- About us
- We help brands grow.
- Contact us

Visit:

```text
/fr/about
```

Confirm DB contains discovered strings.

Go to:

```text
/wp-admin/admin.php?page=mytr-translations
```

Translate:

| Original | Translation |
| --- | --- |
| About us | À propos |
| We help brands grow. | Nous aidons les marques à grandir. |
| Contact us | Contactez-nous |

Refresh:

```text
/fr/about
```

Expected:

```text
À propos
Nous aidons les marques à grandir.
Contactez-nous
```

#### MVP Acceptance Criteria

- `/fr/about` resolves correctly.
- The original `/about` page remains unchanged.
- Strings are discovered automatically.
- Admin can translate discovered strings.
- Translated strings render on `/fr/about`.
- Internal links stay under `/fr/`.
- Language switcher works.
- Basic hreflang exists.
- Admin pages are not translated.

At this point, you have the first usable version.

## Phase 11: Machine Translation Provider

### Step 24: Add Provider Interface

#### Goal

Prepare automatic translation without locking the plugin to one provider.

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

#### Start With

- `ManualProvider`
- `FakeProvider` for testing
- `DeepLProvider` or `GoogleProvider` later

#### Deliverable

A missing string can be translated through a provider and saved with:

```text
status = 1
```

### Step 25: Add Quota and Crawler Protection

#### Goal

Avoid accidental API cost.

#### Settings

- Daily character limit
- Monthly character limit
- Auto-translate only when admin visits
- Block crawler-triggered translation
- Provider error logs

#### Recommended Default

Do not auto-translate on public visitor traffic in v1. Only auto-translate from admin action.

## Phase 12: Cache Manager

### Step 26: Add Translation Lookup Cache

#### Goal

Avoid repeated DB queries.

Cache by:

- `source_lang`
- `target_lang`
- `hash`

Example key:

```text
mytr_translation_en_fr_abc123
```

#### Deliverable

Repeated visits use cached translations.

### Step 27: Add Cache Invalidation

Clear cache when:

- Translation is saved
- Translation is ignored
- Language settings change
- Page content changes
- Plugin settings change

#### Deliverable

Saved translations appear immediately after cache clear.

## Phase 13: Visual Editor

Do this only after the MVP engine is stable. Your own architecture already defines visual editing as a second-stage admin experience, not the foundation of the plugin.

### Step 28: Create the Visual Editor Admin Shell

#### Goal

Create:

```text
/wp-admin/admin.php?page=mytr-visual-editor&url=/fr/about
```

#### Layout

- Top bar
- Sidebar
- Preview iframe

The iframe loads:

```text
/fr/about?mytr_preview=1
```

### Step 29: Inject DOM Markers in Preview Mode

#### Goal

Make translatable nodes clickable.

Normal mode:

```html
<h1>À propos</h1>
```

Preview mode:

```html
<h1 data-mytr-string-id="123" data-mytr-hash="abc123">
    À propos
</h1>
```

#### Deliverable

The iframe exposes clickable translation nodes only for logged-in authorized admins.

### Step 30: Add REST Save Actions

Use WordPress REST API for the editor. The official WordPress docs describe the REST API as a JSON interface for interacting with WordPress content and as a foundation for custom plugin/admin interfaces.

#### Endpoints

```text
GET  /wp-json/mytr/v1/string/{id}
POST /wp-json/mytr/v1/string/{id}
POST /wp-json/mytr/v1/string/{id}/review
POST /wp-json/mytr/v1/string/{id}/ignore
```

When registering custom routes, WordPress says `register_rest_route()` should be used on the `rest_api_init` hook.

#### Deliverable

Clicking a text in the iframe opens it in the sidebar, and saving updates the translation memory.

## Phase 14: Integrations

### Step 31: Add Gettext Support

#### Goal

Translate theme/plugin strings.

Hook into:

- `gettext`
- `gettext_with_context`
- `ngettext`

This catches strings like:

- Read more
- Add to cart
- Checkout
- Search
- No results found

For MVP, output buffer can catch many of these after render, but gettext support improves control.

### Step 32: Add REST/AJAX Translation

#### Goal

Support dynamic content.

Start with REST:

- `rest_prepare_post`
- `rest_prepare_page`

Then handle AJAX only for explicit integrations.

Do not translate all AJAX blindly.

### Step 33: Add WooCommerce Compatibility

Start simple:

- Product page visible HTML
- Cart/checkout visible HTML
- WooCommerce gettext strings
- Cart/checkout link rewriting

Later:

- Product attributes
- Variations
- Product slugs
- Transactional emails
- Cart fragments
- Schema markup

WooCommerce and page builders should be treated as later integration layers, not as v1 core.

### Step 34: Add SEO Plugin Compatibility

Integrate later with:

- Yoast SEO
- Rank Math
- All in One SEO
- SEOPress

Translate:

- SEO title
- Meta description
- Open Graph title
- Open Graph description
- Breadcrumb labels
- Schema text fields

## Phase 15: Advanced Features

### Step 35: Add Translated Slugs

Only after routing is stable.

Example:

```text
/about
/fr/a-propos
/es/sobre-nosotros
```

This affects:

- Routing
- Canonical URLs
- Redirects
- Sitemaps
- Duplicate detection
- Language switcher

So it should not be part of the first version.

### Step 36: Add Multilingual Sitemap

Generate URLs like:

```text
/about
/fr/about
/es/about
```

Later with translated slugs:

```text
/about
/fr/a-propos
/es/sobre-nosotros
```

## Recommended Delivery Roadmap

### Version 0.1: Technical Proof

- Plugin bootstrap
- Database table
- Language settings
- URL resolver
- Rewrite rules
- Output buffer
- HTML parser
- String extractor
- Missing string discovery

### Version 0.2: First Usable MVP

- Translation memory admin table
- Manual translation save
- Translation renderer
- Link rewriter
- Language switcher
- Basic hreflang

### Version 0.3: Better Production MVP

- Cache manager
- Machine translation provider
- Quota protection
- Better admin filters
- Basic SEO metadata translation

### Version 0.4: Visual Editor

- Admin editor shell
- Preview iframe
- DOM markers
- Sidebar editor
- REST save actions

### Version 0.5: Compatibility

- Gettext support
- REST translation
- AJAX translation
- WooCommerce MVP
- SEO plugin integration

### Version 1.0: Production Release

- Stable routing
- Stable translation memory
- Stable admin UI
- Cache invalidation
- Security review
- Performance review
- Export/import
- Logs/debugging
- Documentation
