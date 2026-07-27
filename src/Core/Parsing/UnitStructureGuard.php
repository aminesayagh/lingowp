<?php

namespace TransLangs\Core\Parsing;

/**
 * Enforces that a submitted translation keeps the same inline-tag marker
 * structure as its source unit (parsing spec §5 / inline-tag editor v1).
 *
 * A composed unit stores its inline HTML as numbered markers ({n} open,
 * {/n} close, {n/} void). decode() rebuilds HTML by blind str_replace, so a
 * translation that drops, adds, or crosses markers produces broken markup. The
 * front-end editor mirrors these same rules for live feedback; this class is the
 * enforcement layer — the REST write path and any future MT writer both go
 * through it. It is the PHP mirror of the reference `validateStructure`.
 */
final class UnitStructureGuard
{
    private const MARKER = '/\{(\/?)(\d+)(\/?)\}/';

    /**
     * Validate a submission against its source unit string.
     *
     * @return string[] error codes, empty array means valid. Shapes:
     *   missing_marker:{kind}:{n} / extra_marker:{kind}:{n} (kind carried),
     *   crossed_marker:{n} / unclosed_marker:{n} (id only).
     */
    public static function validate(string $source, string $submission): array
    {
        $src = self::markers($source);
        if ($src === []) {
            return []; // plain unit, nothing to guard
        }

        $sub    = self::markers($submission);
        $errors = [];

        // 1. Multiset equality — same ids and kinds, any order.
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

        // 2. Nesting — only meaningful once the multiset holds. Reordering whole
        //    pairs is allowed; interleaving is not (a close must match the
        //    innermost open). $n is the id of the marker being read.
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

    /** @param array<array{0:string,1:int}> $markers @return array<string,int> */
    private static function countByKey(array $markers): array
    {
        $counts = [];
        foreach ($markers as [$kind, $n]) {
            $counts["$kind:$n"] = ($counts["$kind:$n"] ?? 0) + 1;
        }

        return $counts;
    }

    /** @return array<array{0:string,1:int}> ordered (kind, id) pairs */
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
