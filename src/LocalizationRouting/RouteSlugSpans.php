<?php

namespace LingoWP\LocalizationRouting;

final class RouteSlugSpans
{
    public const CORE_SLUG_VARS = [
        'pagename',
        'name',
        'attachment',
        'category_name',
        'tag',
        'term',
    ];

    private function __construct() {}

    public static function find(string $path, array $rules, array $slugVars): array
    {
        $subject = ltrim($path, '/');
        if ($subject === '' || $rules === [] || $slugVars === []) {
            return [];
        }

        $offset = strlen($path) - strlen($subject);

        foreach ($rules as $regex => $query) {
            if (!is_string($regex) || !is_string($query)) {
                continue;
            }

            if (preg_match('#^' . $regex . '#', $subject, $matches, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }

            $slugIndexes = self::slugCaptureIndexes($query, $slugVars);
            if ($slugIndexes === []) {
                return [];
            }

            $spans = [];
            foreach ($slugIndexes as $index) {
                if (!isset($matches[$index]) || $matches[$index][1] < 0 || $matches[$index][0] === '') {
                    continue;
                }

                $spans[] = [$offset + $matches[$index][1], strlen($matches[$index][0])];
            }

            usort($spans, static fn(array $a, array $b): int => $b[0] <=> $a[0]);

            return $spans;
        }

        return [];
    }

    private static function slugCaptureIndexes(string $query, array $slugVars): array
    {
        $position = strpos($query, '?');
        if ($position === false) {
            return [];
        }

        $indexes = [];

        foreach (explode('&', substr($query, $position + 1)) as $pair) {
            $parts = explode('=', $pair, 2);
            if (count($parts) !== 2) {
                continue;
            }

            [$var, $value] = $parts;
            if (!isset($slugVars[$var])) {
                continue;
            }

            if (preg_match('/^\$matches\[([0-9]+)\]$/', $value, $m) === 1) {
                $indexes[] = (int) $m[1];
            }
        }

        return $indexes;
    }
}
