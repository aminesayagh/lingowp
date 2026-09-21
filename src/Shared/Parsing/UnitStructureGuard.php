<?php

namespace LingoWP\Shared\Parsing;

final class UnitStructureGuard
{
    private const MARKER = '/\{(\/?)(\d+)(\/?)\}/';

    public static function validate(string $source, string $submission): array
    {
        $src = self::markers($source);
        if ($src === []) {
            return [];
        }

        $sub    = self::markers($submission);
        $errors = [];

        $cs = self::countByKey($src);
        $cu = self::countByKey($sub);
        foreach ($cs as $k => $v) {
            if (($cu[$k] ?? 0) < $v) {
                $errors[] = "missing_marker:$k";
            }
        }
        foreach ($cu as $k => $v) {
            if (($cs[$k] ?? 0) < $v) {
                $errors[] = "extra_marker:$k";
            }
        }

        if ($errors === []) {
            $stack = [];
            foreach ($sub as [$kind, $n]) {
                if ($kind === 'open') {
                    $stack[] = $n;
                } elseif ($kind === 'close') {
                    if (end($stack) === $n) {
                        array_pop($stack);
                    } else {
                        $errors[] = "crossed_marker:$n";
                        break;
                    }
                }
            }
            if ($errors === [] && $stack !== []) {
                $errors[] = 'unclosed_marker:' . end($stack);
            }
        }

        return $errors;
    }

    private static function countByKey(array $markers): array
    {
        $counts = [];
        foreach ($markers as [$kind, $n]) {
            $counts["$kind:$n"] = ($counts["$kind:$n"] ?? 0) + 1;
        }

        return $counts;
    }

    private static function markers(string $s): array
    {
        preg_match_all(self::MARKER, $s, $matches, PREG_SET_ORDER);
        $out = [];
        foreach ($matches as $m) {
            $kind  = $m[1] === '/' ? 'close' : ($m[3] === '/' ? 'void' : 'open');
            $out[] = [$kind, (int) $m[2]];
        }

        return $out;
    }
}
