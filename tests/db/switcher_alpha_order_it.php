<?php

/**
 * REPRODUCTION SUITE — the switcher's order="alpha" must sort by the label the
 * visitor actually reads (plan: "alphabetical by selected label"), sorted the
 * way a dictionary in the page's language sorts — not by byte value.
 *
 * Registered in composer.json's test:db together with the fix (same convention
 * as slug_routing_bugs_it.php): from then on it must stay all-green.
 *
 * Seen on the playground page (2026-10-04): alpha sorted by the full native
 * name in LanguageLinks, BEFORE the switcher picked the label — "Français
 * (Canada)" came before "Français", and with English names "Arabic" came last
 * and "Spanish" second (from "Español"). Also: when `include` is given,
 * LanguageLinks keeps the include order, so alpha was silently ignored.
 *
 * Every case passes the languages through `include` in a deliberately
 * NON-alphabetical order, so the expected order can only come from sorting.
 *
 * Covers:
 *   1  native names (region rule applied) — accent-aware: Íslenska before
 *      Italiano, Latin script before Arabic
 *   2  English names
 *   3  names in the page language (French page): Allemand, Anglais, Arabe…
 *   4  short names: AR, DE, EN, …
 *   5  guards that must keep passing: order="settings" keeps the include
 *      order; the data API lingowp_get_language_links() is unchanged
 *
 * Run: ./wp eval-file tests/db/switcher_alpha_order_it.php
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

// ── Setup: real locales (real ICU names), throwaway slugs, explicit English
// names so nothing depends on WordPress's downloaded language catalog. All of
// it is rolled back by the harness. ─────────────────────────────────────────

$store    = new LanguageMetadataStore();
$registry = new OptionLanguageRegistry();
$langs    = new SupportedLanguages($store);
$default  = $registry->getDefaultLanguage();

test_ok('the source language is en_US (these expectations assume it)', $default === 'en_US', "default=$default");

$fixtures = [
    'de_DE' => ['lwt-de', 'German (Germany)'],
    'es_ES' => ['lwt-es', 'Spanish (Spain)'],
    'fr_FR' => ['lwt-fr', 'French (France)'],
    'fr_CA' => ['lwt-frca', 'French (Canada)'],
    'it_IT' => ['lwt-it', 'Italian (Italy)'],
    'is_IS' => ['lwt-is', 'Icelandic (Iceland)'],
    'ar'    => ['lwt-ar', 'Arabic'],
];
foreach ($fixtures as $code => [$slug, $name]) {
    $store->save(['code' => $code, 'slug' => $slug, 'name' => $name, 'direction' => $code === 'ar' ? 'rtl' : 'ltr']);
    $registry->enableLanguage($code);
}

// Deliberately not alphabetical in any of the label sets below.
$include = ['en_US', 'ar', 'fr_CA', 'it_IT', 'es_ES', 'fr_FR', 'is_IS', 'de_DE'];

$_SERVER['REQUEST_METHOD'] = 'GET';

/** A switcher for a request on the given page language (fresh, nothing memoized). */
function alpha_switcher(string $uri, string $active, OptionLanguageRegistry $registry, SupportedLanguages $langs): Switcher
{
    $_SERVER['REQUEST_URI'] = $uri;
    set_query_var('lingowp_lang', $active);
    $resolver = new ResolveRequestLanguage($registry);

    return new Switcher(new LanguageLinks($resolver, new LocalizedUrlBuilder($resolver), $langs));
}

/** The visible label of every list item, in order. */
function alpha_labels(string $html): array
{
    preg_match_all('#<li class="lingowp-switcher__item.*?<span class="lingowp-switcher__(?:text|sr)"[^>]*>([^<]*)</span>#s', $html, $m);

    return $m[1];
}

function alpha_show(array $labels): string
{
    return implode(' · ', $labels);
}

// ── 1. Native names ─────────────────────────────────────────────────────────

test_section('1. Native names, English page');

$sw     = alpha_switcher('/lingowp-test-alpha/', '', $registry, $langs);
$labels = alpha_labels($sw->render(['layout' => 'vertical', 'include' => $include, 'order' => 'alpha']));
$expect = ['Deutsch', 'English', 'Español', 'Français', 'Français (Canada)', 'Íslenska', 'Italiano', 'العربية'];
test_ok('sorted by the shown native label, accents and scripts in dictionary order', $labels === $expect, 'got: ' . alpha_show($labels) . '  want: ' . alpha_show($expect));

// ── 2. English names ────────────────────────────────────────────────────────

test_section('2. English names, English page');

$labels = alpha_labels($sw->render(['layout' => 'vertical', 'include' => $include, 'order' => 'alpha', 'name_source' => 'english']));
$expect = ['Arabic', 'English', 'French', 'French (Canada)', 'German', 'Icelandic', 'Italian', 'Spanish'];
test_ok('sorted by the shown English label', $labels === $expect, 'got: ' . alpha_show($labels) . '  want: ' . alpha_show($expect));

// ── 3. Names in the page language ───────────────────────────────────────────

test_section('3. Page-language names, French page');

$sw     = alpha_switcher('/lwt-fr/lingowp-test-alpha/', 'fr_FR', $registry, $langs);
$labels = alpha_labels($sw->render(['layout' => 'vertical', 'include' => $include, 'order' => 'alpha', 'name_source' => 'current']));
$expect = ['Allemand', 'Anglais', 'Arabe', 'Espagnol', 'Français', 'Français (Canada)', 'Islandais', 'Italien'];
test_ok('sorted by the French label, as a French dictionary would', $labels === $expect, 'got: ' . alpha_show($labels) . '  want: ' . alpha_show($expect));

// ── 4. Short names ──────────────────────────────────────────────────────────

test_section('4. Short names, English page');

$sw     = alpha_switcher('/lingowp-test-alpha/', '', $registry, $langs);
$labels = alpha_labels($sw->render(['layout' => 'vertical', 'include' => $include, 'order' => 'alpha', 'label_format' => 'short']));
$expect = ['AR', 'DE', 'EN', 'ES', 'FR', 'FR-CA', 'IS', 'IT'];
test_ok('sorted by the shown code', $labels === $expect, 'got: ' . alpha_show($labels) . '  want: ' . alpha_show($expect));

// ── 5. Guards (pass before and after the fix) ───────────────────────────────

test_section('5. Guards');

$labels = alpha_labels($sw->render(['layout' => 'vertical', 'include' => $include, 'order' => 'settings']));
$expect = ['English', 'العربية', 'Français (Canada)', 'Italiano', 'Español', 'Français', 'Íslenska', 'Deutsch'];
test_ok('order="settings" with include keeps the include order', $labels === $expect, 'got: ' . alpha_show($labels));

$_SERVER['REQUEST_URI'] = '/lingowp-test-alpha/';
set_query_var('lingowp_lang', '');
$resolver = new ResolveRequestLanguage($registry);
$codes    = array_column((new LanguageLinks($resolver, new LocalizedUrlBuilder($resolver), $langs))->links(['include' => $include, 'order' => 'alpha']), 'code');
test_ok('data API unchanged: LanguageLinks still lets include win over order', $codes === $include, implode(', ', $codes));

set_query_var('lingowp_lang', '');

test_done();
