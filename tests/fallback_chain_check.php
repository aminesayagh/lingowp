<?php

/**
 * Standalone check for OptionLanguageRegistry::fallbackChain() — no WP, no DB.
 * Run: php tests/fallback_chain_check.php   (needs composer vendor/ for autoload)
 *
 * fallbackChain() is the core algorithm behind the fallback-language feature:
 * given a requested language, it returns the ordered list of other languages
 * to try before the caller's normal "no row found" path renders the
 * untranslated source. It reads its data through get_option() (WP's own
 * options API), so this stubs that one function backed by an in-memory array
 * instead of requiring a WordPress bootstrap.
 */

require __DIR__ . '/../vendor/autoload.php';

use LingoWP\Language\Infrastructure\OptionLanguageRegistry;

// ── get_option() stub ────────────────────────────────────────────────────────
// LanguageMetadataStore::all()/get() and OptionLanguageRegistry::getDefaultLanguage()
// only ever READ options in the code paths fallbackChain() touches, so a bare
// in-memory map is enough — no update_option()/current_time() stub needed.

$GLOBALS['test_test_options'] = [
    'lingowp_source_locale' => 'en_US',
    'lingowp_languages'     => [],
];

function get_option(string $name, $default = false)
{
    return $GLOBALS['test_test_options'][$name] ?? $default;
}

/** Seed one language's metadata entry directly (bypassing LanguageMetadataStore::save(),
 *  which needs current_time()/update_option() — this test only reads). */
function seed_language(string $code, string $fallback): void
{
    $GLOBALS['test_test_options']['lingowp_languages'][$code] = [
        'code'              => $code,
        'fallback_language' => $fallback,
    ];
}

$failed = 0;
$total  = 0;

function ok(string $label, bool $cond, string $detail = ''): void
{
    global $failed, $total;
    $total++;
    if ($cond) {
        echo "PASS  $label\n";
        return;
    }
    $failed++;
    echo "FAIL  $label\n";
    if ($detail !== '') {
        echo "        $detail\n";
    }
}

$registry = new OptionLanguageRegistry();
$dump     = static fn(array $chain): string => '[' . implode(', ', $chain) . ']';

echo "fallbackChain — basic chain:\n";

seed_language('fr_FR', '');
ok('no fallback configured → empty chain',
    $registry->fallbackChain('fr_FR') === [],
    'got ' . $dump($registry->fallbackChain('fr_FR')));

seed_language('es_ES', 'fr_FR');
ok('one hop → [fr_FR]',
    $registry->fallbackChain('es_ES') === ['fr_FR'],
    'got ' . $dump($registry->fallbackChain('es_ES')));

seed_language('it_IT', 'es_ES'); // it_IT -> es_ES -> fr_FR
ok('two hops → [es_ES, fr_FR]',
    $registry->fallbackChain('it_IT') === ['es_ES', 'fr_FR'],
    'got ' . $dump($registry->fallbackChain('it_IT')));

echo "\nfallbackChain — safety guards:\n";

seed_language('aa_AA', 'aa_AA'); // self-reference
ok('self-reference → empty chain (no infinite loop)',
    $registry->fallbackChain('aa_AA') === [],
    'got ' . $dump($registry->fallbackChain('aa_AA')));

seed_language('bb_BB', 'cc_CC');
seed_language('cc_CC', 'bb_BB'); // bb_BB -> cc_CC -> bb_BB (cycle)
ok('cycle stops instead of looping forever',
    $registry->fallbackChain('bb_BB') === ['cc_CC'],
    'got ' . $dump($registry->fallbackChain('bb_BB')));

seed_language('dd_DD', 'en_US'); // fallback points straight at the default/source language
ok('fallback pointing at the default language is excluded (nothing to query there)',
    $registry->fallbackChain('dd_DD') === [],
    'got ' . $dump($registry->fallbackChain('dd_DD')));

seed_language('ee_EE', 'ff_FF'); // ff_FF has no metadata entry at all
ok('fallback pointing at an unknown/never-added code stops there',
    $registry->fallbackChain('ee_EE') === ['ff_FF'],
    'got ' . $dump($registry->fallbackChain('ee_EE')));

echo $failed === 0
    ? "\nAll $total assertions passed.\n"
    : "\n$failed of $total assertion(s) failed.\n";

exit($failed === 0 ? 0 : 1);
