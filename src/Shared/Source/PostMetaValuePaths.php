<?php

namespace LingoWP\Shared\Source;

final class PostMetaValuePaths
{
    private function __construct() {}

    public static function extract($value): array
    {
        if (is_string($value)) {
            $decoded = self::decodeJson($value);
            if ($decoded !== null) {
                return self::walk($decoded, '');
            }

            return self::isTranslatable($value) ? [['context' => null, 'text' => $value]] : [];
        }

        if (is_array($value) || is_object($value)) {
            return self::walk((array) $value, '');
        }

        return [];
    }

    private static function decodeJson(string $value): ?array
    {
        $trimmed = ltrim($value);
        if ($trimmed === '' || ($trimmed[0] !== '{' && $trimmed[0] !== '[')) {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    private static function walk(array $value, string $prefix): array
    {
        $leaves = [];

        foreach ($value as $key => $item) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (is_array($item) || is_object($item)) {
                array_push($leaves, ...self::walk((array) $item, $path));
            } elseif (is_string($item) && self::isTranslatable($item)) {
                $leaves[] = ['context' => $path, 'text' => $item];
            }
        }

        return $leaves;
    }

    private static function isTranslatable(string $text): bool
    {
        return trim($text) !== '' && preg_match('/\p{L}/u', $text) === 1;
    }

    public static function patch($value, array $translations)
    {
        if ($translations === []) {
            return $value;
        }

        if (count($translations) === 1 && array_key_exists('', $translations)) {
            return $translations[''];
        }

        if (is_string($value)) {
            $decoded = self::decodeJson($value);

            return $decoded !== null ? json_encode(self::patchArray($decoded, $translations, '')) : $value;
        }

        if (is_array($value)) {
            return self::patchArray($value, $translations, '');
        }

        if (is_object($value)) {
            return (object) self::patchArray((array) $value, $translations, '');
        }

        return $value;
    }

    private static function patchArray(array $value, array $translations, string $prefix): array
    {
        foreach ($value as $key => $item) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (is_array($item)) {
                $value[$key] = self::patchArray($item, $translations, $path);
            } elseif (is_object($item)) {
                $value[$key] = (object) self::patchArray((array) $item, $translations, $path);
            } elseif (isset($translations[$path])) {
                $value[$key] = $translations[$path];
            }
        }

        return $value;
    }
}
