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

use LingoWP\Shared\Parsing\HtmlUnitExtractor;
use LingoWP\Shared\Parsing\TranslationUnitCodec;
use LingoWP\Shared\Text\TranslationKey;

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
    TranslationKey::currentHash($units[0]) === TranslationKey::currentHash('About Us'));
ok('distinct pieces hash differently',
    TranslationKey::currentHash($units[0]) !== TranslationKey::currentHash($units[1]));

// 4. A round-trip through render() with a no-op translator is lossless.
$identity = $ex->render($html, static fn(string $u): ?string => null);
ok('no-op render preserves both units', $ex->units($identity) === $units,
    'got ' . json_encode($ex->units($identity)));

// 5. Checklist §3 (media edge cases): SVG with <title>/<text> inside — pruned
// outright (PRUNE_TAGS), same as <script>/<canvas>. Only the two surrounding
// paragraphs are real units; nothing from the svg subtree leaks out.
$svgHtml = '<p>Before the svg</p>'
    . '<svg><title>svg title text</title><text>svg text content</text></svg>'
    . '<p>After the svg</p>';
$svgUnits = $ex->units($svgHtml);
ok('svg subtree contributes no units',
    $svgUnits === ['Before the svg', 'After the svg'],
    'got ' . json_encode($svgUnits));

// 6. Checklist §3: a gallery block's per-image captions are distinct units,
// not merged or deduped — each <figcaption> is its own block-level leaf,
// independent of how many <figure> wrappers it's nested inside.
$galleryHtml = '<figure class="wp-block-gallery">'
    . '<figure class="wp-block-image"><img src="a.jpg" alt=""/><figcaption>First gallery caption</figcaption></figure>'
    . '<figure class="wp-block-image"><img src="b.jpg" alt=""/><figcaption>Second gallery caption</figcaption></figure>'
    . '</figure>';
$galleryUnits = $ex->units($galleryHtml);
ok('gallery produces one unit per caption, not merged',
    $galleryUnits === ['First gallery caption', 'Second gallery caption'],
    'got ' . json_encode($galleryUnits));

// 7. Checklist §3: an inline <img>'s alt/title attributes never become their
// own unit and never leak into the surrounding block's unit text — that text
// belongs to the attachment's own fields, not to every place the image is
// embedded. <img> is a VOID element (TranslationUnitCodec::VOID): it becomes
// a bare {n/} marker, carrying no text into the unit string.
$imgHtml = '<p>Intro text <img src="a.jpg" alt="inline alt text" title="inline title text"/> more text</p>';
$imgUnits = $ex->units($imgHtml);
ok('inline img alt/title text never appears in the unit',
    count($imgUnits) === 1
        && !str_contains($imgUnits[0], 'inline alt text')
        && !str_contains($imgUnits[0], 'inline title text'),
    'got ' . json_encode($imgUnits));
ok('the img becomes a bare void marker, not a text-bearing tag pair',
    $imgUnits[0] === 'Intro text {1/} more text',
    'got ' . json_encode($imgUnits));

echo $failed === 0
    ? "\nAll $total assertions passed.\n"
    : "\n$failed of $total assertion(s) failed.\n";

exit($failed === 0 ? 0 : 1);
