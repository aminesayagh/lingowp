<?php

/**
 * Standalone check for SeoTemplateVariables + TranslationSubmissionGuard — no framework.
 * Run: php tests/seo_var_guard_check.php
 * Exits non-zero if any case fails. Mirrors the §2 test table of
 * plan-seo-integration-layer.md.
 *
 * SeoTemplateVariables is now an empty generic engine in core — Yoast/Rank
 * Math's own grammar/descriptor data lives in the lingowp-seo add-on and is
 * normally supplied via apply_filters(). There's no WordPress bootstrap here,
 * so this defines a minimal local apply_filters() shim (only if not already
 * defined) that hands back the add-on's data for those two filter tags —
 * exercising the exact same filter-based extension point end to end, without
 * pulling in a WordPress bootstrap.
 */

require __DIR__ . '/../src/Shared/Parsing/UnitStructureGuard.php';
require __DIR__ . '/../src/Shared/Parsing/ShortcodeGuard.php';
require __DIR__ . '/../src/Shared/Parsing/SeoTemplateVariables.php';
require __DIR__ . '/../src/Shared/Parsing/TranslationSubmissionGuard.php';
require __DIR__ . '/../../lingowp-seo/src/SeoTemplateData.php';

use LingoWP\Shared\Parsing\SeoTemplateVariables;
use LingoWP\Shared\Parsing\TranslationSubmissionGuard;
use LingoWPSeo\SeoTemplateData;

if (!function_exists('apply_filters')) {
    function apply_filters(string $tag, $value)
    {
        if ($tag === 'lingowp_seo_template_descriptors') {
            return SeoTemplateData::DESCRIPTORS;
        }
        if ($tag === 'lingowp_seo_template_grammars') {
            return SeoTemplateData::GRAMMARS;
        }

        return $value;
    }
}

$YT = '_yoast_wpseo_title';
$RT = 'rank_math_title';

$failed = 0;

function check(string $label, $got, $expected): void
{
    global $failed;
    if (is_array($got)) {
        sort($got);
    }
    if (is_array($expected)) {
        sort($expected);
    }
    if ($got === $expected) {
        echo "ok    $label\n";

        return;
    }
    $failed++;
    echo "FAIL  $label\n";
    echo "        expected: " . json_encode($expected) . "\n";
    echo "        got:      " . json_encode($got) . "\n";
}

// ── grammar is key-aware, never sniffed ──────────────────────────────────────
check('yoast tokens',        SeoTemplateVariables::extract($YT, 'Buy %%title%% on %%sitename%%'), ['%%title%%', '%%sitename%%']);
check('rankmath tokens',     SeoTemplateVariables::extract($RT, 'Buy %title% on %sitename%'),     ['%title%', '%sitename%']);
check('rankmath args opaque', SeoTemplateVariables::extract($RT, 'A %customfield( my_key )% B'),  ['%customfield( my_key )%']);
check('yoast grammar ignores single-%', SeoTemplateVariables::extract($YT, 'Save 50% now %title%'), []);
// Rank Math's own tokenizer accepts any non-space run as a plain variable, so we
// mirror it verbatim rather than assuming [a-z0-9_-]+ (class-replacer.php:172).
check('rankmath lone % is not a token', SeoTemplateVariables::extract($RT, 'Save 50% today'),      []);
check('rankmath skips bare % pair',     SeoTemplateVariables::extract($RT, 'Save 50% now %title%'), ['%title%']);
check('rankmath broad plain var',       SeoTemplateVariables::extract($RT, 'A %foo.bar% B'),        ['%foo.bar%']);
check('unsupported key',     SeoTemplateVariables::extract('_wc_product_short_description', 'Buy %%title%%'), []);
check('repeats preserved',   SeoTemplateVariables::extract($YT, '%%title%% | %%title%%'), ['%%title%%', '%%title%%']);

// ── pure-template detection (discovery skip) ─────────────────────────────────
check('pure template skipped',      SeoTemplateVariables::hasTranslatableProse($YT, '%%title%% %%sep%% %%sitename%%'), false);
check('pure rankmath skipped',      SeoTemplateVariables::hasTranslatableProse($RT, '%title% %sep% %sitename%'),       false);
check('mixed prose kept',           SeoTemplateVariables::hasTranslatableProse($YT, 'Buy %%title%% online'),           true);
check('plain prose kept',           SeoTemplateVariables::hasTranslatableProse($YT, 'About our company'),              true);
check('unsupported key untouched',  SeoTemplateVariables::hasTranslatableProse('_wc_product_short_description', '%%x%%'), true);

// ── write guard: multiset equality, INCLUDING the empty sides ────────────────
check('identical vars ok',      TranslationSubmissionGuard::validate('Buy %%title%% online', 'Achetez %%title%% en ligne', $YT), []);
check('variable dropped',       TranslationSubmissionGuard::validate('Buy %%title%% online', 'Achetez en ligne', $YT),           ['missing_variable:%%title%%']);
check('variable injected',      TranslationSubmissionGuard::validate('About our company', 'À propos de %%title%%', $YT),         ['extra_variable:%%title%%']);
check('all vars removed',       TranslationSubmissionGuard::validate('%title% x %sep%', 'y', $RT),                               ['missing_variable:%title%', 'missing_variable:%sep%']);
check('none either side',       TranslationSubmissionGuard::validate('About us', 'À propos', $YT),                               []);
check('duplicate count drops',  TranslationSubmissionGuard::validate('%%title%% | %%title%%', '%%title%% seul', $YT),            ['missing_variable:%%title%%']);
check('unsupported key skips',  TranslationSubmissionGuard::validate('Buy %%title%%', 'Achetez', '_wc_product_short_description'), []);
check('null meta key skips',    TranslationSubmissionGuard::validate('Buy %%title%%', 'Achetez', null),                          []);

// ── template patterns: recover what variables expanded to ────────────────────
// Returns a JSON string so keys are compared exactly (check() sorts arrays,
// which would reindex an assoc array and stop verifying the token names).
function caps(string $key, string $template, string $rendered): string {
    $p = SeoTemplateVariables::pattern($key, $template);
    if ($p === null) {
        return 'BAIL';
    }
    if (preg_match($p['regex'], $rendered, $m) !== 1) {
        return 'NO_MATCH';
    }
    $out = [];
    foreach ($p['tokens'] as $group => $token) {
        $out[$group] = [$token, $m[$group] ?? null];
    }

    return json_encode($out);
}

check('yoast shape recovers value',     caps($YT, 'Buy %%title%% online', 'Buy Product Name online'),               '{"v0":["%%title%%","Product Name"]}');
check('rankmath shape recovers value',  caps($RT, 'Achetez %title% en ligne', 'Achetez Product Name en ligne'),     '{"v0":["%title%","Product Name"]}');
check('leading variable ok',            caps($YT, '%%title%% online', 'Widgets online'),                            '{"v0":["%%title%%","Widgets"]}');
check('anchored — partial fails',       caps($YT, 'Buy %%title%% online', 'Buy Product Name online now'),           'NO_MATCH');
check('adjacent variables bail',        caps($YT, '%%title%%%%sitename%%', 'anything'),                             'BAIL');
check('punctuation-only literals bail', caps($YT, '%%title%% | %%sitename%%', 'A | B'),                             'BAIL');
check('pure structure bails',           caps($YT, '%%title%% %%sep%% %%sitename%%', 'A | B'),                       'BAIL');
check('non-template bails',             caps($YT, 'About our company', 'About our company'),                        'BAIL');
check('repeated var captured twice',    caps($YT, 'Buy %%title%% or %%title%%', 'Buy A or A'),                       '{"v0":["%%title%%","A"],"v1":["%%title%%","A"]}');

// ── inline-tag checks still compose ──────────────────────────────────────────
check('tag error surfaces',     TranslationSubmissionGuard::validate('A {1}B{/1}', 'X Y', $YT), ['missing_marker:close:1', 'missing_marker:open:1']);
check('both error kinds merge', TranslationSubmissionGuard::validate('{1}A{/1} %%title%%', 'B', $YT), ['missing_marker:close:1', 'missing_marker:open:1', 'missing_variable:%%title%%']);

echo $failed === 0 ? "\nAll cases passed.\n" : "\n$failed case(s) failed.\n";

exit($failed === 0 ? 0 : 1);
