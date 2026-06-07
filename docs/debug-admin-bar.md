# Debugging Admin Bar Issues

If admin bar content is still appearing in your translated pages or translation table, follow this diagnostic guide.

## Step 1: Verify the Filter is Applied

### Test if the show_admin_bar filter is working:

**A. Check with scan parameter:**
```
Visit your site as a logged-in user:
https://yoursite.com/page/

Then visit with the scan parameter:
https://yoursite.com/page/?translangs_scan=1
```

**Expected:** The admin bar should NOT appear on the second URL.

**B. View page source:**
```
Right-click → View Page Source
Search for "wpadminbar"
```

**Expected:** No `<div id="wpadminbar">` should be found.

**Result:**
- ✅ No wpadminbar → Filter is working, problem is elsewhere
- ❌ wpadminbar still present → Filter is NOT working

---

## Step 2: If Filter is NOT Working

### Possible causes:

**1. Parameter name mismatch:**
Check that you're using exactly `?translangs_scan=1` or `?translangs_preview=1`

**2. Filter hook timing:**
The `show_admin_bar` filter must run before `wp_head`. Verify it's in `Plugin::boot()` which runs on `plugins_loaded` (early enough).

**3. Theme/plugin override:**
Another plugin might be forcing `show_admin_bar(true)` with higher priority.

**Check:** In `wp-config.php`, temporarily add:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

Then in `wp-content/debug.log`, search for `show_admin_bar` to see which plugins are hooking it.

**4. WordPress version compatibility:**
`show_admin_bar` was introduced in WordPress 3.1. Verify your version is >= 3.1.

---

## Step 3: If Filter IS Working, But Strings Still Captured

### The parser might not be excluding the admin bar HTML.

**Debug the parser exclusion:**

Create a temporary test file at `wp-content/plugins/translangs/test-parser.php`:

```php
<?php
// Test if parser correctly excludes admin bar elements

use TransLangs\PublicSite\Infrastructure\HtmlTextTools;

$tools = new HtmlTextTools();

// Test 1: Check ID matching
echo "Test ID 'wpadminbar': " . ($tools->shouldSkipElementByAttributes('wpadminbar', '') ? 'SKIP' : 'CAPTURE') . "\n";

// Test 2: Check class matching
echo "Test class 'ab-item': " . ($tools->shouldSkipElementByAttributes('', 'ab-item') ? 'SKIP' : 'CAPTURE') . "\n";

// Test 3: Check wildcard matching
echo "Test class 'ab-something-else': " . ($tools->shouldSkipElementByAttributes('', 'ab-something-else') ? 'SKIP' : 'CAPTURE') . "\n";

// Test 4: Check mixed classes
echo "Test mixed classes 'some-class ab-item other': " . ($tools->shouldSkipElementByAttributes('', 'some-class ab-item other') ? 'SKIP' : 'CAPTURE') . "\n";
```

**Expected output:**
```
Test ID 'wpadminbar': SKIP
Test class 'ab-item': SKIP
Test class 'ab-something-else': SKIP
Test mixed classes 'some-class ab-item other': SKIP
```

If any say CAPTURE, the matching logic has a bug.

---

## Step 4: Check What's Actually Being Captured

### Query the translation table directly:

Connect to WordPress database and run:

```sql
SELECT original_text, COUNT(*) as count
FROM wp_tl_translations_i18n
WHERE original_text IN ('Howdy', 'Dashboard', 'Edit Page', 'Edit Post', 'New', 'Customize', 'Search', 'Visit Site', 'Updates', 'Edit')
GROUP BY original_text;
```

**Expected:** 0 rows (no admin bar strings)

**If you see rows:**
- These are historical captures (before the fix was deployed)
- Run the cleanup command: `wp translangs cleanup admin-bar`

---

## Step 5: Full Diagnostic Command

Run this WP-CLI command for a full report:

```bash
# Check for admin bar strings
wp db query "SELECT COUNT(*) as admin_bar_strings FROM wp_tl_translations_i18n WHERE original_text IN ('Howdy', 'Dashboard', 'Edit Page', 'Edit Post', 'New', 'Customize', 'Search', 'Visit Site', 'Updates', 'Edit');"

# Check total i18n strings
wp db query "SELECT COUNT(*) as total_i18n_strings FROM wp_tl_translations_i18n;"

# Check if wpadminbar appears in the HTML source of any cached translations
wp db query "SELECT COUNT(*) FROM wp_tl_page_cache WHERE html LIKE '%wpadminbar%';"
```

---

## Step 6: Nuclear Option — Force Clean

If strings are stubbornly stuck:

### Delete by pattern:
```php
// In a temporary admin page or WP-CLI command:
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->prefix}tl_translations_i18n WHERE original_text LIKE 'Howdy%'");
$wpdb->query("DELETE FROM {$wpdb->prefix}tl_translations_i18n WHERE original_text = 'Dashboard'");
$wpdb->query("DELETE FROM {$wpdb->prefix}tl_translations_i18n WHERE original_text = 'Edit'");
// ... etc for all admin bar strings
```

Or use the CLI cleanup command:
```bash
wp translangs cleanup admin-bar
```

---

## Reporting Issues

If none of the above work, provide:

1. **WordPress version:** `wp core version`
2. **Admin bar appears on:** `?translangs_scan=1` or normal pages?
3. **Output of:** `wp db query "SELECT original_text FROM wp_tl_translations_i18n WHERE original_text = 'Howdy' LIMIT 1;"`
4. **Which parser is running:** Check `src/PublicSite/Infrastructure/WpHtmlProcessorRenderParser.php::isSupported()` returns true or false
5. **Page source with wpadminbar ID:** Does it exist and at what nesting level?
