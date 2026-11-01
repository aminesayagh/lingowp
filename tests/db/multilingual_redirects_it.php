<?php

/**
 * MultilingualRedirects: WordPress's own redirects (wp_old_slug_redirect(),
 * redirect_canonical(), and anything else routed through wp_redirect()) must
 * keep the visitor's language prefix instead of silently dropping them back
 * into the default language — and a case-mismatched but otherwise valid
 * language prefix (e.g. /FR/about/, which matches no rewrite rule at all)
 * should recover to the correctly-cased URL instead of 404ing.
 *
 * Tests the registered filter callback and the pure decision method directly
 * (same approach hreflang_it.php uses for filterCanonical()) rather than
 * simulating a full request/main-query cycle to actually trigger
 * wp_old_slug_redirect() or redirectMiscasedPrefix()'s exit() path — that
 * would need a real HTTP round trip this harness doesn't have, and testing
 * exactly what's registered/exactly what decides the redirect target proves
 * the real behavior.
 *
 * Covers:
 *   1. register() wires the filter.
 *   2. Non-string input, default-language request: untouched.
 *   3. Translated-language request: relative + absolute paths prefixed,
 *      query string/fragment preserved, idempotent against an existing
 *      (different) prefix.
 *   4. Off-site and admin/login destinations: never touched.
 *   5. Non-front-end current request (e.g. admin): untouched even when the
 *      active language is translated.
 *   6. correctedPrefixSlug() / miscasedPrefixTarget(): case-mismatch recovered,
 *      already-correct and unknown prefixes left alone.
 *   8. Subfolder install (/blog): redirect targets keep the folder once with
 *      the prefix right after it; /blog/wp-login.php untouched; miscased
 *      prefix found behind the folder.
 *
 * Run: wp eval-file tests/db/multilingual_redirects_it.php
 */

require __DIR__ . '/_harness.php';

use LingoWP\Language\Application\ResolveRequestLanguage;
use LingoWP\Language\Infrastructure\LanguageMetadataStore;
use LingoWP\Language\Infrastructure\OptionLanguageRegistry;
use LingoWP\LocalizationRouting\LocalizedUrlBuilder;
use LingoWP\LocalizationRouting\MultilingualRedirects;
use LingoWP\Resolution\RequestContext;

test_guard();
test_begin();

// ── Setup: one throwaway enabled target language ─────────────────────────────
// zz_ZZ is not a real WordPress locale, so it cannot collide with anything
// already configured on this dev site (same convention as hreflang_it.php).

$store    = new LanguageMetadataStore();
$registry = new OptionLanguageRegistry();

$store->save(['code' => 'zz_ZZ', 'slug' => 'zz']);
$registry->enableLanguage('zz_ZZ');
// A second throwaway language, needed to test "idempotent against an existing
// DIFFERENT prefix" — detectPrefix() only recognizes a segment as a prefix
// when it matches an ENABLED language's slug, so an arbitrary/unenabled code
// like 'es' would be treated as an ordinary path segment, not a prefix to
// replace. yy_YY gives us a real enabled prefix other than zz_ZZ's own.
$store->save(['code' => 'yy_YY', 'slug' => 'yy']);
$registry->enableLanguage('yy_YY');

$default = $registry->getDefaultLanguage();
test_ok('default language is set up', $default !== '', "default=$default");
test_ok('zz_ZZ is now enabled', $registry->isEnabledLanguage('zz_ZZ'));
test_ok('yy_YY is now enabled', $registry->isEnabledLanguage('yy_YY'));

$context = new RequestContext();

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/lingowp-test-redirect-page/';

/** Fresh instance so ResolveRequestLanguage's internal memoization never leaks between scenarios. */
function test_redirects(OptionLanguageRegistry $registry, RequestContext $context): MultilingualRedirects
{
    $resolver = new ResolveRequestLanguage($registry);
    return new MultilingualRedirects($resolver, new LocalizedUrlBuilder($resolver), $context);
}

// ── 1. register() wires the filter ────────────────────────────────────────────

test_section('register(): wires the wp_redirect filter');

$redirects = test_redirects($registry, $context);
$redirects->register();
test_ok(
    'preserveLanguagePrefix is registered on wp_redirect',
    has_filter('wp_redirect', [$redirects, 'preserveLanguagePrefix']) !== false
);
test_ok(
    'preserveLanguagePrefix is registered on redirect_canonical too',
    has_filter('redirect_canonical', [$redirects, 'preserveLanguagePrefix']) !== false
);

// ── 2. Non-string input / default-language request: untouched ────────────────

test_section('gating: non-string input, default-language request');

set_query_var('lingowp_lang', '');

test_ok('false passes through unchanged', test_redirects($registry, $context)->preserveLanguagePrefix(false, 301) === false);
test_ok('empty string passes through unchanged', test_redirects($registry, $context)->preserveLanguagePrefix('', 301) === '');

test_ok(
    'default-language request leaves a relative path untouched',
    test_redirects($registry, $context)->preserveLanguagePrefix('/lingowp-test-old-slug/', 301) === '/lingowp-test-old-slug/'
);

// ── 3. Translated-language request: prefixing ────────────────────────────────

test_section('translated-language request: prefixing');

set_query_var('lingowp_lang', 'zz_ZZ');

test_ok(
    'relative path gets the /zz/ prefix',
    test_redirects($registry, $context)->preserveLanguagePrefix('/lingowp-test-old-slug/', 301) === '/zz/lingowp-test-old-slug/'
);

$absoluteIn  = home_url('/lingowp-test-old-slug/');
$absoluteOut = home_url('/zz/lingowp-test-old-slug/');
test_ok(
    'absolute same-site URL stays absolute, gets the /zz/ prefix',
    test_redirects($registry, $context)->preserveLanguagePrefix($absoluteIn, 301) === $absoluteOut,
    (string) test_redirects($registry, $context)->preserveLanguagePrefix($absoluteIn, 301)
);

test_ok(
    'query string and fragment survive the rewrite',
    test_redirects($registry, $context)->preserveLanguagePrefix('/lingowp-test-old-slug/?foo=bar#baz', 301)
        === '/zz/lingowp-test-old-slug/?foo=bar#baz'
);

// Regression: a redirect target that's ALREADY correctly prefixed at the
// language-HOME root ('/zz/', nothing after the prefix) must pass through
// completely untouched — including its exact trailing-slash formatting. This
// exact case caused a live infinite redirect loop: core's own trailing-slash
// canonicalization sends '/fr-ca' -> '/fr-ca/', and rewriting an
// already-correct target via strip-then-rebuild silently dropped the
// trailing slash back off ('/fr-ca/' -> '/fr-ca'), so the two redirects
// fought forever. Bailing as soon as the existing prefix already matches the
// active language — before ever calling addPrefix()/stripPrefix() — is what
// prevents the round trip that loses the slash.
test_ok(
    'an already-correctly-prefixed language-home path (with trailing slash) is untouched',
    test_redirects($registry, $context)->preserveLanguagePrefix('/zz/', 301) === '/zz/'
);
test_ok(
    'an already-correctly-prefixed language-home path (no trailing slash) is untouched either way',
    test_redirects($registry, $context)->preserveLanguagePrefix('/zz', 301) === '/zz'
);

// LocalizedUrlBuilder::stripPrefix() used to drop the trailing slash when it
// stripped an EXISTING prefix (trim() takes both ends, the rebuild only re-added
// a leading slash). It no longer does: the shape is preserved on both sides, and
// the language home follows the site's permalink structure. That had leaked into
// every hreflang target on a translated page as a 301, and is the same root cause
// the guard clause in MultilingualRedirects::preserveLanguagePrefix() works
// around — that guard is now belt-and-braces rather than load-bearing.
test_ok(
    'idempotent against an existing different (but real, enabled) prefix — no double-prefixing',
    test_redirects($registry, $context)->preserveLanguagePrefix('/yy/lingowp-test-old-slug/', 301) === '/zz/lingowp-test-old-slug/',
    test_redirects($registry, $context)->preserveLanguagePrefix('/yy/lingowp-test-old-slug/', 301)
);

test_ok(
    'the swapped prefix keeps the shape it arrived with (no extra canonical hop)',
    test_redirects($registry, $context)->preserveLanguagePrefix('/yy/lingowp-test-old-slug', 301) === '/zz/lingowp-test-old-slug',
    test_redirects($registry, $context)->preserveLanguagePrefix('/yy/lingowp-test-old-slug', 301)
);

// ── 4. Off-site and admin/login destinations: never touched ──────────────────

test_section('off-site and admin/login destinations are never touched');

$offSite = 'https://lingowp-test-external.example/callback?token=abc';
test_ok(
    'off-site absolute URL is untouched',
    test_redirects($registry, $context)->preserveLanguagePrefix($offSite, 302) === $offSite
);

$adminAbsolute = admin_url('post.php?post=1&action=edit');
test_ok(
    'admin_url() destination is untouched',
    test_redirects($registry, $context)->preserveLanguagePrefix($adminAbsolute, 302) === $adminAbsolute
);

test_ok(
    'relative wp-admin path is untouched',
    test_redirects($registry, $context)->preserveLanguagePrefix('/wp-admin/edit.php', 302) === '/wp-admin/edit.php'
);

$loginRedirect = '/wp-login.php?redirect_to=' . rawurlencode('/lingowp-test-old-slug/');
test_ok(
    'wp-login.php destination is untouched',
    test_redirects($registry, $context)->preserveLanguagePrefix($loginRedirect, 302) === $loginRedirect
);

// ── 5. Non-front-end current request: untouched even in a translated language ─

test_section('current request is non-front-end (e.g. admin): untouched regardless of active language');

$_SERVER['REQUEST_URI'] = '/wp-admin/edit.php';
test_ok(
    'admin current-request context leaves the redirect target untouched',
    test_redirects($registry, $context)->preserveLanguagePrefix('/lingowp-test-old-slug/', 301) === '/lingowp-test-old-slug/'
);
$_SERVER['REQUEST_URI'] = '/lingowp-test-redirect-page/';

set_query_var('lingowp_lang', '');

// ── 6. correctedPrefixSlug(): case-mismatch recovery decision ────────────────
// Pure — no request/redirect side effects — tested directly rather than via
// redirectMiscasedPrefix(), which calls exit() on a match.

test_section('correctedPrefixSlug(): case-mismatch recovery decision');

$plain = test_redirects($registry, $context);

test_ok(
    'a case-mismatched but real prefix resolves to its correct casing',
    $plain->correctedPrefixSlug('ZZ') === 'zz',
    (string) $plain->correctedPrefixSlug('ZZ')
);

test_ok(
    'an already-correctly-cased prefix needs no correction (null — that 404 is real)',
    $plain->correctedPrefixSlug('zz') === null
);

test_ok(
    'a prefix that is not any enabled language at all needs no correction (null — normal 404 stands)',
    $plain->correctedPrefixSlug('xx') === null
);

// miscasedPrefixTarget(): the full redirect target, split out of
// redirectMiscasedPrefix() (which exits) so it is testable. Root install.
test_ok(
    'miscasedPrefixTarget: /ZZ/about/?a=1 -> /zz/about/?a=1 (slash shape + query kept)',
    $plain->miscasedPrefixTarget('/ZZ/lingowp-test-about/?a=1') === '/zz/lingowp-test-about/?a=1',
    var_export($plain->miscasedPrefixTarget('/ZZ/lingowp-test-about/?a=1'), true)
);
test_ok(
    'miscasedPrefixTarget: correctly-cased or non-language first segment -> null',
    $plain->miscasedPrefixTarget('/zz/lingowp-test-about/') === null && $plain->miscasedPrefixTarget('/lingowp-test-about/') === null
);

// ── 7. redirect_canonical(): the paged-singular loop ─────────────────────────
// The one case in this file that DOES drive core's own function, because the
// bug being guarded is core's behavior, not ours: redirect_canonical() is
// called with $do_redirect = false, so it returns its target instead of
// exiting, which makes the loop observable without an HTTP round trip.
//
// The bug: a paginated singular under a language prefix (/zz/<slug>/2/, i.e.
// any post with a Page Break block) reaches canonical.php's "Post paging"
// branch, where core rebuilds the target from get_permalink() — prefix-blind,
// since this plugin has no post_link filter. Core compares its unprefixed
// /<slug>/2/ against the requested /zz/<slug>/2/, finds them different, and
// redirects; the wp_redirect filter tested above then puts the prefix back,
// producing a 301 to the page we are already on. Forever.
//
// Archive paging never had this: that branch rebuilds from the requested path,
// prefix included (covered by language_url_it.php's '/zz/blog/page/3/').

test_section('redirect_canonical(): paged singular under a prefix must not loop');

global $wpdb, $wp_query;

$pagedId = test_insert($wpdb->posts, [
    'post_author'       => 1,
    'post_date'         => current_time('mysql'),
    'post_date_gmt'     => current_time('mysql'),
    'post_content'      => 'page one<!--nextpage-->page two',
    'post_title'        => 'Lingowp Test Paged',
    'post_excerpt'      => '',
    'post_status'       => 'publish',
    'comment_status'    => 'closed',
    'ping_status'       => 'closed',
    'post_name'         => 'lingowp-test-paged',
    'post_modified'     => current_time('mysql'),
    'post_modified_gmt' => current_time('mysql'),
    'post_parent'       => 0,
    'guid'              => '',
    'menu_order'        => 0,
    'post_type'         => 'post',
    'comment_count'     => 0,
]);

// Page 2 of that post, as the rewrite rules would have parsed it. Built from
// get_permalink() rather than hardcoded, so the case holds under any
// permalink structure.
$permalinkPath = (string) wp_parse_url(get_permalink($pagedId), PHP_URL_PATH);
$pagedPath     = trailingslashit($permalinkPath) . user_trailingslashit(2, 'single_paged');

$prefixer      = new LocalizedUrlBuilder(new ResolveRequestLanguage($registry));
$requestedUrl  = home_url($prefixer->addPrefix($pagedPath, 'zz_ZZ'));

$restoreQuery           = $wp_query;
$restoreUri             = $_SERVER['REQUEST_URI'] ?? '';
$_SERVER['REQUEST_URI'] = (string) wp_parse_url($requestedUrl, PHP_URL_PATH);

// Main-query state the "Post paging" branch keys off: a found singular with
// get_query_var('page') set.
$wp_query            = new WP_Query(['p' => $pagedId, 'page' => 2]);
$GLOBALS['wp_query'] = $wp_query;

// AFTER installing the query, not before: set_query_var() writes into the
// global $wp_query, so replacing that object would discard the language var
// that ResolveRequestLanguage::isTranslatedRequest() reads.
set_query_var('lingowp_lang', 'zz_ZZ');

test_ok(
    'fixture is a found, paginated singular',
    $wp_query->is_singular() && (int) get_query_var('page') === 2 && get_queried_object_id() === $pagedId,
    'singular=' . var_export($wp_query->is_singular(), true)
        . ' page=' . var_export(get_query_var('page'), true)
        . ' id=' . get_queried_object_id() . ' expected=' . $pagedId
);

// First, core ALONE: detach every redirect_canonical callback, the plugin's own
// included — Plugin::boot() registers one on every request, this WP-CLI process
// among them, so removing only a locally-built instance would leave the real
// one answering and the "unfiltered" baseline would silently measure the fix
// instead of the bug. This half is the regression guard: if core ever stops
// dropping the prefix here, the fix below is dead weight and should be
// revisited.
$savedHook = $GLOBALS['wp_filter']['redirect_canonical'] ?? null;
unset($GLOBALS['wp_filter']['redirect_canonical']);

$unfiltered = redirect_canonical($requestedUrl, false);

if ($savedHook !== null) {
    $GLOBALS['wp_filter']['redirect_canonical'] = $savedHook;
}

test_ok(
    'core alone wants to redirect away from the prefixed URL',
    is_string($unfiltered) && $unfiltered !== $requestedUrl,
    var_export($unfiltered, true)
);
test_ok(
    "core's target has lost the /zz/ prefix (this is the loop)",
    is_string($unfiltered) && !str_contains($unfiltered, '/zz/'),
    var_export($unfiltered, true)
);

// Now with the filter restored — the one register() actually wired at boot, not
// a test-local copy: the rebuilt target equals the requested URL, so core
// cancels the redirect itself ("Yes, again -- in case the filter aborted the
// request") and the page renders.

test_ok(
    'with the filter, no redirect is issued at all',
    redirect_canonical($requestedUrl, false) === null,
    var_export(redirect_canonical($requestedUrl, false), true)
);

// And the fix must not disable canonicalization wholesale: a genuinely
// non-canonical URL (page 2 without its trailing slash, on a trailing-slash
// permalink structure) still redirects — now with the prefix intact.
if (substr($pagedPath, -1) === '/') {
    $unslashedUrl = home_url(untrailingslashit($prefixer->addPrefix($pagedPath, 'zz_ZZ')));
    $_SERVER['REQUEST_URI'] = (string) wp_parse_url($unslashedUrl, PHP_URL_PATH);

    $fixedUp = redirect_canonical($unslashedUrl, false);

    test_ok(
        'a real canonicalization still happens',
        is_string($fixedUp) && $fixedUp !== $unslashedUrl,
        var_export($fixedUp, true)
    );
    test_ok(
        'and it keeps the /zz/ prefix',
        is_string($fixedUp) && str_contains($fixedUp, '/zz/'),
        var_export($fixedUp, true)
    );
}

$wp_query               = $restoreQuery;
$GLOBALS['wp_query']    = $restoreQuery;
$_SERVER['REQUEST_URI'] = $restoreUri;
set_query_var('lingowp_lang', '');

// ── 8. Subfolder install (home_url() ends in /blog) ─────────────────────────
//
// Reproduces what a real /blog install did (2026-10-04, subfolder test site):
// /blog/fr/?p=4 301'd to /blog/blog/fr/blog/fr/about/, and /blog/FR/about/
// landed on the English page. On a subfolder install every redirect target
// WordPress builds already carries /blog; the language prefix belongs right
// after it, the folder must appear once, and system paths behind the folder
// (/blog/wp-login.php) stay untouched like they do at the root.

test_section('Subfolder install (home_url() ends in /blog)');

// Faithful to a real /blog install: home_url() is the site address + /blog +
// the given path, ALWAYS — so a path that already carries /blog comes back
// doubled, exactly like the real subfolder site did. (An earlier version only
// added /blog when missing, which hid that doubling.)
$subfolder = static function (string $url, $path = ''): string {
    $tail = (is_string($path) && $path !== '') ? '/' . ltrim($path, '/') : '';

    return substr($url, 0, strlen($url) - strlen($tail)) . '/blog' . $tail;
};
add_filter('home_url', $subfolder, 10, 2);

$pq = static function ($url): string {
    $path  = (string) wp_parse_url((string) $url, PHP_URL_PATH);
    $query = (string) wp_parse_url((string) $url, PHP_URL_QUERY);
    return $path . ($query !== '' ? '?' . $query : '');
};

test_ok('home_url() really is in /blog (setup)', str_ends_with(rtrim(home_url('/'), '/'), '/blog'), home_url('/'));

set_query_var('lingowp_lang', 'zz_ZZ');
$_SERVER['REQUEST_URI'] = '/blog/zz/lingowp-test-redirect-page/';
$sub = test_redirects($registry, $context);

$target = $sub->preserveLanguagePrefix(home_url('/lingowp-test-old-slug/'), 301);
test_ok('absolute target (as core builds it) -> /blog/zz/lingowp-test-old-slug/', $pq($target) === '/blog/zz/lingowp-test-old-slug/', (string) $target);

$target = $sub->preserveLanguagePrefix('/blog/lingowp-test-old-slug/', 301);
test_ok('relative target -> /blog/zz/lingowp-test-old-slug/', $target === '/blog/zz/lingowp-test-old-slug/', (string) $target);

$target = $sub->preserveLanguagePrefix(home_url('/?p=4'), 301);
test_ok('shortlink-style target keeps its query -> /blog/zz/?p=4', $pq($target) === '/blog/zz/?p=4', (string) $target);

$already = home_url('/zz/lingowp-test-old-slug/');
$target  = $sub->preserveLanguagePrefix($already, 301);
test_ok('already-prefixed target is left exactly as built', $target === $already, (string) $target);

$login  = home_url('/wp-login.php?action=lostpassword');
$target = $sub->preserveLanguagePrefix($login, 302);
test_ok('/blog/wp-login.php is never prefixed', $target === $login, (string) $target);

$target = $sub->miscasedPrefixTarget('/blog/ZZ/lingowp-test-about/?a=1');
test_ok('miscasedPrefixTarget: /blog/ZZ/about/ -> /blog/zz/about/ (folder kept, prefix fixed)', $target === '/blog/zz/lingowp-test-about/?a=1', var_export($target, true));

remove_filter('home_url', $subfolder, 10);
$_SERVER['REQUEST_URI'] = $restoreUri;
set_query_var('lingowp_lang', '');

test_done();
