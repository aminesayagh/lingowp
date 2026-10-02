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

test_done();
