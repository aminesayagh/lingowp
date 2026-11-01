<?php

/**
 * Standalone round-trip check for split-field extraction (split-field spec).
 * Run:  php tests/split_field_check.php   (needs composer vendor/ for Masterminds)
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

// 7. Checklist §3: an inline <img>'s alt/title never leak into the
// surrounding block's unit text — <img> is a VOID element
// (TranslationUnitCodec::VOID): a bare {n/} marker, no text. They ARE their
// own attribute units (HtmlUnitExtractor::walkAttributes()): the image block
// copies the alt into post_content at insert time and WordPress never re-reads
// the attachment's alt for it, so the attachment's own translated alt never
// reaches an embedded copy.
$imgHtml = '<p>Intro text <img src="a.jpg" alt="inline alt text" title="inline title text"/> more text</p>';
$imgUnits = $ex->units($imgHtml);
ok('inline img alt/title never leak into the paragraph unit, and are their own units',
    $imgUnits === ['Intro text {1/} more text', 'inline alt text', 'inline title text'],
    'got ' . json_encode($imgUnits));
ok('the img becomes a bare void marker, not a text-bearing tag pair',
    $imgUnits[0] === 'Intro text {1/} more text',
    'got ' . json_encode($imgUnits));

// 8. Checklist §5 (Gutenberg): a Table block's thead/tbody cells each become
// their own unit, not one giant merged blob (thead/tbody weren't recognized
// as block containers, so hasChildBlock(table) missed them entirely).
$tableHtml = '<table><thead><tr><th>Header One</th><th>Header Two</th></tr></thead>'
    . '<tbody><tr><td>Cell One</td><td>Cell Two</td></tr></tbody></table>';
$tableUnits = $ex->units($tableHtml);
ok('table cells split one-per-unit across thead and tbody',
    $tableUnits === ['Header One', 'Header Two', 'Cell One', 'Cell Two'],
    'got ' . json_encode($tableUnits));

// 9. Checklist §5: a Quote's <cite> and a Details block's <summary> are
// standalone text tags — extracted as their own unit when they're a sibling
// of a real block (blockquote/details), not silently dropped.
$quoteUnits = $ex->units('<blockquote><p>Quoted text.</p><cite>Citation Author</cite></blockquote>');
ok('quote citation extracted as its own unit',
    $quoteUnits === ['Quoted text.', 'Citation Author'], 'got ' . json_encode($quoteUnits));

$detailsUnits = $ex->units('<details><summary>Summary text</summary><p>Body text.</p></details>');
ok('details summary extracted as its own unit',
    $detailsUnits === ['Summary text', 'Body text.'], 'got ' . json_encode($detailsUnits));

// 9b. REGRESSION GUARD: the fix above must NOT change how cite/summary behave
// as an ordinary inline piece of an ALREADY-recognized leaf paragraph — a naive
// "just add cite to BLOCK_TAGS" fix would make hasChildBlock($p) see cite as a
// block child, turn <p> into a container, and silently drop its bare
// surrounding text nodes (only wrapped elements survive container recursion).
$pWithCiteUnits = $ex->units('<p>Text before <cite>Source</cite> text after.</p>');
ok('cite as one inline piece of a normal paragraph still produces ONE unit (no regression)',
    $pWithCiteUnits === ['Text before {1}Source{/1} text after.'],
    'got ' . json_encode($pWithCiteUnits));

// 10. Checklist §5: a Verse block's <pre> is real prose (a poem), not code —
// exempted from PRUNE_TAGS by its own class, with internal newlines preserved
// (white-space: pre-wrap — they're the poem's actual line breaks, not
// incidental whitespace tidy() would otherwise collapse away).
$verseHtml = "<pre class=\"wp-block-verse\">Line one.\nLine two.</pre>";
$verseUnits = $ex->units($verseHtml);
ok('verse block is discovered with its real line break preserved',
    $verseUnits === ["Line one.\nLine two."], 'got ' . json_encode($verseUnits));

$verseRendered = $ex->render($verseHtml, static fn(string $u): ?string => "Ligne un.\nLigne deux.");
ok('verse translation round-trip preserves real line breaks in the rendered output',
    str_contains($verseRendered, "Ligne un.\nLigne deux."), $verseRendered);

// 10b. REGRESSION GUARD: Code and Preformatted blocks share the bare <pre> tag
// with Verse but must stay fully excluded — the exemption is keyed on the
// wp-block-verse CSS class, never the tag alone.
$codeAndPreformatted = '<pre class="wp-block-code"><code>code_should_not_translate();</code></pre>'
    . '<pre class="wp-block-preformatted">preformatted should not translate.</pre>';
ok('code and preformatted blocks remain fully excluded (no regression)',
    $ex->units($codeAndPreformatted) === []);

echo $failed === 0
    ? "\nAll $total assertions passed.\n"
    : "\n$failed of $total assertion(s) failed.\n";

exit($failed === 0 ? 0 : 1);
