<?php

/**
 * Short labels (label_format="short") show a code on screen — "FR-CA" — but a
 * screen reader reading that aloud says "F, R, C, A". The switcher keeps the
 * code visible and gives assistive technology "FR-CA, Français (Canada)":
 * the full name to understand, the code first so voice-control users can say
 * what they see (WCAG 2.5.3 Label in Name).
 *
 * Covers:
 *   1  native full name (region rule applied), spoken in the TARGET language
 *   2  English full name, spoken as English
 *   3  page-language full name on a French page, spoken as French
 *   4  guards: full labels and flag-only labels are unchanged (one span, no
 *      aria-hidden, no code in the spoken name)
 *
 * Run: ./wp eval-file tests/db/switcher_short_label_a11y_it.php
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
// names; rolled back by the harness. ─────────────────────────────────────────

$store    = new LanguageMetadataStore();
$registry = new OptionLanguageRegistry();
$langs    = new SupportedLanguages($store);
$default  = $registry->getDefaultLanguage();

test_ok('the source language is en_US (these expectations assume it)', $default === 'en_US', "default=$default");

foreach (['fr_FR' => ['lwt-fr', 'French (France)'], 'fr_CA' => ['lwt-frca', 'French (Canada)'], 'de_DE' => ['lwt-de', 'German (Germany)']] as $code => [$slug, $name]) {
    $store->save(['code' => $code, 'slug' => $slug, 'name' => $name]);
    $registry->enableLanguage($code);
}

$include = ['en_US', 'fr_FR', 'fr_CA', 'de_DE'];

$_SERVER['REQUEST_METHOD'] = 'GET';

function a11y_switcher(string $uri, string $active, OptionLanguageRegistry $registry, SupportedLanguages $langs): Switcher
{
    $_SERVER['REQUEST_URI'] = $uri;
    set_query_var('lingowp_lang', $active);
    $resolver = new ResolveRequestLanguage($registry);

    return new Switcher(new LanguageLinks($resolver, new LocalizedUrlBuilder($resolver), $langs));
}

/**
 * Per list item (in include order): the visible text span and the
 * screen-reader span, with their attributes.
 *
 * @return array<int, array{visible:string, visible_hidden:bool, sr:string, sr_lang:string, spans:int}>
 */
function a11y_items(string $html): array
{
    preg_match_all('#<li class="lingowp-switcher__item[^"]*">(.*?)</li>#s', $html, $lis);

    $out = [];
    foreach ($lis[1] as $li) {
        preg_match('#<span class="lingowp-switcher__text"([^>]*)>([^<]*)</span>#', $li, $v);
        preg_match('#<span class="lingowp-switcher__sr" lang="([^"]*)"[^>]*>([^<]*)</span>#', $li, $s);
        $out[] = [
            'visible'        => $v[2] ?? '',
            'visible_hidden' => isset($v[1]) && str_contains($v[1], 'aria-hidden="true"'),
            'sr'             => $s[2] ?? '',
            'sr_lang'        => $s[1] ?? '',
            'spans'          => preg_match_all('#<span class="lingowp-switcher__(?:text|sr)"#', $li),
        ];
    }

    return $out;
}

// ── 1. Native names ─────────────────────────────────────────────────────────

test_section('1. Short labels, native names');

$sw    = a11y_switcher('/lingowp-test-a11y/', '', $registry, $langs);
$items = a11y_items($sw->render(['layout' => 'vertical', 'include' => $include, 'label_format' => 'short']));
$frca  = $items[2] ?? [];

test_ok('FR-CA stays visible on screen', ($frca['visible'] ?? '') === 'FR-CA', $frca['visible'] ?? '(missing)');
test_ok('... and is hidden from screen readers (aria-hidden)', ($frca['visible_hidden'] ?? false) === true);
test_ok('screen readers hear "FR-CA, Français (Canada)"', ($frca['sr'] ?? '') === 'FR-CA, Français (Canada)', $frca['sr'] ?? '(missing)');
test_ok('... in the target language\'s voice (lang="fr-CA")', ($frca['sr_lang'] ?? '') === 'fr-CA', $frca['sr_lang'] ?? '(missing)');
test_ok('the region rule applies to the spoken name too: "FR, Français"', ($items[1]['sr'] ?? '') === 'FR, Français', $items[1]['sr'] ?? '(missing)');
test_ok('a single-region language: "DE, Deutsch"', ($items[3]['sr'] ?? '') === 'DE, Deutsch', $items[3]['sr'] ?? '(missing)');

// ── 2. English names ────────────────────────────────────────────────────────

test_section('2. Short labels, English names');

$items = a11y_items($sw->render(['layout' => 'vertical', 'include' => $include, 'label_format' => 'short', 'name_source' => 'english']));
test_ok('spoken "FR-CA, French (Canada)"', ($items[2]['sr'] ?? '') === 'FR-CA, French (Canada)', $items[2]['sr'] ?? '(missing)');
test_ok('... as English (lang="en")', ($items[2]['sr_lang'] ?? '') === 'en', $items[2]['sr_lang'] ?? '(missing)');

// ── 3. Page-language names ──────────────────────────────────────────────────

test_section('3. Short labels, page-language names on a French page');

$sw    = a11y_switcher('/lwt-fr/lingowp-test-a11y/', 'fr_FR', $registry, $langs);
$items = a11y_items($sw->render(['layout' => 'vertical', 'include' => $include, 'label_format' => 'short', 'name_source' => 'current']));
test_ok('spoken "DE, Allemand"', ($items[3]['sr'] ?? '') === 'DE, Allemand', $items[3]['sr'] ?? '(missing)');
test_ok('... as French (lang="fr-FR")', ($items[3]['sr_lang'] ?? '') === 'fr-FR', $items[3]['sr_lang'] ?? '(missing)');

// ── 4. Guards: other label formats unchanged ────────────────────────────────

test_section('4. Guards: full and flag-only labels unchanged');

$sw    = a11y_switcher('/lingowp-test-a11y/', '', $registry, $langs);
$items = a11y_items($sw->render(['layout' => 'vertical', 'include' => $include]));
test_ok('full label: one span, visible, not aria-hidden', ($items[2]['spans'] ?? 0) === 1 && ($items[2]['visible'] ?? '') === 'Français (Canada)' && !($items[2]['visible_hidden'] ?? true));

$items = a11y_items($sw->render(['layout' => 'horizontal', 'include' => $include, 'label_format' => 'none']));
test_ok('flag-only: one screen-reader span with the full name, no code', ($items[2]['spans'] ?? 0) === 1 && ($items[2]['sr'] ?? '') === 'Français (Canada)', $items[2]['sr'] ?? '(missing)');

set_query_var('lingowp_lang', '');

test_done();
