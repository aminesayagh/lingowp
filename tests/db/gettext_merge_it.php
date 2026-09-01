<?php

/**
 * GettextOnlineTranslationFetcher internals for the language-first "find
 * online" flow: pickPackages() (which package is the region's own, which is
 * the language's default region) and mergeMo() (own strings win, default
 * region fills the gaps).
 * Run: wp eval-file tests/db/gettext_merge_it.php
 *
 * READ ONLY — no transaction, writes nothing. Both methods are pure; they
 * only need a WP bootstrap for core's bundled \MO / \Translation_Entry and
 * for LocaleNormalizer autoloading. Reached via reflection so they stay
 * private.
 */

require __DIR__ . '/_harness.php';

use LingoWP\GettextDomains\GettextOnlineTranslationFetcher;
use LingoWP\GettextDomains\GettextPoImporter;

test_guard();
// No test_begin(): this file only computes.

if (! class_exists('MO')) {
    require_once ABSPATH . WPINC . '/pomo/mo.php';
}

/** Build .mo bytes from a [key => translation] map; '' context unless key has "\x04". */
function test_mo_bytes(array $entries, array $headers = []): string
{
    $mo = new \MO();
    if ($headers !== []) {
        $mo->set_headers($headers);
    }
    foreach ($entries as $key => $translation) {
        $context  = null;
        $singular = $key;
        if (strpos($key, "\x04") !== false) {
            [$context, $singular] = explode("\x04", $key, 2);
        }
        $mo->add_entry(new \Translation_Entry([
            'singular'     => $singular,
            'context'      => $context,
            'translations' => [$translation],
        ]));
    }

    return $mo->export();
}

function test_mo_load(string $bytes): \MO
{
    $tmp = wp_tempnam('merge-assert.mo');
    file_put_contents($tmp, $bytes);
    $mo = new \MO();
    $mo->import_from_file($tmp);
    @unlink($tmp);

    return $mo;
}

/** Parse .mo bytes back to [Translation_Entry::key() => translation]. */
function test_mo_parse(string $bytes): array
{
    $out = [];
    foreach (test_mo_load($bytes)->entries as $key => $entry) {
        $out[$key] = (string) ($entry->translations[0] ?? '');
    }

    return $out;
}

$fetcher = new GettextOnlineTranslationFetcher(new GettextPoImporter());

$mergeMo = new \ReflectionMethod($fetcher, 'mergeMo');
$mergeMo->setAccessible(true);
$pickPackages = new \ReflectionMethod($fetcher, 'pickPackages');
$pickPackages->setAccessible(true);

// ── mergeMo() ───────────────────────────────────────────────────────────────

$base = test_mo_bytes(
    [
        'A'        => 'base-A',
        'B'        => 'base-B',
        'C'        => 'base-C',
        "ctx\x04M" => 'base-ctxM',
    ],
    ['Language' => 'fr', 'X-Base-Only' => 'yes', 'PO-Revision-Date' => 'base-date']
);
$overlay = test_mo_bytes(
    [
        'A'        => 'ov-A',   // overrides base
        'B'        => '',       // empty — must NOT blank base-B
        'D'        => 'ov-D',   // overlay-only
        "ctx\x04M" => 'ov-ctxM', // context entry overrides independently
    ],
    ['Language' => 'fr_BE', 'PO-Revision-Date' => 'overlay-date']
);

$langDir = WP_LANG_DIR . '/plugins';
$before  = is_dir($langDir) ? scandir($langDir) : [];

$mergedBytes = $mergeMo->invoke($fetcher, $base, $overlay);

$after = is_dir($langDir) ? scandir($langDir) : [];

test_section('mergeMo():');

test_ok('returns a non-empty string', is_string($mergedBytes) && $mergedBytes !== '');

$merged        = test_mo_parse((string) $mergedBytes);
$mergedHeaders = test_mo_load((string) $mergedBytes)->headers;

test_ok('overlay wins for a shared key (A)', ($merged['A'] ?? null) === 'ov-A', 'got ' . json_encode($merged['A'] ?? null));
test_ok('empty overlay entry is skipped, base survives (B)', ($merged['B'] ?? null) === 'base-B', 'got ' . json_encode($merged['B'] ?? null));
test_ok('base-only key survives (C)', ($merged['C'] ?? null) === 'base-C', 'got ' . json_encode($merged['C'] ?? null));
test_ok('overlay-only key survives (D)', ($merged['D'] ?? null) === 'ov-D', 'got ' . json_encode($merged['D'] ?? null));
test_ok('context-keyed entry overlays independently', ($merged["ctx\x04M"] ?? null) === 'ov-ctxM', 'got ' . json_encode($merged["ctx\x04M"] ?? null));
test_ok('a bare "M" key was never created', ! array_key_exists('M', $merged), 'keys: ' . json_encode(array_keys($merged)));

test_ok('overlay header wins (Language)', ($mergedHeaders['Language'] ?? null) === 'fr_BE', 'got ' . json_encode($mergedHeaders['Language'] ?? null));
test_ok('overlay header wins (PO-Revision-Date)', ($mergedHeaders['PO-Revision-Date'] ?? null) === 'overlay-date', 'got ' . json_encode($mergedHeaders['PO-Revision-Date'] ?? null));
test_ok('base-only header survives', ($mergedHeaders['X-Base-Only'] ?? null) === 'yes', 'got ' . json_encode($mergedHeaders['X-Base-Only'] ?? null));

test_ok('mergeMo() wrote nothing to WP_LANG_DIR/plugins', $before === $after,
    'added: ' . json_encode(array_values(array_diff($after, $before))));

// ── pickPackages() ─────────────────────────────────────────────────────────

test_section('pickPackages():');

$frFrOld = ['language' => 'fr_FR', 'package' => 'p-fr', 'updated' => '2024-01-01 00:00:00'];
$frBeNew = ['language' => 'fr_BE', 'package' => 'p-be', 'updated' => '2024-06-01 00:00:00'];
$deDe    = ['language' => 'de_DE', 'package' => 'p-de', 'updated' => '2024-06-01 00:00:00'];

$r = $pickPackages->invoke($fetcher, [$frFrOld, $frBeNew], 'fr_BE');
test_ok('fr_BE: exact is its own package', ($r['exact']['language'] ?? null) === 'fr_BE', json_encode($r['exact'] ?? null));
test_ok('fr_BE: default region excludes self even when self is freshest',
    ($r['default']['language'] ?? null) === 'fr_FR', json_encode($r['default'] ?? null));

$r = $pickPackages->invoke($fetcher, [$frFrOld, $frBeNew], 'fr_CA');
test_ok('fr_CA: no own package', $r['exact'] === null);
test_ok('fr_CA: default region is the freshest sibling',
    ($r['default']['language'] ?? null) === 'fr_BE', json_encode($r['default'] ?? null));

$r = $pickPackages->invoke($fetcher, [$frBeNew], 'fr_BE');
test_ok('sole sibling: exact set', ($r['exact']['language'] ?? null) === 'fr_BE');
test_ok('sole sibling: default falls back to self (nothing else to merge with)',
    ($r['default']['language'] ?? null) === 'fr_BE', json_encode($r['default'] ?? null));

test_ok('no same-base sibling -> null', $pickPackages->invoke($fetcher, [$deDe], 'fr_BE') === null);
test_ok('garbage locale -> null', $pickPackages->invoke($fetcher, [$frFrOld], '') === null);

test_done();
