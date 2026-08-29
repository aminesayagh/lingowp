<?php

/**
 * Standalone check for OptionSourceScanner::looksTranslatable() — no WP, no DB.
 * Run: php tests/option_gate_check.php   (needs composer vendor/ for autoload)
 * Exits non-zero if any case fails. Mirrors §"Phase 1" of
 * plan-database-pipeline-verification.md.
 *
 * looksTranslatable() is a cheap PRE-filter for the recursive walk over
 * theme_mods / widget option values. It is deliberately stricter than
 * TranslatableText::isTranslatable(), which remains the real chokepoint in
 * insertParents(). The divergence table at the bottom pins that relationship.
 *
 * The method is private and the constructor needs $wpdb, so it is reached via
 * reflection on an uninitialized instance — no WordPress required.
 */

require __DIR__ . '/../vendor/autoload.php';

use LingoWP\Shared\Text\TranslatableText;
use LingoWP\Extraction\OptionSourceScanner;

$scanner = (new ReflectionClass(OptionSourceScanner::class))->newInstanceWithoutConstructor();
$method  = new ReflectionMethod(OptionSourceScanner::class, 'looksTranslatable');
$method->setAccessible(true);

$looks = static fn(string $t): bool => $method->invoke($scanner, $t);

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

echo "looksTranslatable — rejected:\n";

$rejected = [
    ['empty',                  ''],
    ['whitespace only',        '   '],
    ['single char (len < 2)',  'a'],
    ['bare number',            '5'],
    ['css px',                 '12px'],
    ['css rem',                '1.5rem'],
    ['percentage',             '80%'],
    ['hex colour short',       '#fff'],
    ['hex colour long',        '#abcdef12'],
    ['html markup',            '<p>Hi</p>'],
    ['block comment markup',   '<!-- wp:paragraph -->'],
    ['closing tag',            '</div>'],
    ['absolute path',          '/wp-content/x'],
    ['url',                    'https://x.com'],
    ['email',                  'a@b.com'],
    ['no letters',             '→→'],
];

foreach ($rejected as [$label, $text]) {
    ok("rejects $label", $looks($text) === false, 'got true for ' . json_encode($text));
}

echo "\nlooksTranslatable — accepted:\n";

$accepted = [
    ['short word',            'Ok'],
    ['accented word',         'Café'],
    ['padded text',           '  Hi  '],
    ['hyphenated slug-ish',   'footer-text'],
    ['underscored key-ish',   'widget_title'],
    ['sentence',              'Read more about us'],
    ['text containing a url', 'Visit https://x.com today'],
];

foreach ($accepted as [$label, $text]) {
    ok("accepts $label", $looks($text) === true, 'got false for ' . json_encode($text));
}

// ── Divergence: looksTranslatable is the STRICTER pre-filter ─────────────────
// These pass the real chokepoint but are dropped early by the option walk.
// If this table ever inverts, the option scanner has become more permissive
// than the gate that guards every parent table — which would be a bug.

echo "\ndivergence (stricter than the real chokepoint):\n";

$divergent = [
    'a',            // 1 char: isTranslatable allows, looksTranslatable needs >= 2
    '<p>Hi</p>',    // markup: isTranslatable sees letters, looksTranslatable drops tags
    '#fff',         // hex: no letters after the hash for isTranslatable? -> see below
];

foreach ($divergent as $text) {
    $strict  = $looks($text);
    $chokept = TranslatableText::isTranslatable($text);
    ok(
        'looksTranslatable is not more permissive: ' . json_encode($text),
        !($strict === true && $chokept === false),
        'looks=' . var_export($strict, true) . ' chokepoint=' . var_export($chokept, true)
    );
}

ok('1-char: chokepoint allows, option gate rejects',
    TranslatableText::isTranslatable('a') === true && $looks('a') === false);
ok('markup: chokepoint allows, option gate rejects',
    TranslatableText::isTranslatable('<p>Hi</p>') === true && $looks('<p>Hi</p>') === false);

echo $failed === 0
    ? "\nAll $total assertions passed.\n"
    : "\n$failed of $total assertion(s) failed.\n";

exit($failed === 0 ? 0 : 1);
