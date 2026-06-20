<?php

namespace TransLangs\Source\Domain;

/**
 * The text-hash helper for the v5 schema.
 *
 * v5 retired both the packed 32-byte source_key and the numeric source "type":
 * identity now lives entirely in readable parent columns (post_id/field/
 * original_hash, etc.) and a record's kind is simply the parent table it targets.
 * All that remains here is currentHash() — the single definition of
 * original_hash = SHA-256(normalized text).
 */
final class TL_Key
{
    private function __construct() {}

    /**
     * Full 32-byte SHA-256 of the normalized text. Stored as a parent's
     * original_hash (identity) and, for hash-link children, the link key itself.
     */
    public static function currentHash(string $text): string
    {
        return hash('sha256', self::normalize($text), true);
    }

    /**
     * Deterministic normalization, applied identically at discovery and render:
     * line-ending → HTML-entity normalization → trim → whitespace collapse → UTF-8.
     * Delegated to StringNormalizer; this class owns the contract.
     */
    public static function normalize(string $text): string
    {
        return StringNormalizer::normalize($text);
    }
}
