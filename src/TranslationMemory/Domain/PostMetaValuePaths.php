<?php

namespace TransLangs\TranslationMemory\Domain;

/**
 * Flattens a post meta value into its translatable string leaves with a context
 * path (data pipeline §1 row 8). One meta row can hold many translatable strings
 * (ACF repeaters, serialized arrays, JSON blobs); each leaf becomes its own
 * source, distinguished by the dot-path context (e.g. "slides.0.title").
 *
 * Pure and deterministic — no WordPress, fully unit-testable.
 */
final class PostMetaValuePaths
{
    private function __construct() {}

    /**
     * @return list<array{context: ?string, text: string}>
     *         A scalar string yields one entry with context = null; arrays /
     *         JSON yield one entry per translatable string leaf with its path.
     */
    public static function extract(mixed $value): array
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
}
