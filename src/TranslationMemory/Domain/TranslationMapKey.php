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
}
