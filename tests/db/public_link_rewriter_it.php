<?php

/**
 * PublicLinkRewriter: links inside a translated page must keep the visitor in
 * that language — same-site links get the language prefix, everything else
 * (other sites, wp-admin, wp-content, login, anchors) is left alone.
 *
 * Covers:
 *   1. Root install (baseline — passes before the subfolder fix too): relative
 *      and absolute same-site links prefixed, query kept, language home,
 *      already-prefixed links not stacked, system paths / other sites /
 *      anchors untouched, source language untouched.
 *   2. Subfolder install (/blog): what a real /blog install served on
 *      2026-10-04 — menu and content links became /blog/blog/fr/about/. The
 *      prefix belongs right after the folder, the folder appears once,
 *      /blog/wp-admin/ and /blog/wp-content/ stay untouched, and a link
 *      outside the folder is not a WordPress URL at all.
 *
 * Run: ./wp eval-file tests/db/public_link_rewriter_it.php
 */

require __DIR__ . '/_harness.php';

use LingoWP\Language\Application\ResolveRequestLanguage;
use LingoWP\Language\Infrastructure\LanguageMetadataStore;
use LingoWP\Language\Infrastructure\OptionLanguageRegistry;
use LingoWP\LocalizationRouting\LocalizedUrlBuilder;
use LingoWP\Resolution\PublicLinkRewriter;

test_guard();
test_begin();

// ── Setup: throwaway enabled target language (not a real locale) ────────────

$store    = new LanguageMetadataStore();
$registry = new OptionLanguageRegistry();

$store->save(['code' => 'zz_ZZ', 'slug' => 'zz']);
$registry->enableLanguage('zz_ZZ');

$default = $registry->getDefaultLanguage();
test_ok('default language is set up', $default !== '', "default=$default");

$_SERVER['REQUEST_METHOD'] = 'GET';
set_query_var('lingowp_lang', 'zz_ZZ');

/** Fresh rewriter so nothing memoized leaks between the root and subfolder halves. */
function link_rewriter(OptionLanguageRegistry $registry): PublicLinkRewriter
{
    $resolver = new ResolveRequestLanguage($registry);

    return new PublicLinkRewriter(new LocalizedUrlBuilder($resolver));
}

/** The href of the single <a> in the rewritten HTML. */
function link_href(PublicLinkRewriter $rewriter, string $href, string $lang = 'zz_ZZ'): string
{
    $html = $rewriter->rewrite('<p><a href="' . esc_attr($href) . '">link</a></p>', $lang);
    preg_match('#<a href="([^"]*)"#', $html, $m);

    return html_entity_decode($m[1] ?? '(no link)', ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** Path + query of a URL, for comparing absolute links. */
function link_pq(string $url): string
{
    $path  = (string) wp_parse_url($url, PHP_URL_PATH);
    $query = (string) wp_parse_url($url, PHP_URL_QUERY);

    return $path . ($query !== '' ? '?' . $query : '');
}

// ── 1. Root install (baseline) ──────────────────────────────────────────────

test_section('Root install (baseline)');

$rw = link_rewriter($registry);

test_ok('relative /about/ -> /zz/about/', link_href($rw, '/lingowp-test-about/') === '/zz/lingowp-test-about/', link_href($rw, '/lingowp-test-about/'));
test_ok('absolute same-site link -> /zz/about/', link_pq(link_href($rw, home_url('/lingowp-test-about/'))) === '/zz/lingowp-test-about/', link_href($rw, home_url('/lingowp-test-about/')));
test_ok('query string kept -> /zz/shop/?orderby=price', link_href($rw, '/shop/?orderby=price') === '/zz/shop/?orderby=price', link_href($rw, '/shop/?orderby=price'));
test_ok('home -> /zz/', link_href($rw, '/') === '/zz/', link_href($rw, '/'));
test_ok('already-prefixed /zz/about/ is not stacked', link_href($rw, '/zz/lingowp-test-about/') === '/zz/lingowp-test-about/', link_href($rw, '/zz/lingowp-test-about/'));
test_ok('/wp-admin/ untouched', link_href($rw, '/wp-admin/') === '/wp-admin/', link_href($rw, '/wp-admin/'));
test_ok('/wp-content/ untouched', link_href($rw, '/wp-content/plugins/x/') === '/wp-content/plugins/x/', link_href($rw, '/wp-content/plugins/x/'));
test_ok('another site untouched', link_href($rw, 'https://example.org/about/') === 'https://example.org/about/', link_href($rw, 'https://example.org/about/'));
test_ok('in-page anchor untouched', link_href($rw, '#top') === '#top', link_href($rw, '#top'));
test_ok("source language ($default) leaves links alone", link_href($rw, '/lingowp-test-about/', $default) === '/lingowp-test-about/', link_href($rw, '/lingowp-test-about/', $default));

// ── 2. Subfolder install (home_url() ends in /blog) ─────────────────────────

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

test_ok('home_url() really is in /blog (setup)', str_ends_with(rtrim(home_url('/'), '/'), '/blog'), home_url('/'));

$rw = link_rewriter($registry);

$abs = link_href($rw, home_url('/lingowp-test-about/'));
test_ok('absolute link (as WordPress builds it) -> /blog/zz/about/', link_pq($abs) === '/blog/zz/lingowp-test-about/', $abs);
test_ok('absolute link stays on this host', str_starts_with($abs, 'http') && wp_parse_url($abs, PHP_URL_HOST) === wp_parse_url(home_url('/'), PHP_URL_HOST), $abs);
test_ok('relative /blog/about/ -> /blog/zz/about/', link_href($rw, '/blog/lingowp-test-about/') === '/blog/zz/lingowp-test-about/', link_href($rw, '/blog/lingowp-test-about/'));
test_ok('site home /blog/ -> /blog/zz/', link_href($rw, '/blog/') === '/blog/zz/', link_href($rw, '/blog/'));
test_ok('query kept -> /blog/zz/shop/?orderby=price', link_href($rw, '/blog/shop/?orderby=price') === '/blog/zz/shop/?orderby=price', link_href($rw, '/blog/shop/?orderby=price'));
test_ok('already-prefixed /blog/zz/about/ is not stacked', link_href($rw, '/blog/zz/lingowp-test-about/') === '/blog/zz/lingowp-test-about/', link_href($rw, '/blog/zz/lingowp-test-about/'));
test_ok('/blog/wp-admin/ untouched', link_href($rw, '/blog/wp-admin/') === '/blog/wp-admin/', link_href($rw, '/blog/wp-admin/'));
test_ok('/blog/wp-content/ untouched', link_href($rw, '/blog/wp-content/plugins/x/') === '/blog/wp-content/plugins/x/', link_href($rw, '/blog/wp-content/plugins/x/'));
test_ok('link outside the folder (/other/) is not a WordPress URL: untouched', link_href($rw, '/other/') === '/other/', link_href($rw, '/other/'));

remove_filter('home_url', $subfolder, 10);
set_query_var('lingowp_lang', '');

test_done();
