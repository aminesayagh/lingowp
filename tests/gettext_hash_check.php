<?php

/**
 * Standalone check for GettextUnitRepository's unit_hash formula — no
 * framework, no DB. Run: php tests/gettext_hash_check.php
 *
 * The one thing that must never regress: this hash is byte-exact and
 * case-sensitive, NOT StringNormalizer::normalize()'d like every other
 * original_hash in this plugin. Normalizing here would silently collide
 * distinct gettext strings ("Save" vs "save") into one row — see
 * GettextUnitRepository::hashOf()'s own doc for why.
 */

require __DIR__ . '/../src/GettextDomains/GettextUnitRepository.php';

use LingoWP\GettextDomains\GettextUnitRepository;

$failed = 0;

function check(string $label, $got, $expected): void
{
    global $failed;
    if ($got === $expected) {
        echo "ok    $label\n";
        return;
    }
    $failed++;
    echo "FAIL  $label\n";
    echo "        expected: " . json_encode($expected) . "\n";
    echo "        got:      " . json_encode($got) . "\n";
}

$hex = static fn (string $msgid, ?string $context) => GettextUnitRepository::hashHex($msgid, $context);

// ── case sensitivity: never normalized ───────────────────────────────────
check('case differs -> different hash', $hex('Save', null) !== $hex('save', null), true);
check('whitespace differs -> different hash', $hex('Save ', null) !== $hex('Save', null), true);

// ── null vs empty-string context agree (both mean "no context") ─────────
check('null context == empty-string context', $hex('x', null), $hex('x', ''));

// ── context is folded in via \x04, matching WP core's Translation_Entry::key() ──
check('context changes the hash', $hex('x', 'ctx') !== $hex('x', null), true);
check('context+msgid vs msgid+context are distinguished by the \x04 separator',
    $hex('a', 'b') !== $hex('ab', null), true);

// ── deterministic / stable ───────────────────────────────────────────────
check('same input -> same hash', $hex('Save', 'button'), $hex('Save', 'button'));
check('hashHex is 64 lowercase hex chars (sha256)', (bool) preg_match('/^[0-9a-f]{64}$/', $hex('x', null)), true);

echo $failed === 0 ? "\nAll cases passed.\n" : "\n$failed case(s) failed.\n";

exit($failed === 0 ? 0 : 1);
