<?php

/**
 * Standalone round-trip check for split-field extraction (split-field spec).
 * Run:  php bin/check-split-field.php   (needs composer vendor/ for Masterminds)
 * Exits non-zero if any case fails. No WordPress, no DB.
 *
 * A record is keyed by its identity() columns and hashes the text with
 * currentHash(); determinism of that hash is covered by
 * tests/normalizer_gate_check.php, not here.
 */

declare(strict_types=1);

$base = dirname(__DIR__);
require $base . '/vendor/autoload.php';

use TransLangs\Shared\Parsing\HtmlUnitExtractor;
use TransLangs\Shared\Parsing\TranslationUnitCodec;
use TransLangs\Shared\Text\TL_Key;

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

$ex = new HtmlUnitExtractor(new TranslationUnitCodec());

$html =
    '<!-- wp:paragraph --><p>About Us</p><!-- /wp:paragraph -->'
    . '<!-- wp:paragraph --><p>Get in <a href="/x">touch</a></p><!-- /wp:paragraph -->';

// 1. Discovery extracts each block-level piece; inline tag becomes {1}.
$units = $ex->units($html);
ok('units extracted with inline markers',
    $units === ['About Us', 'Get in {1}touch{/1}'],
    'got ' . json_encode($units));

// 2. Render replaces only the translated piece; scaffolding and siblings
//    untouched; no <html>/<body> wrapper injected into a content fragment.
$out = $ex->render($html, fn(string $u): ?string => $u === 'About Us' ? 'À propos' : null);
ok('render replaced the translated piece', str_contains($out, 'À propos'), $out);
ok('render kept the untranslated sibling', str_contains($out, 'Get in'), $out);
ok('render injected no html wrapper', !str_contains($out, '<html'), $out);

// 3. Extraction is deterministic — the same piece hashes identically, which is
//    what lets discovery and render agree on a row.
ok('same piece hashes identically',
    TL_Key::currentHash($units[0]) === TL_Key::currentHash('About Us'));
ok('distinct pieces hash differently',
    TL_Key::currentHash($units[0]) !== TL_Key::currentHash($units[1]));

// 4. A round-trip through render() with a no-op translator is lossless.
$identity = $ex->render($html, static fn(string $u): ?string => null);
ok('no-op render preserves both units', $ex->units($identity) === $units,
    'got ' . json_encode($ex->units($identity)));

echo $failed === 0
    ? "\nAll $total assertions passed.\n"
    : "\n$failed of $total assertion(s) failed.\n";

exit($failed === 0 ? 0 : 1);
