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
 *   6. correctedPrefixSlug(): case-mismatch recovered, already-correct and
 *      unknown prefixes left alone.
 *
 * Run: wp eval-file tests/db/multilingual_redirects_it.php
 */

require __DIR__ . '/_harness.php';

use TransLangs\Language\Application\ResolveRequestLanguage;
use TransLangs\Language\Infrastructure\LanguageMetadataStore;
use TransLangs\Language\Infrastructure\OptionLanguageRegistry;
use TransLangs\Localization\LocalizedUrlBuilder;
use TransLangs\Localization\MultilingualRedirects;
use TransLangs\Rendering\RequestContext;

tl_guard();
tl_begin();

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
tl_ok('default language is set up', $default !== '', "default=$default");
tl_ok('zz_ZZ is now enabled', $registry->isEnabledLanguage('zz_ZZ'));
tl_ok('yy_YY is now enabled', $registry->isEnabledLanguage('yy_YY'));

$context = new RequestContext();

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/tltest-redirect-page/';

/** Fresh instance so ResolveRequestLanguage's internal memoization never leaks between scenarios. */
function tl_redirects(OptionLanguageRegistry $registry, RequestContext $context): MultilingualRedirects
{
    $resolver = new ResolveRequestLanguage($registry);
    return new MultilingualRedirects($resolver, new LocalizedUrlBuilder($resolver), $context);
}

// ── 1. register() wires the filter ────────────────────────────────────────────

tl_section('register(): wires the wp_redirect filter');

$redirects = tl_redirects($registry, $context);
$redirects->register();
tl_ok(
    'preserveLanguagePrefix is registered on wp_redirect',
    has_filter('wp_redirect', [$redirects, 'preserveLanguagePrefix']) !== false
);

// ── 2. Non-string input / default-language request: untouched ────────────────

tl_section('gating: non-string input, default-language request');

set_query_var('translangs_lang', '');

tl_ok('false passes through unchanged', tl_redirects($registry, $context)->preserveLanguagePrefix(false, 301) === false);
tl_ok('empty string passes through unchanged', tl_redirects($registry, $context)->preserveLanguagePrefix('', 301) === '');

tl_ok(
    'default-language request leaves a relative path untouched',
    tl_redirects($registry, $context)->preserveLanguagePrefix('/tltest-old-slug/', 301) === '/tltest-old-slug/'
);

// ── 3. Translated-language request: prefixing ────────────────────────────────

tl_section('translated-language request: prefixing');

set_query_var('translangs_lang', 'zz_ZZ');

tl_ok(
    'relative path gets the /zz/ prefix',
    tl_redirects($registry, $context)->preserveLanguagePrefix('/tltest-old-slug/', 301) === '/zz/tltest-old-slug/'
);

$absoluteIn  = home_url('/tltest-old-slug/');
$absoluteOut = home_url('/zz/tltest-old-slug/');
tl_ok(
    'absolute same-site URL stays absolute, gets the /zz/ prefix',
    tl_redirects($registry, $context)->preserveLanguagePrefix($absoluteIn, 301) === $absoluteOut,
    (string) tl_redirects($registry, $context)->preserveLanguagePrefix($absoluteIn, 301)
);

tl_ok(
    'query string and fragment survive the rewrite',
    tl_redirects($registry, $context)->preserveLanguagePrefix('/tltest-old-slug/?foo=bar#baz', 301)
        === '/zz/tltest-old-slug/?foo=bar#baz'
);

// LocalizedUrlBuilder::stripPrefix() drops the trailing slash when it strips
// an EXISTING prefix (trim() takes both ends, the rebuild only re-adds a
// leading slash) — unlike the no-existing-prefix case above, which preserves
// it (ltrim() only touches the leading side). That's pre-existing behavior in
// shared infrastructure this fix reuses as-is, not something to change here;
// the real-world case (wp_old_slug_redirect()'s $link from get_permalink())
// is always the no-existing-prefix path anyway.
tl_ok(
    'idempotent against an existing different (but real, enabled) prefix — no double-prefixing',
    tl_redirects($registry, $context)->preserveLanguagePrefix('/yy/tltest-old-slug/', 301) === '/zz/tltest-old-slug'
);

// ── 4. Off-site and admin/login destinations: never touched ──────────────────

tl_section('off-site and admin/login destinations are never touched');

$offSite = 'https://tltest-external.example/callback?token=abc';
tl_ok(
    'off-site absolute URL is untouched',
    tl_redirects($registry, $context)->preserveLanguagePrefix($offSite, 302) === $offSite
);

$adminAbsolute = admin_url('post.php?post=1&action=edit');
tl_ok(
    'admin_url() destination is untouched',
    tl_redirects($registry, $context)->preserveLanguagePrefix($adminAbsolute, 302) === $adminAbsolute
);

tl_ok(
    'relative wp-admin path is untouched',
    tl_redirects($registry, $context)->preserveLanguagePrefix('/wp-admin/edit.php', 302) === '/wp-admin/edit.php'
);

$loginRedirect = '/wp-login.php?redirect_to=' . rawurlencode('/tltest-old-slug/');
tl_ok(
    'wp-login.php destination is untouched',
    tl_redirects($registry, $context)->preserveLanguagePrefix($loginRedirect, 302) === $loginRedirect
);

// ── 5. Non-front-end current request: untouched even in a translated language ─

tl_section('current request is non-front-end (e.g. admin): untouched regardless of active language');

$_SERVER['REQUEST_URI'] = '/wp-admin/edit.php';
tl_ok(
    'admin current-request context leaves the redirect target untouched',
    tl_redirects($registry, $context)->preserveLanguagePrefix('/tltest-old-slug/', 301) === '/tltest-old-slug/'
);
$_SERVER['REQUEST_URI'] = '/tltest-redirect-page/';

set_query_var('translangs_lang', '');

// ── 6. correctedPrefixSlug(): case-mismatch recovery decision ────────────────
// Pure — no request/redirect side effects — tested directly rather than via
// redirectMiscasedPrefix(), which calls exit() on a match.

tl_section('correctedPrefixSlug(): case-mismatch recovery decision');

$plain = tl_redirects($registry, $context);

tl_ok(
    'a case-mismatched but real prefix resolves to its correct casing',
    $plain->correctedPrefixSlug('ZZ') === 'zz',
    (string) $plain->correctedPrefixSlug('ZZ')
);

tl_ok(
    'an already-correctly-cased prefix needs no correction (null — that 404 is real)',
    $plain->correctedPrefixSlug('zz') === null
);

tl_ok(
    'a prefix that is not any enabled language at all needs no correction (null — normal 404 stands)',
    $plain->correctedPrefixSlug('xx') === null
);

tl_done();
