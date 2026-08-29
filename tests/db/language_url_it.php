<?php

/**
 * The language-links API end to end, against real production classes and the
 * live options table — no mocks for ResolveRequestLanguage / LocalizedUrlBuilder,
 * since their exact prefixing rules are the thing being verified.
 *
 * Covers:
 *   1. LanguageLinks::links() — row shape, absolute URLs, source/current flags.
 *   2. The URL matrix: front page, singular, paginated, date archive, search,
 *      query strings, already-prefixed paths, trailing-slash variants,
 *      double-switch idempotence.
 *   3. SwitcherRenderer — markup contract, dropdown <details>, aria-current,
 *      is-current, RTL dir, empty output with < 2 languages.
 *   4. The lingowp_language_links filter dropping a language.
 *   5. LanguageHtmlAttributes — <html lang>/dir swap on translated requests.
 *
 * Run: wp eval-file tests/db/language_url_it.php
 */

require __DIR__ . '/_harness.php';

use LingoWP\Language\Application\ResolveRequestLanguage;
use LingoWP\Language\Domain\LanguageRegistry;
use LingoWP\Language\Infrastructure\LanguageMetadataStore;
use LingoWP\Language\Infrastructure\OptionLanguageRegistry;
use LingoWP\Language\Infrastructure\SupportedLanguages;
use LingoWP\LocalizationRouting\LanguageLinks;
use LingoWP\LocalizationRouting\LocalizedUrlBuilder;
use LingoWP\LocalizationRouting\SwitcherRenderer;
use LingoWP\Resolution\RequestContext;
use LingoWP\Resolution\Seo\LanguageHtmlAttributes;

test_guard();
test_begin();

// ── Setup: throwaway enabled target languages ────────────────────────────────
// zz_ZZ / yy_YY / rr_RR are not real WordPress locales, so they cannot collide
// with anything configured on this dev site (same convention as hreflang_it.php).

$store    = new LanguageMetadataStore();
$registry = new OptionLanguageRegistry();
$langs    = new SupportedLanguages($store);

$store->save(['code' => 'zz_ZZ', 'slug' => 'zz']);
$store->save(['code' => 'yy_YY', 'slug' => 'yy']);
$store->save(['code' => 'rr_RR', 'slug' => 'rr', 'direction' => 'rtl']);
$registry->enableLanguage('zz_ZZ');
$registry->enableLanguage('yy_YY');
$registry->enableLanguage('rr_RR');

$default = $registry->getDefaultLanguage();
test_ok('default language is set up', $default !== '', "default=$default");
test_ok('zz_ZZ is enabled', $registry->isEnabledLanguage('zz_ZZ'));

$_SERVER['REQUEST_METHOD'] = 'GET';

/** @return array<int, array<string, mixed>> */
function test_links_for(string $uri, OptionLanguageRegistry $registry, SupportedLanguages $langs, string $active = ''): array
{
    $_SERVER['REQUEST_URI'] = $uri;
    set_query_var('lingowp_lang', $active);
    $resolver = new ResolveRequestLanguage($registry);
    return (new LanguageLinks($resolver, new LocalizedUrlBuilder($resolver), $langs))->links();
}

/** @param array<int, array<string, mixed>> $rows @return array<string, mixed>|null */
function test_row(array $rows, string $code): ?array
{
    foreach ($rows as $r) {
        if ($r['code'] === $code) {
            return $r;
        }
    }
    return null;
}

/** Path + query of a URL, for matrix assertions. */
function test_pq(string $url): string
{
    $path  = (string) wp_parse_url($url, PHP_URL_PATH);
    $query = (string) wp_parse_url($url, PHP_URL_QUERY);
    return $path . ($query !== '' ? '?' . $query : '');
}

// ── 1. Row shape ────────────────────────────────────────────────────────────

test_section('LanguageLinks::links() — row shape');

$rows = test_links_for('/about/', $registry, $langs, 'zz_ZZ');
$zz   = test_row($rows, 'zz_ZZ');
$def  = test_row($rows, $default);

test_ok('zz_ZZ row present', $zz !== null);
test_ok(
    'row carries every documented key',
    $zz !== null && array_keys($zz) === ['code', 'slug', 'url', 'native_name', 'english_name', 'dir', 'is_source', 'current'],
    $zz === null ? '(missing)' : implode(',', array_keys($zz))
);
test_ok('zz_ZZ url is absolute', $zz !== null && str_starts_with((string) $zz['url'], 'http'), $zz['url'] ?? '(missing)');
test_ok('zz_ZZ is the current row', $zz !== null && $zz['current'] === true);
test_ok('zz_ZZ is not the source', $zz !== null && $zz['is_source'] === false);
test_ok('default row is the source', $def !== null && $def['is_source'] === true);
test_ok('default row is not current', $def !== null && $def['current'] === false);
test_ok('source row is listed first', ($rows[0]['code'] ?? '') === $default, $rows[0]['code'] ?? '(empty)');

// ── 2. URL matrix ──────────────────────────────────────────────────────────

test_section('URL matrix (zz_ZZ prefixed, default unprefixed)');

/** @param array{0:string,1:string,2:string} $case  [request uri, expected zz path+query, expected default path+query] */
$matrix = [
    ['/',                     '/zz/',                   '/'],
    ['/about/',               '/zz/about/',             '/about/'],
    ['/about',                '/zz/about',              '/about'],
    ['/blog/page/3/',         '/zz/blog/page/3/',       '/blog/page/3/'],
    ['/2026/08/',             '/zz/2026/08/',           '/2026/08/'],
    ['/?s=hello',             '/zz/?s=hello',           '/?s=hello'],
    ['/shop/?orderby=price',  '/zz/shop/?orderby=price', '/shop/?orderby=price'],
];

foreach ($matrix as [$uri, $expectZz, $expectDefault]) {
    $rows = test_links_for($uri, $registry, $langs, 'zz_ZZ');
    $zz   = test_row($rows, 'zz_ZZ');
    $def  = test_row($rows, $default);
    test_ok("[$uri] zz_ZZ -> $expectZz", $zz !== null && test_pq((string) $zz['url']) === $expectZz, $zz === null ? '(missing)' : test_pq((string) $zz['url']));
    test_ok("[$uri] $default -> $expectDefault", $def !== null && test_pq((string) $def['url']) === $expectDefault, $def === null ? '(missing)' : test_pq((string) $def['url']));
}

// Already on a prefixed path: switching to another language must not stack
// prefixes, and switching to source must strip cleanly.
$rows = test_links_for('/zz/about/', $registry, $langs, 'zz_ZZ');
test_ok('/zz/about/ -> yy_YY is /yy/about/ (no /zz/yy/)', test_pq((string) test_row($rows, 'yy_YY')['url']) === '/yy/about/', test_pq((string) test_row($rows, 'yy_YY')['url']));
test_ok('/zz/about/ -> zz_ZZ stays /zz/about/', test_pq((string) test_row($rows, 'zz_ZZ')['url']) === '/zz/about/', test_pq((string) test_row($rows, 'zz_ZZ')['url']));
test_ok('/zz/about/ -> source strips to /about/ (no //)', test_pq((string) test_row($rows, $default)['url']) === '/about/', test_pq((string) test_row($rows, $default)['url']));

// Double-switch idempotence on LocalizedUrlBuilder directly.
$builder = new LocalizedUrlBuilder(new ResolveRequestLanguage($registry));
$once  = $builder->addPrefix($builder->stripPrefix('/zz/about/'), 'zz_ZZ');
$twice = $builder->addPrefix($builder->stripPrefix($once), 'zz_ZZ');
test_ok('addPrefix/stripPrefix is idempotent', $once === $twice, "$once vs $twice");

// ── 3. SwitcherRenderer ────────────────────────────────────────────────────

test_section('SwitcherRenderer — markup contract');

$_SERVER['REQUEST_URI'] = '/about/';
set_query_var('lingowp_lang', 'zz_ZZ');
$resolver = new ResolveRequestLanguage($registry);
$renderer = new SwitcherRenderer(new LanguageLinks($resolver, new LocalizedUrlBuilder($resolver), $langs));

$dropdown = $renderer->render(['display' => 'code', 'layout' => 'dropdown']);
test_ok('nav wrapper + aria-label', str_contains($dropdown, '<nav class="lingowp-switcher lingowp-switcher--dropdown" data-lingowp-switcher aria-label="Language">'));
test_ok('dropdown uses <details>', str_contains($dropdown, '<details class="lingowp-switcher__details">') && str_contains($dropdown, '<summary class="lingowp-switcher__current">'));
test_ok('active item carries aria-current', preg_match('/data-lingowp-lang="zz_ZZ"[^>]*aria-current="true"/', $dropdown) === 1, $dropdown);
test_ok('active item carries is-current class', str_contains($dropdown, '<li class="lingowp-switcher__item is-current">'));
test_ok('rr_RR link carries dir="rtl"', preg_match('/data-lingowp-lang="rr_RR" dir="rtl"/', $dropdown) === 1, $dropdown);
test_ok('links are absolute + escaped', preg_match('#href="https?://[^"]+/zz/about/"#', $dropdown) === 1, $dropdown);

$inline = $renderer->render(['layout' => 'inline']);
test_ok('inline layout has no <details>', !str_contains($inline, '<details'));
test_ok('inline layout class', str_contains($inline, 'lingowp-switcher--inline'));

$hidden = $renderer->render(['hide_current' => true]);
test_ok('hide_current drops the active language', !str_contains($hidden, 'data-lingowp-lang="zz_ZZ"'));

// ── 4. lingowp_language_links filter ───────────────────────────────────────

test_section('lingowp_language_links filter');

$dropZz = static function (array $rows): array {
    return array_values(array_filter($rows, static fn(array $r): bool => $r['code'] !== 'zz_ZZ'));
};
add_filter('lingowp_language_links', $dropZz);

$rows = test_links_for('/about/', $registry, $langs, 'yy_YY');
test_ok('filter removes zz_ZZ from the data', test_row($rows, 'zz_ZZ') === null);

$resolver = new ResolveRequestLanguage($registry);
$filtered = (new SwitcherRenderer(new LanguageLinks($resolver, new LocalizedUrlBuilder($resolver), $langs)))->render();
test_ok('filter removes zz_ZZ from the markup', !str_contains($filtered, 'data-lingowp-lang="zz_ZZ"'));

remove_filter('lingowp_language_links', $dropZz);

// ── 5. Fewer than 2 languages ─────────────────────────────────────────────

test_section('gating: < 2 enabled languages');

$singleRegistry = new class ($default) implements LanguageRegistry {
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
$singleResolver = new ResolveRequestLanguage($singleRegistry);
$singleLinks    = new LanguageLinks($singleResolver, new LocalizedUrlBuilder($singleResolver), $langs);
test_ok('links() is empty with 1 language', $singleLinks->links() === []);
test_ok('renderer is empty with 1 language', (new SwitcherRenderer($singleLinks))->render() === '');

// ── 6. LanguageHtmlAttributes ─────────────────────────────────────────────

test_section('LanguageHtmlAttributes — <html lang>/dir');

$_SERVER['REQUEST_URI'] = '/lingowp-test-attr-page/';

set_query_var('lingowp_lang', '');
$attrsDefault = new LanguageHtmlAttributes(new ResolveRequestLanguage($registry), new RequestContext(), $langs);
test_ok('default-language request leaves lang untouched', $attrsDefault->filter('lang="en-US"') === 'lang="en-US"', $attrsDefault->filter('lang="en-US"'));

set_query_var('lingowp_lang', 'zz_ZZ');
$attrsZz = new LanguageHtmlAttributes(new ResolveRequestLanguage($registry), new RequestContext(), $langs);
test_ok('translated request swaps lang to zz-ZZ', $attrsZz->filter('lang="en-US"') === 'lang="zz-ZZ"', $attrsZz->filter('lang="en-US"'));

set_query_var('lingowp_lang', 'rr_RR');
$attrsRr = new LanguageHtmlAttributes(new ResolveRequestLanguage($registry), new RequestContext(), $langs);
test_ok('RTL target adds dir="rtl"', $attrsRr->filter('lang="en-US"') === 'lang="rr-RR" dir="rtl"', $attrsRr->filter('lang="en-US"'));
test_ok('RTL target drops an inherited LTR dir', $attrsRr->filter('dir="ltr" lang="en-US"') === 'lang="rr-RR" dir="rtl"', $attrsRr->filter('dir="ltr" lang="en-US"'));

set_query_var('lingowp_lang', '');

test_done();
