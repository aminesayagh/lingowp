<?php

/**
 * Automatic hreflang tags (goals-seo-overview.md §4.5) end to end, against real
 * production classes and the live options table — no mocks for
 * ResolveRequestLanguage/LocalizedUrlBuilder, since their exact prefixing rules
 * are the thing being verified.
 *
 * Covers:
 *   1. renderHreflangTags() — reciprocal + complete set, x-default, correct
 *      prefixed/unprefixed paths.
 *   2. The "prefix default language" admin option changing the default
 *      language's own alternate.
 *   3. Gating: single enabled language, is_404(), non-front-end paths.
 *   4. filterCanonical() — non-string passthrough, default-language no-op,
 *      translated-language re-prefixing.
 *
 * Run: wp eval-file tests/db/hreflang_it.php
 */

require __DIR__ . '/_harness.php';

use LingoWP\Language\Application\ResolveRequestLanguage;
use LingoWP\Language\Domain\LanguageRegistry;
use LingoWP\Language\Infrastructure\LanguageMetadataStore;
use LingoWP\Language\Infrastructure\OptionLanguageRegistry;
use LingoWP\Resolution\Seo\HreflangPresenter;
use LingoWP\LocalizationRouting\LocalizedUrlBuilder;
use LingoWP\Resolution\RequestContext;

test_guard();
test_begin();

// ── Setup: one throwaway enabled target language ─────────────────────────────
// zz_ZZ is not a real WordPress locale, so it cannot collide with anything
// already configured on this dev site (same convention as fallback_language_it.php).

$store    = new LanguageMetadataStore();
$registry = new OptionLanguageRegistry();

$store->save(['code' => 'zz_ZZ', 'slug' => 'zz']);
$registry->enableLanguage('zz_ZZ');

$default = $registry->getDefaultLanguage();
test_ok('default language is set up', $default !== '', "default=$default");
test_ok('zz_ZZ is now enabled', $registry->isEnabledLanguage('zz_ZZ'));

$resolver   = new ResolveRequestLanguage($registry);
$urlBuilder = new LocalizedUrlBuilder($resolver);
$context    = new RequestContext();
$presenter  = new HreflangPresenter($resolver, $urlBuilder, $context);

/** @return array<string,string> hreflang => href, parsed out of the rendered tags */
function test_parse_hreflang_tags(string $html): array
{
    preg_match_all('/<link rel="alternate" hreflang="([^"]+)" href="([^"]+)" \/>/', $html, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $tag) {
        $out[$tag[1]] = $tag[2];
    }
    return $out;
}

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/lingowp-test-hreflang-page/';

// ── 1. Reciprocal + complete set ──────────────────────────────────────────────

test_section('renderHreflangTags: reciprocal + complete set');

ob_start();
$presenter->renderHreflangTags();
$tags = test_parse_hreflang_tags((string) ob_get_clean());

$enabled = $resolver->getEnabledLanguages();

test_ok(
    'one alternate per enabled language, plus x-default',
    count($tags) === count($enabled) + 1,
    'enabled=' . implode(',', $enabled) . ' tags=' . implode(',', array_keys($tags))
);

foreach ($enabled as $lang) {
    $hreflang = str_replace('_', '-', $lang);
    test_ok("alternate present for enabled language $lang", isset($tags[$hreflang]), implode(',', array_keys($tags)));
}

$defaultHreflang = str_replace('_', '-', $default);
test_ok(
    "default language ($default) is unprefixed",
    isset($tags[$defaultHreflang]) && str_ends_with($tags[$defaultHreflang], '/lingowp-test-hreflang-page/') && !str_contains($tags[$defaultHreflang], '/zz/'),
    $tags[$defaultHreflang] ?? '(missing)'
);

test_ok(
    'zz_ZZ alternate carries the /zz/ prefix',
    isset($tags['zz-ZZ']) && str_ends_with($tags['zz-ZZ'], '/zz/lingowp-test-hreflang-page/'),
    $tags['zz-ZZ'] ?? '(missing)'
);

test_ok(
    'x-default points at the default-language URL',
    isset($tags['x-default'], $tags[$defaultHreflang]) && $tags['x-default'] === $tags[$defaultHreflang],
    ($tags['x-default'] ?? '(missing)') . ' vs ' . ($tags[$defaultHreflang] ?? '(missing)')
);

// ── 1b. Trailing-slash shape: every target must be the canonical URL ────────
//
// Regression guard. stripPrefix() used to drop the trailing slash when it
// removed a language segment, and addPrefix() dropped it again when building a
// language home, so on a trailing-slash site EVERY hreflang target on EVERY
// translated page was a URL WordPress answers with a 301. Search engines treat
// an hreflang cluster whose members disagree on canonical form as unreliable,
// and crawlers report each one as an error.

test_section('renderHreflangTags: canonical trailing-slash shape');

$slashShaped = static fn(string $url): bool
    => str_ends_with((string) wp_parse_url($url, PHP_URL_PATH), '/');

foreach ($tags as $hl => $href) {
    test_ok("alternate $hl keeps the request's trailing slash", $slashShaped($href), $href);
}

// The language HOME is the one path with no shape of its own to carry (its
// leading and trailing slash are the same character), so it is built from the
// site's permalink structure instead.
$_SERVER['REQUEST_URI'] = '/zz/';
ob_start();
$presenter->renderHreflangTags();
$homeTags = test_parse_hreflang_tags((string) ob_get_clean());

test_ok(
    'language-home alternate is /zz/, not /zz (which core 301s)',
    isset($homeTags['zz-ZZ']) && str_ends_with((string) wp_parse_url($homeTags['zz-ZZ'], PHP_URL_PATH), '/zz/'),
    $homeTags['zz-ZZ'] ?? '(missing)'
);

test_ok(
    'source-language home alternate keeps its slash too',
    isset($homeTags[$defaultHreflang]) && wp_parse_url($homeTags[$defaultHreflang], PHP_URL_PATH) === '/',
    $homeTags[$defaultHreflang] ?? '(missing)'
);

$_SERVER['REQUEST_URI'] = '/lingowp-test-hreflang-page/';

// ── 2. "Prefix default language" option changes the default's own alternate ──

test_section('renderHreflangTags: lingowp_prefix_default_language option');

update_option('lingowp_prefix_default_language', true);

ob_start();
$presenter->renderHreflangTags();
$prefixedTags = test_parse_hreflang_tags((string) ob_get_clean());

test_ok(
    'default language alternate is no longer the bare path once prefixed',
    isset($prefixedTags[$defaultHreflang]) && $prefixedTags[$defaultHreflang] !== ($tags[$defaultHreflang] ?? null),
    $prefixedTags[$defaultHreflang] ?? '(missing)'
);

update_option('lingowp_prefix_default_language', false);

// ── 3. Gating ──────────────────────────────────────────────────────────────

test_section('renderHreflangTags: gating');

$singleLangRegistry = new class ($default) implements LanguageRegistry {
    public function __construct(private string $default) {}
    public function getDefaultLanguage(): string { return $this->default; }
    public function getDefaultLanguageDetails(): array { return []; }
    public function getTargetLanguages(): array { return []; }
    public function getTargetLanguageDetails(): array { return []; }
    public function getEnabledLanguages(): array { return [$this->default]; }
    public function isEnabledLanguage(string $code): bool { return $code === $this->default; }
    public function slugForLanguage(string $code): string { return $code; }
    public function languageForSlug(string $slug): ?string { return null; }
    public function existingLanguages(): array { return []; }
    public function enableLanguage(string $code): void {}
    public function disableLanguage(string $code): void {}
    public function fallbackChain(string $code): array { return []; }
    public function resolutionChain(string $code): array { return [$code]; }
};
$singleResolver  = new ResolveRequestLanguage($singleLangRegistry);
$singlePresenter = new HreflangPresenter($singleResolver, new LocalizedUrlBuilder($singleResolver), $context);

ob_start();
$singlePresenter->renderHreflangTags();
test_ok('nothing rendered with fewer than 2 enabled languages', ob_get_clean() === '');

if (!($GLOBALS['wp_query'] ?? null) instanceof WP_Query) {
    $GLOBALS['wp_query'] = new WP_Query();
}
$GLOBALS['wp_query']->is_404 = true;
ob_start();
$presenter->renderHreflangTags();
test_ok('nothing rendered on a 404', ob_get_clean() === '');
$GLOBALS['wp_query']->is_404 = false;

$_SERVER['REQUEST_URI'] = '/wp-admin/edit.php';
ob_start();
$presenter->renderHreflangTags();
test_ok('nothing rendered on a wp-admin path', ob_get_clean() === '');
$_SERVER['REQUEST_URI'] = '/lingowp-test-hreflang-page/';

// ── 4. filterCanonical ────────────────────────────────────────────────────

test_section('filterCanonical');

test_ok('non-string canonical passes through unchanged', $presenter->filterCanonical(false) === false);
test_ok('empty canonical passes through unchanged', $presenter->filterCanonical('') === '');

$canonical = home_url('/lingowp-test-hreflang-page/');

test_ok(
    'default-language request leaves canonical untouched',
    $presenter->filterCanonical($canonical) === $canonical,
    (string) $presenter->filterCanonical($canonical)
);

set_query_var('lingowp_lang', 'zz_ZZ');
$translatedResolver  = new ResolveRequestLanguage($registry);
$translatedPresenter = new HreflangPresenter($translatedResolver, new LocalizedUrlBuilder($translatedResolver), $context);

$rewritten = $translatedPresenter->filterCanonical($canonical);
test_ok(
    'translated-language request re-prefixes the canonical to /zz/',
    is_string($rewritten) && str_ends_with($rewritten, '/zz/lingowp-test-hreflang-page/'),
    (string) $rewritten
);

set_query_var('lingowp_lang', '');

test_done();
