<?php

/**
 * Standalone check for the normalization + translatability contract — no framework.
 * Run: php tests/normalizer_gate_check.php
 * Exits non-zero if any case fails. Mirrors §"Phase 1" of
 * plan-database-pipeline-verification.md.
 *
 * These three classes are the foundation every hash in the database rests on.
 * If this file fails, every assertion in the DB integration scripts is lying.
 */

require __DIR__ . '/../src/Core/Text/StringNormalizer.php';
require __DIR__ . '/../src/Core/Text/TL_Key.php';
require __DIR__ . '/../src/Core/Text/TranslatableText.php';

use TransLangs\Core\Text\StringNormalizer;
use TransLangs\Core\Text\TL_Key;
use TransLangs\Core\Text\TranslatableText;

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

// ── A. StringNormalizer — the frozen contract ────────────────────────────────
// Order is load-bearing: strip \r → html_entity_decode → mb_strtolower → trim
// → collapse \s+ to one space.

echo "normalizer frozen contract:\n";

$normCases = [
    // [label, input, expected]
    ['CRLF stripped and collapsed',  "About\r\n  Us",      'about us'],
    ['trim + collapse',              '  About  us  ',      'about us'],
    ['entity decode then lowercase', '&amp;Caf&eacute;',   '&café'],
    ['empty stays empty',            '',                   ''],
    ['whitespace only → empty',      '   ',                ''],
    ['tabs collapse',                "A\t\tB",             'a b'],
];

foreach ($normCases as [$label, $in, $expected]) {
    $got = StringNormalizer::normalize($in);
    ok($label, $got === $expected, 'expected ' . json_encode($expected) . ', got ' . json_encode($got));
}

// ── STEP-ORDER PROOFS. Do not weaken these. ─────────────────────────────────
//
// NOTE: StringNormalizer's docblock claims step 1 exists "before entity decode,
// so &#13; is handled by step 2". That rationale is FALSE. PHP's
// html_entity_decode() follows the HTML5 spec and refuses to decode &#13; (CR),
// while decoding &#10; (LF), &#9; (tab) and &#32; fine. So &#13; passes through
// as literal text and the step 1<->2 order is unobservable through it.
// Verified on PHP 8.5. The real, observable ordering constraints are below.

// 1. Step 1 DELETES \r outright — it never reaches the step-5 collapse, so no
//    space is left behind. Contrast with \n, which does become a space.
ok('step 1: \r is deleted, not spaced', StringNormalizer::normalize("a\rb") === 'ab',
    'got ' . json_encode(StringNormalizer::normalize("a\rb")));
ok('contrast: \n collapses to a space', StringNormalizer::normalize("a\nb") === 'a b',
    'got ' . json_encode(StringNormalizer::normalize("a\nb")));

// 2. Decode (2) MUST precede collapse (5): &#10; becomes a newline, which then
//    has to be collapsed to a single space. Collapse-then-decode leaves "a\nb".
ok('step 2 precedes step 5: &#10; decodes then collapses',
    StringNormalizer::normalize('a&#10;b') === 'a b',
    'got ' . json_encode(StringNormalizer::normalize('a&#10;b')));

// 3. Decode (2) MUST precede lowercase (3): &Eacute; decodes to É, which must
//    then fold to é. Lowercase-then-decode would leave a capital É.
ok('step 2 precedes step 3: &Eacute; decodes then lowercases',
    StringNormalizer::normalize('&Eacute;') === 'é',
    'got ' . json_encode(StringNormalizer::normalize('&Eacute;')));

// 4. Pin the surprising one so nobody "fixes" it by accident: CR entities are
//    NOT decoded and survive verbatim into the hash.
ok('&#13; is NOT decoded (HTML5 spec, PHP behavior)',
    StringNormalizer::normalize('a&#13;b') === 'a&#13;b',
    'got ' . json_encode(StringNormalizer::normalize('a&#13;b')));

// Accents are deliberately KEPT (fr "ou" vs "où"); case is deliberately folded.
ok('accents preserved (ou !== où)', StringNormalizer::normalize('Où') !== StringNormalizer::normalize('Ou'));
ok('case folded (OU === ou)',       StringNormalizer::normalize('OU') === StringNormalizer::normalize('ou'));

// NBSP survives: the collapse regex is /\s+/ with no /u modifier, so it does not
// match U+00A0. Pins current behavior — if NBSP handling is ever added, this
// case turns red on purpose and should then be updated, not deleted.
$nbsp = StringNormalizer::normalize("a\u{00A0}b");
ok('NBSP is not collapsed (no /u modifier)', $nbsp === "a\u{00A0}b", 'got ' . json_encode($nbsp));

// ── B. TL_Key::currentHash ───────────────────────────────────────────────────

echo "\nTL_Key::currentHash:\n";

ok('raw 32 bytes', strlen(TL_Key::currentHash('About Us')) === 32);
ok(
    'is sha256 of the normalized form',
    TL_Key::currentHash('About Us') === hash('sha256', StringNormalizer::normalize('About Us'), true)
);
ok(
    'normalization collides as expected',
    TL_Key::currentHash('About Us') === TL_Key::currentHash("  about\r\nus ")
);
ok(
    'accents do not collide',
    TL_Key::currentHash('Où') !== TL_Key::currentHash('Ou')
);
ok('TL_Key::normalize delegates', TL_Key::normalize('  A B  ') === StringNormalizer::normalize('  A B  '));

// ── C. TranslatableText::isTranslatable — the discovery gate ─────────────────
// A gate, never a transformer. Applied AFTER normalization. Anchored full-string
// matches, so prose that merely CONTAINS a url/email keeps its letters.

echo "\ntranslatability gate:\n";

$gateCases = [
    // [label, input, expected]
    ['empty rejected',                   '',                        false],
    ['whitespace rejected',              '    ',                    false],
    ['marker-only unit rejected',        '{1}{2}{/2}{/1}',          false],
    ['date rejected (no letters)',       '2024-01-01',              false],
    ['currency rejected (no letters)',   '€ 12',                    false],
    ['bare number rejected',             '5',                       false],
    ['arrow rejected',                   '→',                       false],
    ['whole-string url rejected',        'https://x.com/a',         false],
    ['whole-string www url rejected',    'www.example.com',         false],
    ['whole-string email rejected',      'a@b.com',                 false],
    ['filename rejected',                'photo.jpg',               false],
    ['dotted filename rejected',         'my.file.name.pdf',        false],

    ['prose containing a url kept',      'Visit https://x.com/a today', true],
    ['prose containing an email kept',   'Mail us at a@b.com now',      true],
    ['short word kept',                  'Ok',                      true],
    ['single letter kept',               'a',                       true],
    ['accented single letter kept',      'É',                       true],
    ['accented word kept',               'Café',                    true],
];

foreach ($gateCases as [$label, $in, $expected]) {
    $got = TranslatableText::isTranslatable($in);
    ok($label, $got === $expected, 'expected ' . var_export($expected, true) . ', got ' . var_export($got, true));
}

echo $failed === 0
    ? "\nAll $total assertions passed.\n"
    : "\n$failed of $total assertion(s) failed.\n";

exit($failed === 0 ? 0 : 1);
