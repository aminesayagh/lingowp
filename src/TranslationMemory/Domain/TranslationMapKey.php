<?php

namespace TransLangs\TranslationMemory\Domain;

/**
 * Builds the in-memory preload map keys for each translation table.
 *
 * All hash parameters are raw SHA-256 bytes (binary(32)).
 * bin2hex() is applied here; callers pass raw bytes from the hasher.
 *
 * Key shapes:
 *   post     -> "field:<field>:<original_hash_hex>"
 *   postmeta -> "meta:<meta_key_hash_hex>:<context_hash_hex>:<original_hash_hex>"
 *   terms    -> "term:<term_taxonomy_id>:<field>:<original_hash_hex>"
 *   i18n     -> "i18n:<source>:<reference_hash_hex>:<original_hash_hex>"
 */
final class TranslationMapKey
{
    private function __construct() {}

    public static function forPost(string $field, string $originalHashRaw): string
    {
        return 'field:' . $field . ':' . bin2hex($originalHashRaw);
    }

    /**
     * @param string $contextHashRaw sha256('') for flat meta — never null.
     */
    public static function forPostmeta(
        string $metaKeyHashRaw,
        string $contextHashRaw,
        string $originalHashRaw
    ): string {
        return 'meta:'
            . bin2hex($metaKeyHashRaw) . ':'
            . bin2hex($contextHashRaw) . ':'
            . bin2hex($originalHashRaw);
    }

    public static function forTerm(int $termTaxonomyId, string $field, string $originalHashRaw): string
    {
        return 'term:' . $termTaxonomyId . ':' . $field . ':' . bin2hex($originalHashRaw);
    }

    public static function forI18n(string $source, string $referenceHashRaw, string $originalHashRaw): string
    {
        return 'i18n:' . $source . ':' . bin2hex($referenceHashRaw) . ':' . bin2hex($originalHashRaw);
    }

    public static function originalHashHexFromMapKey(string $key): ?string
    {
        $parts = explode(':', $key);

        if (!self::hasValidShape($parts)) {
            return null;
        }

        return $parts[count($parts) - 1];
    }

    /**
     * @param string[] $parts
     */
    private static function hasValidShape(array $parts): bool
    {
        if ($parts === []) {
            return false;
        }

        return match ($parts[0]) {
            'field' => count($parts) === 3
                && $parts[1] !== ''
                && self::isSha256Hex($parts[2]),
            'meta' => count($parts) === 4
                && self::isSha256Hex($parts[1])
                && self::isSha256Hex($parts[2])
                && self::isSha256Hex($parts[3]),
            'term' => count($parts) === 4
                && ctype_digit($parts[1])
                && $parts[2] !== ''
                && self::isSha256Hex($parts[3]),
            'i18n' => count($parts) === 4
                && $parts[1] !== ''
                && self::isSha256Hex($parts[2])
                && self::isSha256Hex($parts[3]),
            default => false,
        };
    }

    private static function isSha256Hex(string $value): bool
    {
        return (bool) preg_match('/\\A[a-f0-9]{64}\\z/', $value);
    }
}
