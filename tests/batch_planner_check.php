<?php

/**
 * Standalone check for TranslationBatchPlanner — no framework.
 * Run: php tests/batch_planner_check.php
 * Exits non-zero if any case fails.
 */

require __DIR__ . '/../src/AiTranslation/TranslationBatchPlanner.php';

use LingoWP\AiTranslation\TranslationBatchPlanner;

$failed = 0;

function check(string $label, bool $cond, string $detail = ''): void
{
    global $failed;
    if ($cond) {
        echo "ok    $label\n";

        return;
    }
    $failed++;
    echo "FAIL  $label\n";
    if ($detail !== '') {
        echo "        $detail\n";
    }
}

/** @return array<int, array{group_id:string, original:string}> */
function units(string $groupId, int $count, string $prefix = 'u'): array
{
    $out = [];
    for ($i = 0; $i < $count; $i++) {
        $out[] = ['group_id' => $groupId, 'original' => "{$prefix}{$groupId}-{$i}"];
    }

    return $out;
}

function maxGroupInAnyBin(array $bins): int
{
    $max = 0;
    foreach ($bins as $bin) {
        $counts = [];
        foreach ($bin as $u) {
            $counts[$u['group_id']] = ($counts[$u['group_id']] ?? 0) + 1;
        }
        $max = max($max, empty($counts) ? 0 : max($counts));
    }

    return $max;
}

function maxBinSize(array $bins): int
{
    $max = 0;
    foreach ($bins as $bin) {
        $max = max($max, count($bin));
    }

    return $max;
}

// ── boundary: exactly 25 stays in one cell ───────────────────────────────────
$bins = TranslationBatchPlanner::plan(units('g', 25));
check('group of 25 -> 1 bin', count($bins) === 1, 'bins: ' . count($bins));
check('group of 25 -> bin has 25 items', count($bins[0] ?? []) === 25);

// ── group over the per-group cap splits, never exceeds 25 in one bin ─────────
$bins = TranslationBatchPlanner::plan(units('g', 26));
check('group of 26 -> group_id never exceeds 25 in any bin', maxGroupInAnyBin($bins) <= 25, 'max: ' . maxGroupInAnyBin($bins));
$total = array_sum(array_map('count', $bins));
check('group of 26 -> all 26 items preserved', $total === 26, 'total: ' . $total);

// ── many small groups pack into one bin when they fit ────────────────────────
$units = [];
for ($i = 0; $i < 10; $i++) {
    $units = array_merge($units, units("g{$i}", 5));
}
$bins = TranslationBatchPlanner::plan($units);
check('10 groups of 5 (=50) -> 1 bin', count($bins) === 1, 'bins: ' . count($bins));

// ── forced to span exactly 2 bins: 4x25 (=100, fills bin 1) + 1 more ─────────
$units = [];
for ($i = 0; $i < 4; $i++) {
    $units = array_merge($units, units("g{$i}", 25));
}
$units = array_merge($units, units('g4', 1));
$bins = TranslationBatchPlanner::plan($units);
check('4x25 + 1 -> 2 bins', count($bins) === 2, 'bins: ' . count($bins));
check('4x25 + 1 -> bin 1 has exactly 100', count($bins[0] ?? []) === 100, 'bin 1: ' . count($bins[0] ?? []));

// ── general invariants across edge shapes ────────────────────────────────────
$shapes = [
    'one group of 200'      => units('big', 200),
    '150 singleton groups'  => array_merge(...array_map(static fn (int $i) => units("s{$i}", 1), range(0, 149))),
];
foreach ($shapes as $label => $shapeUnits) {
    $bins = TranslationBatchPlanner::plan($shapeUnits);
    check("$label: no bin exceeds 100", maxBinSize($bins) <= 100, 'max bin: ' . maxBinSize($bins));
    check("$label: no group exceeds 25 in any bin", maxGroupInAnyBin($bins) <= 25, 'max group in bin: ' . maxGroupInAnyBin($bins));
}

// ── empty input ───────────────────────────────────────────────────────────────
check('empty input -> empty bins', TranslationBatchPlanner::plan([]) === []);

// ── order preservation within a group's cell ─────────────────────────────────
$bins = TranslationBatchPlanner::plan(units('g', 3));
$order = array_map(static fn (array $u) => $u['original'], $bins[0] ?? []);
check('order preserved within a cell', $order === ['ug-0', 'ug-1', 'ug-2'], json_encode($order));

echo $failed === 0 ? "\nAll cases passed.\n" : "\n$failed case(s) failed.\n";

exit($failed === 0 ? 0 : 1);
