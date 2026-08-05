<?php

/**
 * Standalone check for UnitStructureGuard — no framework.
 * Run: php tests/unit_structure_guard_check.php
 * Exits non-zero if any case fails. Mirrors the §2 test table of
 * plan-inline-tag-editor-v1-backend.md.
 */

require __DIR__ . '/../src/Shared/Parsing/UnitStructureGuard.php';

use TransLangs\Shared\Parsing\UnitStructureGuard;

$cases = [
    // [label, source, submission, expected errors (sorted)]
    ['plain reorder-free',      'A {1}B{/1}',        'X {1}Y{/1}',           []],
    ['reorder whole pair',      'A {1}B{/1}',        '{1}Y{/1} X',           []],
    ['both markers dropped',    'A {1}B{/1}',        'X Y',                  ['missing_marker:close:1', 'missing_marker:open:1']],
    ['crossed nesting',         '{1}{2}t{/2}{/1}',   '{1}{2}t{/1}{/2}',      ['crossed_marker:1']],
    ['void reorder',            'A{1/}B',            'B{1/}A',               []],
    ['duplicated pair',         '{1}t{/1}',          '{1}t{/1}{1}u{/1}',     ['extra_marker:close:1', 'extra_marker:open:1']],
    ['empty pair (UI-only)',    '{1}t{/1}',          '{1}{/1}t',             []],
];

$failed = 0;
foreach ($cases as [$label, $source, $submission, $expected]) {
    $got = UnitStructureGuard::validate($source, $submission);
    sort($got);
    sort($expected);
    if ($got === $expected) {
        echo "PASS  $label\n";
    } else {
        $failed++;
        echo "FAIL  $label\n";
        echo "        expected: " . json_encode($expected) . "\n";
        echo "        got:      " . json_encode($got) . "\n";
    }
}

echo $failed === 0
    ? "\nAll " . count($cases) . " cases passed.\n"
    : "\n$failed case(s) failed.\n";

exit($failed === 0 ? 0 : 1);
