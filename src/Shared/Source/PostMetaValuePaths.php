<?php

namespace LingoWP\Shared\Source;

/**
 * Flattens a post meta value into its translatable string leaves with a context
 * path (data pipeline §1 row 8). One meta row can hold many translatable strings
 * (ACF repeaters, serialized arrays, JSON blobs); each leaf becomes its own
 * source, distinguished by the dot-path context (e.g. "slides.0.title").
 * patch() is the inverse, used at render time: given the same raw value and a
 * context => translated-text map, it rebuilds the value with each translated
 * leaf swapped in place.
 *
 * Pure and deterministic — no WordPress, fully unit-testable.
 */
final class PostMetaValuePaths
{
    private function __construct() {}

    /**
     * @param mixed $value
     * @return list<array{context: ?string, text: string}>
     *         A scalar string yields one entry with context = null; arrays /
     *         JSON yield one entry per translatable string leaf with its path.
     */
    public static function extract($value): array
    {
        if (is_string($value)) {
            $decoded = self::decodeJson($value);
            if ($decoded !== null) {
                return self::walk($decoded, '');
            }

            return self::isTranslatable($value) ? [['context' => null, 'text' => $value]] : [];
        }

        if (is_array($value)) {
            return self::walk($value, '');
        }

        // Numbers, booleans, null, objects: nothing translatable.
        return [];
    }

    /** Decode only strings that actually look like a JSON array/object. */
    private static function decodeJson(string $value): ?array
    {
        $trimmed = ltrim($value);
        if ($trimmed === '' || ($trimmed[0] !== '{' && $trimmed[0] !== '[')) {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<array-key, mixed> $value
     * @return list<array{context: ?string, text: string}>
     */
    private static function walk(array $value, string $prefix): array
    {
        $leaves = [];

        foreach ($value as $key => $item) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (is_array($item)) {
                array_push($leaves, ...self::walk($item, $path));
            } elseif (is_string($item) && self::isTranslatable($item)) {
                $leaves[] = ['context' => $path, 'text' => $item];
            }
        }

        return $leaves;
    }

    /** Non-empty and contains at least one letter (skips IDs, numbers, separators). */
    private static function isTranslatable(string $text): bool
    {
        return trim($text) !== '' && preg_match('/\p{L}/u', $text) === 1;
    }

    /**
     * Inverse of extract(): a copy of $value with every leaf that has a
     * translation replaced, using the exact same dot-path walk as walk() so
     * paths always round-trip. Untranslated leaves keep their original text.
     *
     * @param mixed                $value        the raw value, as extract() would have seen it
     * @param array<string,string> $translations context ('' for extract()'s null/whole-value
     *                                            case) => translated text
     * @return mixed same shape as $value
     */
    public static function patch($value, array $translations)
    {
        if ($translations === []) {
            return $value;
        }

        // Exactly one entry keyed '': extract() returned a single whole-value
        // leaf (a plain scalar), so the translation replaces $value directly —
        // no walk needed.
        if (count($translations) === 1 && array_key_exists('', $translations)) {
            return $translations[''];
        }

        if (is_string($value)) {
            $decoded = self::decodeJson($value);

            return $decoded !== null ? json_encode(self::patchArray($decoded, $translations, '')) : $value;
        }

        return is_array($value) ? self::patchArray($value, $translations, '') : $value;
    }

    /**
     * @param array<array-key, mixed> $value
     * @param array<string, string>   $translations
     * @return array<array-key, mixed>
     */
    private static function patchArray(array $value, array $translations, string $prefix): array
    {
        foreach ($value as $key => $item) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (is_array($item)) {
                $value[$key] = self::patchArray($item, $translations, $path);
            } elseif (isset($translations[$path])) {
                $value[$key] = $translations[$path];
            }
        }

        return $value;
    }
}
