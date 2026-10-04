<?php
/**
 * Reproductions for the switcher backend review (Codex gpt-6-astra, 2026-10-04).
 * Each section pins one confirmed finding; before its fix the section FAILS on
 * purpose — that failure is the reproduction. After the fix it must pass.
 *
 *   #2  Subfolder install: the language prefix lands before the site folder
 *       (/zz/blog/about/ instead of /blog/zz/about/). Shared URL builder —
 *       affects LanguageLinks rows, so every switcher link.
 *   #4  include without the current language: the first included language is
 *       treated as current (dropdown button, name_source="current" language).
 *   #6  hide_current leaves no choices: the dropdown still renders, empty.
 *   #7  Duplicate locales in include / order produce duplicate entries.
 *
 * Not reproducible here (see review notes): #1 mbstring and #5 intl cannot be
 * unloaded at runtime; #3 is JavaScript — tests/switcher_late_script.test.js.
 *
 * Run: ./wp eval-file tests/db/switcher_review_it.php
 */

require __DIR__ . '/_harness.php';

use LingoWP\Language\Application\ResolveRequestLanguage;
use LingoWP\Language\Infrastructure\LanguageMetadataStore;
use LingoWP\Language\Infrastructure\OptionLanguageRegistry;
use LingoWP\Language\Infrastructure\SupportedLanguages;
use LingoWP\LocalizationRouting\LanguageLinks;
use LingoWP\LocalizationRouting\LocalizedUrlBuilder;
use LingoWP\Switcher\Switcher;

test_guard();
test_begin();

// ── Setup: throwaway enabled target languages (same convention as
// language_url_it.php — not real locales, cannot collide with the dev site) ──

$store    = new LanguageMetadataStore();
$registry = new OptionLanguageRegistry();
$langs    = new SupportedLanguages($store);

$store->save(['code' => 'zz_ZZ', 'slug' => 'zz']);
$store->save(['code' => 'yy_YY', 'slug' => 'yy']);
$registry->enableLanguage('zz_ZZ');
$registry->enableLanguage('yy_YY');

$default    = $registry->getDefaultLanguage();
$defaultTag = str_replace('_', '-', $default);

test_ok('default language is set up', $default !== '', "default=$default");

$_SERVER['REQUEST_METHOD'] = 'GET';

/** LanguageLinks for a request, built fresh so no per-request memo leaks between cases. */
function review_links(string $uri, OptionLanguageRegistry $registry, SupportedLanguages $langs, string $active = ''): LanguageLinks
{
    $_SERVER['REQUEST_URI'] = $uri;
    set_query_var('lingowp_lang', $active);
    $resolver = new ResolveRequestLanguage($registry);

    return new LanguageLinks($resolver, new LocalizedUrlBuilder($resolver), $langs);
}

/** @param array<int, array<string, mixed>> $rows */
function review_path(array $rows, string $code): string
{
    foreach ($rows as $row) {
        if ($row['code'] === $code) {
            return (string) wp_parse_url((string) $row['url'], PHP_URL_PATH);
        }
    }

    return '(missing)';
}

/** The visible label text and its lang attribute inside the dropdown button. */
function review_trigger(string $html): array
{
    preg_match('#<button[^>]*lingowp-switcher__trigger.*?<span[^>]*lang="([^"]*)"[^>]*>([^<]*)</span>#s', $html, $m);

    return ['lang' => $m[1] ?? '(none)', 'text' => $m[2] ?? '(none)'];
}

/** lang attributes of every label inside the list items. */
function review_item_langs(string $html): array
{
    preg_match_all('#<li class="lingowp-switcher__item.*?<span class="lingowp-switcher__(?:text|sr)" lang="([^"]*)"#s', $html, $m);

    return array_values(array_unique($m[1]));
}

// ── #2 Subfolder install ─────────────────────────────────────────────────────

test_section('#2 Subfolder install (home_url() ends in /blog)');

// Simulate WordPress installed at https://host/blog: home_url() carries the
// folder, and every request path starts with it.
// Faithful to a real /blog install: home_url() is the site address + /blog +
// the given path, ALWAYS — so a path that already carries /blog comes back
// doubled, exactly like the real subfolder site did. (An earlier version only
// added /blog when missing, which hid that doubling.)
$subfolder = static function (string $url, $path = ''): string {
    $tail = (is_string($path) && $path !== '') ? '/' . ltrim($path, '/') : '';

    return substr($url, 0, strlen($url) - strlen($tail)) . '/blog' . $tail;
};
add_filter('home_url', $subfolder, 10, 2);

$rows = review_links('/blog/about/', $registry, $langs)->links();
test_ok('home_url() really is in /blog (setup)', str_ends_with(rtrim(home_url('/'), '/'), '/blog'), home_url('/'));
test_ok('[/blog/about/] source -> /blog/about/', review_path($rows, $default) === '/blog/about/', review_path($rows, $default));
test_ok('[/blog/about/] zz_ZZ -> /blog/zz/about/', review_path($rows, 'zz_ZZ') === '/blog/zz/about/', review_path($rows, 'zz_ZZ'));

$rows = review_links('/blog/zz/about/', $registry, $langs, 'zz_ZZ')->links();
test_ok('[/blog/zz/about/] yy_YY -> /blog/yy/about/ (prefix replaced, not stacked)', review_path($rows, 'yy_YY') === '/blog/yy/about/', review_path($rows, 'yy_YY'));
test_ok('[/blog/zz/about/] source -> /blog/about/', review_path($rows, $default) === '/blog/about/', review_path($rows, $default));

$rows = review_links('/blog/', $registry, $langs)->links();
test_ok('[/blog/] zz_ZZ home -> /blog/zz/', review_path($rows, 'zz_ZZ') === '/blog/zz/', review_path($rows, 'zz_ZZ'));

remove_filter('home_url', $subfolder, 10);

// ── #4 include without the current language ─────────────────────────────────

test_section('#4 include leaves out the current language');

// The source language is current (unprefixed /about/); include lists only the
// two throwaway languages.
$switcher = new Switcher(review_links('/about/', $registry, $langs));
$html     = $switcher->render(['include' => ['zz_ZZ', 'yy_YY'], 'name_source' => 'current']);
$trigger  = review_trigger($html);

test_ok('renders something (setup)', $html !== '');
test_ok(
    "dropdown button shows the current language ($default), not the first included one",
    $trigger['lang'] === $defaultTag && !str_contains($html, 'is-current'),
    "button label lang={$trigger['lang']} text={$trigger['text']}"
);
test_ok(
    "name_source=\"current\" writes the choices in the page language ($defaultTag)",
    review_item_langs($html) === [$defaultTag],
    'item label langs: ' . implode(', ', review_item_langs($html))
);

// ── #6 hide_current leaves no choices ───────────────────────────────────────

test_section('#6 hide_current removes the only listed language');

$switcher = new Switcher(review_links('/about/', $registry, $langs));
$html     = $switcher->render(['include' => [$default], 'hide_current' => true]);
test_ok(
    'renders nothing instead of an empty dropdown',
    $html === '',
    $html === '' ? '' : 'got markup with ' . substr_count($html, '<li') . ' list item(s)'
);

// ── #7 Duplicate locales ────────────────────────────────────────────────────

test_section('#7 Duplicate locales in include / order');

$links = review_links('/about/', $registry, $langs);

$rows  = $links->links(['include' => [$default, 'zz_ZZ', 'zz_ZZ']]);
$codes = array_column($rows, 'code');
test_ok('include with a repeated locale lists it once', $codes === [$default, 'zz_ZZ'], implode(', ', $codes));

$rows  = $links->links(['order' => ['yy_YY', 'zz_ZZ', 'yy_YY']]);
$codes = array_column($rows, 'code');
test_ok('order with a repeated locale lists it once, first position kept', $codes === ['yy_YY', 'zz_ZZ'], implode(', ', $codes));

$html = (new Switcher($links))->render(['layout' => 'vertical', 'include' => [$default, 'zz_ZZ', 'zz_ZZ']]);
test_ok('the switcher renders 2 items, not 3', substr_count($html, '<li ') === 2, substr_count($html, '<li ') . ' item(s)');

set_query_var('lingowp_lang', '');

test_done();
