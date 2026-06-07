# Admin Bar Filtering

## Problem

WordPress displays an admin bar to logged-in users on the frontend. This admin bar contains UI text like:
- "Howdy"
- "Dashboard"
- "Edit Page"
- "New"
- "Customize"
- etc.

Without filtering, these strings get captured into the translation memory as translatable content, polluting the database with non-website text.

## Solution: Three-Layer Defense

### Layer 1: Prevent admin bar from rendering (Plugin::boot)

When a page request includes scan/preview query parameters, the admin bar is disabled **before it renders**.

```php
add_filter('show_admin_bar', function ($show) {
    if (!empty($_GET['translangs_scan']) || !empty($_GET['translangs_preview'])) {
        return false;
    }
    return $show;
}, 999);
```

**Usage:** Append `?translangs_scan=1` or `?translangs_preview=1` to any URL to suppress the admin bar during that request.

### Layer 2: Parser exclusion (HtmlTextTools)

Even if the admin bar appears despite Layer 1, the HTML parser skips any elements with admin bar IDs or classes before extracting text.

**Excluded elements:**
- ID: `wpadminbar`
- Classes: `ab-top-menu`, `ab-sub-wrapper`, `ab-item`, `screen-reader-shortcut`

The `shouldSkipElementByAttributes()` method checks both the `id` and `class` attributes of every element. If an ancestor matches, its text nodes are skipped entirely.

### Layer 3: Cleanup existing strings (WP-CLI command)

If admin bar strings were already captured before these fixes were added, you can remove them:

```bash
wp translangs cleanup admin-bar
```

This command deletes all instances of known admin bar strings from the i18n translation table:
- Howdy
- Customize
- Edit Page
- Edit Post
- Edit
- New
- Dashboard
- Search
- Visit Site
- Updates

## Implementation

**Affected files:**
- `src/Bootstrap/Plugin.php` — registers the `show_admin_bar` filter and WP-CLI command
- `src/PublicSite/Infrastructure/HtmlTextTools.php` — defines excluded admin bar classes/IDs
- `src/TranslationMemory/Infrastructure/Repositories/I18nTranslationsRepository.php` — adds `deleteByOriginalTexts()` method
- `src/TranslationMemory/Infrastructure/WpDbTranslationMemoryRepository.php` — delegates `deleteI18nByOriginalTexts()`
- `src/Cli/CleanupCommand.php` — WP-CLI command implementation

## When to Use

**Prevention (Layers 1–2):** These are automatic and always active. They prevent future captures.

**Cleanup (Layer 3):** Run this command once after upgrading to clean up historical pollution:

```bash
wp translangs cleanup admin-bar
```

## Extending

To add more exclusion patterns or strings:

1. **Parser exclusions** (more element patterns):
   - Edit `WORDPRESS_ADMIN_UI_CLASSES` in `HtmlTextTools.php`

2. **Cleanup strings** (more known strings to delete):
   - Edit `ADMIN_BAR_STRINGS` in `CleanupCommand.php`
   - Then run the cleanup command again

## Testing

Verify the fix by:

1. Open a translated page in a logged-in session
2. View the page source — confirm `<div id="wpadminbar">` is either absent or skipped
3. Check the translation editor — confirm no new "Howdy", "Dashboard", etc. strings appear
4. Run `wp translangs cleanup admin-bar` to purge any historical entries
