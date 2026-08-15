<?php

namespace TransLangs\AiTranslation;

/**
 * Groups units by group_id, splits any group over 25 into sub-chunks, then
 * greedily first-fit-packs those sub-chunks into request-level bins of at
 * most 100 items — enforcing the backend's per-request caps (25/group,
 * 100/request) without ever splitting a request's-worth of work more than
 * necessary. Pure function of the unit list; no I/O.
 */
final class TranslationBatchPlanner
{
    private const MAX_PER_GROUP   = 25;
    private const MAX_PER_REQUEST = 100;

    private function __construct()
    {
    }

    /**
     * @param  array<int, array{group_id:string}&array> $units
     * @return array<int, array<int, array>> each element is one bin = one backend request
     */
    public static function plan(array $units): array
    {
        $byGroup = [];
        foreach ($units as $u) {
            $byGroup[$u['group_id']][] = $u;
        }

        // Split any group > 25 into consecutive sub-chunks of <= 25. Reusing the
        // same group_id across the resulting sub-chunks/separate requests is fine
        // — the cap is enforced per request, not globally.
        $cells = [];
        foreach ($byGroup as $groupUnits) {
            foreach (array_chunk($groupUnits, self::MAX_PER_GROUP) as $cell) {
                $cells[] = $cell;
            }
        }

        // Greedy first-fit: a cell (already <=25) always fits a fresh or
        // partially-full bin as long as the bin stays <=100 overall AND this
        // cell's group_id doesn't already have items in that bin pushing its
        // own total past 25 — two cells of the SAME group must never share a
        // bin (that's exactly what would blow the per-group cap back open),
        // but cells from DIFFERENT groups happily share one.
        $bins = [];
        foreach ($cells as $cell) {
            $groupId   = $cell[0]['group_id'];
            $cellCount = count($cell);
            $placed    = false;
            foreach ($bins as $i => $bin) {
                $groupCountInBin = $bin['groupCounts'][$groupId] ?? 0;
                if ($bin['count'] + $cellCount <= self::MAX_PER_REQUEST
                    && $groupCountInBin + $cellCount <= self::MAX_PER_GROUP
                ) {
                    $bins[$i]['items'] = array_merge($bin['items'], $cell);
                    $bins[$i]['count'] += $cellCount;
                    $bins[$i]['groupCounts'][$groupId] = $groupCountInBin + $cellCount;
                    $placed = true;
                    break;
                }
            }
            if (!$placed) {
                $bins[] = ['items' => $cell, 'count' => $cellCount, 'groupCounts' => [$groupId => $cellCount]];
            }
        }

        return array_map(static fn (array $bin): array => $bin['items'], $bins);
    }
}
