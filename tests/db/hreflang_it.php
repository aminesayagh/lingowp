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

use TransLangs\Language\Application\ResolveRequestLanguage;
use TransLangs\Language\Domain\LanguageRegistry;
use TransLangs\Language\Infrastructure\LanguageMetadataStore;
use TransLangs\Language\Infrastructure\OptionLanguageRegistry;
use TransLangs\Localization\HreflangPresenter;
use TransLangs\Localization\LocalizedUrlBuilder;
use TransLangs\Rendering\RequestContext;

tl_guard();
tl_begin();

// ── Setup: one throwaway enabled target language ─────────────────────────────
// zz_ZZ is not a real WordPress locale, so it cannot collide with anything
// already configured on this dev site (same convention as fallback_language_it.php).

$store    = new LanguageMetadataStore();
$registry = new OptionLanguageRegistry();

$store->save(['code' => 'zz_ZZ', 'slug' => 'zz']);
$registry->enableLanguage('zz_ZZ');

$default = $registry->getDefaultLanguage();
tl_ok('default language is set up', $default !== '', "default=$default");
tl_ok('zz_ZZ is now enabled', $registry->isEnabledLanguage('zz_ZZ'));

$resolver   = new ResolveRequestLanguage($registry);
$urlBuilder = new LocalizedUrlBuilder($resolver);
$context    = new RequestContext();
$presenter  = new HreflangPresenter($resolver, $urlBuilder, $context);

/** @return array<string,string> hreflang => href, parsed out of the rendered tags */
function tl_parse_hreflang_tags(string $html): array
{
    preg_match_all('/<link rel="alternate" hreflang="([^"]+)" href="([^"]+)" \/>/', $html, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $tag) {
        $out[$tag[1]] = $tag[2];
    }
    return $out;
}

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/tltest-hreflang-page/';

// ── 1. Reciprocal + complete set ──────────────────────────────────────────────

tl_section('renderHreflangTags: reciprocal + complete set');

ob_start();
$presenter->renderHreflangTags();
$tags = tl_parse_hreflang_tags((string) ob_get_clean());

$enabled = $resolver->getEnabledLanguages();

tl_ok(
    'one alternate per enabled language, plus x-default',
    count($tags) === count($enabled) + 1,
    'enabled=' . implode(',', $enabled) . ' tags=' . implode(',', array_keys($tags))
);

foreach ($enabled as $lang) {
    $hreflang = str_replace('_', '-', $lang);
    tl_ok("alternate present for enabled language $lang", isset($tags[$hreflang]), implode(',', array_keys($tags)));
}

$defaultHreflang = str_replace('_', '-', $default);
tl_ok(
    "default language ($default) is unprefixed",
    isset($tags[$defaultHreflang]) && str_ends_with($tags[$defaultHreflang], '/tltest-hreflang-page/') && !str_contains($tags[$defaultHreflang], '/zz/'),
    $tags[$defaultHreflang] ?? '(missing)'
);

tl_ok(
    'zz_ZZ alternate carries the /zz/ prefix',
    isset($tags['zz-ZZ']) && str_ends_with($tags['zz-ZZ'], '/zz/tltest-hreflang-page/'),
    $tags['zz-ZZ'] ?? '(missing)'
);

tl_ok(
    'x-default points at the default-language URL',
    isset($tags['x-default'], $tags[$defaultHreflang]) && $tags['x-default'] === $tags[$defaultHreflang],
    ($tags['x-default'] ?? '(missing)') . ' vs ' . ($tags[$defaultHreflang] ?? '(missing)')
);

// ── 2. "Prefix default language" option changes the default's own alternate ──

tl_section('renderHreflangTags: translangs_prefix_default_language option');

update_option('translangs_prefix_default_language', true);

ob_start();
$presenter->renderHreflangTags();
$prefixedTags = tl_parse_hreflang_tags((string) ob_get_clean());

tl_ok(
    'default language alternate is no longer the bare path once prefixed',
    isset($prefixedTags[$defaultHreflang]) && $prefixedTags[$defaultHreflang] !== ($tags[$defaultHreflang] ?? null),
    $prefixedTags[$defaultHreflang] ?? '(missing)'
);

update_option('translangs_prefix_default_language', false);

// ── 3. Gating ──────────────────────────────────────────────────────────────

tl_section('renderHreflangTags: gating');

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
};
$singleResolver  = new ResolveRequestLanguage($singleLangRegistry);
$singlePresenter = new HreflangPresenter($singleResolver, new LocalizedUrlBuilder($singleResolver), $context);

ob_start();
$singlePresenter->renderHreflangTags();
tl_ok('nothing rendered with fewer than 2 enabled languages', ob_get_clean() === '');

if (!($GLOBALS['wp_query'] ?? null) instanceof WP_Query) {
    $GLOBALS['wp_query'] = new WP_Query();
}
$GLOBALS['wp_query']->is_404 = true;
ob_start();
$presenter->renderHreflangTags();
tl_ok('nothing rendered on a 404', ob_get_clean() === '');
$GLOBALS['wp_query']->is_404 = false;

$_SERVER['REQUEST_URI'] = '/wp-admin/edit.php';
ob_start();
$presenter->renderHreflangTags();
tl_ok('nothing rendered on a wp-admin path', ob_get_clean() === '');
$_SERVER['REQUEST_URI'] = '/tltest-hreflang-page/';

// ── 4. filterCanonical ────────────────────────────────────────────────────

tl_section('filterCanonical');

tl_ok('non-string canonical passes through unchanged', $presenter->filterCanonical(false) === false);
tl_ok('empty canonical passes through unchanged', $presenter->filterCanonical('') === '');

$canonical = home_url('/tltest-hreflang-page/');

tl_ok(
    'default-language request leaves canonical untouched',
    $presenter->filterCanonical($canonical) === $canonical,
    (string) $presenter->filterCanonical($canonical)
);

set_query_var('translangs_lang', 'zz_ZZ');
$translatedResolver  = new ResolveRequestLanguage($registry);
$translatedPresenter = new HreflangPresenter($translatedResolver, new LocalizedUrlBuilder($translatedResolver), $context);

$rewritten = $translatedPresenter->filterCanonical($canonical);
tl_ok(
    'translated-language request re-prefixes the canonical to /zz/',
    is_string($rewritten) && str_ends_with($rewritten, '/zz/tltest-hreflang-page/'),
    (string) $rewritten
);

set_query_var('translangs_lang', '');

tl_done();
