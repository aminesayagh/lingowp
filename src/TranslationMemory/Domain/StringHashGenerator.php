<?php

namespace TransLangs\TranslationMemory\Domain;

/**
 * Generates stable SHA-256 hashes for storage in BINARY(32) columns.
 *
 * All methods return raw bytes (32 bytes), never hex strings.
 * Use bin2hex() only when building preload map keys or displaying to users.
 * Callers must pass pre-normalized text to hashNormalized().
 */
class StringHashGenerator
{
    /**
     * Hash of the normalized original text — stored as original_hash.
     * Always call StringNormalizer::normalize() first.
     */
    public function hashNormalized(string $normalizedText): string
    {
        return hash('sha256', $normalizedText, true);
    }

    /**
     * Hash of a postmeta key — stored as meta_key_hash.
     */
    public function hashMetaKey(string $metaKey): string
    {
        return hash('sha256', $metaKey, true);
    }

    /**
     * Hash of a postmeta context path — stored as context_hash.
     * Null and empty string both hash to sha256(''), so context_hash is never NULL.
     */
    public function hashContext(?string $context): string
    {
        return hash('sha256', $context ?? '', true);
    }

    /**
     * Hash of a normalized i18n reference — stored as reference_hash.
     * Reference is lowercased and trimmed before hashing so casing differences
     * do not split rows.
     */
    public function hashReference(string $reference): string
    {
        return hash('sha256', strtolower(trim($reference)), true);
    }
}
