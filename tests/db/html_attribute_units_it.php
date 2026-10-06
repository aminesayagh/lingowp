<?php

/**
 * Text inside HTML attributes (alt, title, aria-label, placeholder, button
 * value) — each value is its own hash-keyed unit, in post content and in
 * template HTML alike.
 * Run: wp eval-file tests/db/html_attribute_units_it.php
 */

require __DIR__ . '/_harness.php';

use LingoWP\Shared\Parsing\HtmlUnitExtractor;
use LingoWP\Shared\Parsing\TranslationUnitCodec;

test_guard();
test_begin();

$extractor = new HtmlUnitExtractor(new TranslationUnitCodec());

// ── 1. Extractor: which attributes become units ─────────────────────────────

test_section('extractor: allow-listed attributes become units:');

$units = $extractor->units(implode('', [
    '<figure><img src="/a.jpg" alt="A dog on the beach"></figure>',
    '<p><a href="/x" title="Read the full story">More</a></p>',
    '<button aria-label="Close the menu">×</button>',
    '<form><input type="search" placeholder="Search the shop">',
    '<input type="submit" value="Send message">',
    '<input type="image" src="/go.png" alt="Go there">',
    '<textarea placeholder="Your comment here"></textarea></form>',
    '<iframe src="https://example.com/v" title="Product video"></iframe>',
]));

foreach (['A dog on the beach', 'Read the full story', 'Close the menu', 'Search the shop',
    'Send message', 'Go there', 'Your comment here', 'Product video'] as $expected) {
    test_ok("'{$expected}' is a unit", in_array($expected, $units, true), 'got ' . json_encode($units));
}
test_ok('the paragraph text is still its own unit', in_array('More', $units, true), 'got ' . json_encode($units));

test_section('extractor: machine values and non-text attributes are skipped:');

$skipped = $extractor->units(implode('', [
    '<img src="/a.jpg" alt="IMG_1234.jpg">',
    '<img src="/b.jpg" alt="https://example.com/b.jpg">',
    '<img src="/c.jpg" alt="">',
    '<img src="/d.jpg" alt="2024">',
    '<input type="text" value="Prefilled user data">',
    '<input type="hidden" value="Hidden token text">',
    '<input type="text" alt="Not an image input">',
    '<div placeholder="Not a form field">x</div>',
    '<span data-title="Data attributes are not visible">y</span>',
    '<p class="notranslate"><img alt="Opted out alt" src="/e.jpg"></p>',
    '<div translate="no"><a title="Opted out title" href="/">z</a></div>',
    '<script title="Script title"></script>',
]));

foreach (['IMG_1234.jpg', 'https://example.com/b.jpg', '2024', 'Prefilled user data', 'Hidden token text',
    'Not an image input', 'Not a form field', 'Data attributes are not visible', 'Opted out alt',
    'Opted out title', 'Script title'] as $unexpected) {
    test_ok("'{$unexpected}' is not a unit", !in_array($unexpected, $skipped, true), 'got ' . json_encode($skipped));
}
test_ok('an empty alt is not a unit', !in_array('', $skipped, true), 'got ' . json_encode($skipped));

test_section('extractor: whitespace in an attribute is tidied like a block unit:');

test_ok('runs collapse, ends trim', in_array('A tidy title', $extractor->units("<a href=\"/\" title=\"  A\n   tidy\ttitle \">t</a>"), true));

// ── 2. Extractor render: attributes are replaced, block text too ────────────

test_section('extractor render: attribute translations are applied:');

$map = [
    'A dog on the beach'           => 'Un chien sur la plage',
    'Look at {1/} this dog'        => 'Regardez {1/} ce chien',
    'Send message'                 => 'Envoyer le message',
    'Tom & "Jerry"'                => 'Tom & "Jerry" onerror="alert(1)',
];
$translate = static fn(string $unit): ?string => $map[$unit] ?? null;

$out = $extractor->render('<p>Look at <img src="/dog.jpg" alt="A dog on the beach"> this dog</p>', $translate);
test_ok('an <img alt> inside a translated paragraph keeps the translated alt',
    strpos($out, 'alt="Un chien sur la plage"') !== false && strpos($out, 'Regardez') !== false, $out);

$out = $extractor->render('<form><input type="submit" value="Send message"></form>', $translate);
test_ok('a submit button value is translated', strpos($out, 'value="Envoyer le message"') !== false, $out);

$out = $extractor->render('<img src="/x.jpg" alt="Tom &amp; &quot;Jerry&quot;">', $translate);
// The real risk inside an attribute is a quote closing it early; `<` inside a
// quoted value is inert in HTML5, so the serializer rightly leaves it alone.
test_ok('entities decode for the lookup and a quote in the translation cannot close the attribute',
    strpos($out, '" onerror=') === false && strpos($out, 'Tom &amp; &quot;Jerry&quot;') !== false, $out);

$out = $extractor->render('<img src="/x.jpg" alt="Untranslated alt">', $translate);
test_ok('an attribute with no translation is left exactly as-is', strpos($out, 'alt="Untranslated alt"') !== false, $out);

test_done();
