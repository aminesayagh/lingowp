<?php

/**
 * REPRODUCTION SUITE — subfolder installs, phase 5: reading INCOMING requests.
 * Every assertion states the DESIRED behavior; on a subfolder install a FAIL
 * means "bug reproduced". Each subfolder case has a root-install control right
 * before it, which must pass both before and after the fix.
 *
 * Registered in composer.json's test:db together with the fix (same convention
 * as slug_routing_bugs_it.php): from then on it must stay all-green.
 *
 * Reproduced live first (2026-10-04, sites/lingowp-subfolder, WordPress at
 * /blog): the switcher links to /blog/fr/a-propos/ — outgoing slug translation
 * already works behind the folder — and that URL 404s, because the incoming
 * side reads "blog" as the language segment and never maps a-propos -> about.
 *
 * Covers:
 *   A  LanguageRouteRegistrar::normalizeRequestPath() maps the translated slug
 *      that follows /blog/<prefix>/, folder and query kept.
 *   B  End to end through WP::parse_request() + WP_Query: the translated URL
 *      resolves to the page instead of a 404.
 *   C  RequestContext: /blog/wp-json/… is REST and /blog/wp-admin/… is admin,
 *      so shouldTranslate() keeps the translation pipeline off them.
 *
 * Run: ./wp eval-file tests/db/subfolder_incoming_it.php
 */

require __DIR__ . '/_harness.php';

use LingoWP\Database\TranslationMemorySchema;
use LingoWP\Language\Application\ResolveRequestLanguage;
use LingoWP\Language\Infrastructure\LanguageMetadataStore;
use LingoWP\Language\Infrastructure\OptionLanguageRegistry;
use LingoWP\LocalizationRouting\LanguageRouteRegistrar;
use LingoWP\LocalizationRouting\SlugMap;
use LingoWP\Resolution\RequestContext;
use LingoWP\Shared\Text\TranslationKey;

test_guard();
test_begin();

global $wpdb, $wp_rewrite;

// ── Setup: throwaway language + a page whose title translates to a new slug ──

$store    = new LanguageMetadataStore();
$registry = new OptionLanguageRegistry();

$store->save(['code' => 'zz_ZZ', 'slug' => 'zz']);
$registry->enableLanguage('zz_ZZ');
update_option(SlugMap::OPTION_ENABLED, true);

$now     = current_time('mysql');
$aboutId = test_insert($wpdb->posts, [
    'post_author'       => 1,
    'post_date'         => $now,
    'post_date_gmt'     => $now,
    'post_content'      => '',
    'post_title'        => 'Lingowp Test About',
    'post_excerpt'      => '',
    'post_status'       => 'publish',
    'comment_status'    => 'closed',
    'ping_status'       => 'closed',
    'post_name'         => 'lingowp-test-about',
    'post_modified'     => $now,
    'post_modified_gmt' => $now,
    'post_parent'       => 0,
    'guid'              => '',
    'menu_order'        => 0,
    'post_type'         => 'page',
    'comment_count'     => 0,
]);

$parentTable = TranslationMemorySchema::parentTables()['post'];
$childTable  = $parentTable . '_translation';
$hashHex     = bin2hex(TranslationKey::currentHash('Lingowp Test About'));

$wpdb->query($wpdb->prepare(
    "INSERT INTO {$parentTable} (post_id, field, original_hash, original_text, status, created_at, updated_at)
     VALUES (%d, 'post_title', UNHEX(%s), %s, 0, %s, %s)",
    $aboutId,
    $hashHex,
    'Lingowp Test About',
    $now,
    $now
));
$wpdb->query($wpdb->prepare(
    "INSERT INTO {$childTable} (original_hash, lang_code, translated_text, status, created_at, updated_at)
     VALUES (UNHEX(%s), 'zz_ZZ', %s, 1, %s, %s)",
    $hashHex,
    'Lingowp Zed Apropos',
    $now,
    $now
));

SlugMap::bumpVersion();

// Prefixed rewrite rules for zz must exist for B (same as slug_routing_bugs_it.php).
update_option('rewrite_rules', '');
$wp_rewrite->wp_rewrite_rules();

$_SERVER['REQUEST_METHOD'] = 'GET';

$registrar = new LanguageRouteRegistrar(new ResolveRequestLanguage($registry), new SlugMap($wpdb, $registry));

/** What normalizeRequestPath() leaves in REQUEST_URI, restored afterwards. */
function incoming_normalized(LanguageRouteRegistrar $registrar, string $uri): string
{
    $_SERVER['REQUEST_URI'] = $uri;
    $registrar->normalizeRequestPath(true);
    $seen = (string) $_SERVER['REQUEST_URI'];
    $registrar->restoreRequestPath();

    return $seen;
}

/** Drive the real request pipeline: rule matching + query. Returns the post id found (0 = 404). */
function incoming_resolve(string $uri): int
{
    $_SERVER['REQUEST_URI'] = $uri;

    // Clone the global $wp (see slug_routing_bugs_it.php): a `new WP()` drops
    // every query var registered on the global instance.
    $wp = clone $GLOBALS['wp'];
    $wp->query_vars    = [];
    $wp->matched_rule  = '';
    $wp->matched_query = '';
    $wp->parse_request();

    $query = new WP_Query();
    // nosemgrep: wpdb-query-without-prepare -- WP_Query::query() takes query VARS, never SQL.
    $query->query($wp->query_vars);

    return (int) ($query->posts[0]->ID ?? 0);
}

/** A fresh context reading the given request path. */
function incoming_context(string $uri): RequestContext
{
    $_SERVER['REQUEST_URI'] = $uri;

    return new RequestContext();
}

test_ok('fixture: the page exists', $aboutId > 0, (string) $aboutId);

// ── Root-install controls (before the subfolder filter is added) ─────────────

test_section('Root install (controls — must pass before and after the fix)');

$seen = incoming_normalized($registrar, '/zz/lingowp-zed-apropos/');
test_ok('A: /zz/<translated>/ is mapped to /zz/lingowp-test-about/', $seen === '/zz/lingowp-test-about/', $seen);

$found = incoming_resolve('/zz/lingowp-zed-apropos/');
test_ok('B: the translated URL resolves to the page', $found === $aboutId, "resolved=$found expected=$aboutId");

test_ok('C: /wp-json/wp/v2/posts is REST', incoming_context('/wp-json/wp/v2/posts')->isRest());
test_ok('C: /wp-admin/edit.php is an admin path', incoming_context('/wp-admin/edit.php')->isAdminPath());
test_ok('C: a normal page is neither', !incoming_context('/lingowp-test-about/')->isRest() && !incoming_context('/lingowp-test-about/')->isAdminPath());

// ── Subfolder install ────────────────────────────────────────────────────────

test_section('Subfolder install (home_url() ends in /blog)');

// Faithful to a real /blog install: home_url() is the site address + /blog +
// the given path, ALWAYS (an "add only if missing" filter hid a doubling bug).
$subfolder = static function (string $url, $path = ''): string {
    $tail = (is_string($path) && $path !== '') ? '/' . ltrim($path, '/') : '';

    return substr($url, 0, strlen($url) - strlen($tail)) . '/blog' . $tail;
};
add_filter('home_url', $subfolder, 10, 2);

test_ok('home_url() really is in /blog (setup)', str_ends_with(rtrim(home_url('/'), '/'), '/blog'), home_url('/'));

// A — normalization
$seen = incoming_normalized($registrar, '/blog/zz/lingowp-zed-apropos/');
test_ok('A: /blog/zz/<translated>/ is mapped to /blog/zz/lingowp-test-about/', $seen === '/blog/zz/lingowp-test-about/', $seen);

$seen = incoming_normalized($registrar, '/blog/zz/lingowp-zed-apropos/?x=1');
test_ok('A: the query string is kept -> /blog/zz/lingowp-test-about/?x=1', $seen === '/blog/zz/lingowp-test-about/?x=1', $seen);

$seen = incoming_normalized($registrar, '/blog/zz/lingowp-test-about/');
test_ok('A: an untranslated slug behind the folder is left alone', $seen === '/blog/zz/lingowp-test-about/', $seen);

incoming_normalized($registrar, '/blog/zz/lingowp-zed-apropos/');
test_ok('A: the real request URI is restored afterwards', ($_SERVER['REQUEST_URI'] ?? '') === '/blog/zz/lingowp-zed-apropos/', (string) ($_SERVER['REQUEST_URI'] ?? '(missing)'));

// Guard: PATH_INFO is relative to index.php (index.php/zz/…), so it never
// carries the site folder — it must keep being mapped without one, even
// though REQUEST_URI on the same request does carry /blog.
$_SERVER['REQUEST_URI'] = '/blog/zz/lingowp-zed-apropos/';
$_SERVER['PATH_INFO']   = '/zz/lingowp-zed-apropos/';
$registrar->normalizeRequestPath(true);
$seenPathInfo = (string) $_SERVER['PATH_INFO'];
$registrar->restoreRequestPath();
unset($_SERVER['PATH_INFO']);
test_ok('A: PATH_INFO (no folder) is mapped to /zz/lingowp-test-about/', $seenPathInfo === '/zz/lingowp-test-about/', $seenPathInfo);

// B — end to end (WP::parse_request() strips /blog from home_url() itself)
$found = incoming_resolve('/blog/zz/lingowp-zed-apropos/');
test_ok('B: /blog/zz/<translated>/ resolves to the page (was a 404 on the live site)', $found === $aboutId, "resolved=$found expected=$aboutId");

$found = incoming_resolve('/blog/zz/lingowp-test-about/');
test_ok('B: the untranslated /blog/zz/lingowp-test-about/ still resolves', $found === $aboutId, "resolved=$found expected=$aboutId");

// C — request context behind the folder
$rest = incoming_context('/blog/wp-json/wp/v2/posts');
test_ok('C: /blog/wp-json/wp/v2/posts is REST', $rest->isRest());
test_ok('C: ... so the translation pipeline stays off it', !$rest->shouldTranslate());

$admin = incoming_context('/blog/wp-admin/edit.php');
test_ok('C: /blog/wp-admin/edit.php is an admin path', $admin->isAdminPath());
test_ok('C: ... so the translation pipeline stays off it', !$admin->shouldTranslate());

$page = incoming_context('/blog/lingowp-test-about/');
test_ok('C: a normal page behind the folder is neither REST nor admin', !$page->isRest() && !$page->isAdminPath());

remove_filter('home_url', $subfolder, 10);
update_option('rewrite_rules', '');

test_done();
