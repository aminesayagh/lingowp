<?php

namespace TransLangs\TranslationMemory\Domain;

use InvalidArgumentException;

/**
 * The single chokepoint for source_key generation (database architecture §1).
 *
 * Every translatable string is identified by a fixed-width 32-byte binary key,
 * big-endian throughout. Identity is LOCATIONAL, not textual (D26): the key
 * encodes WHERE a string lives, never the string itself — so editing the text
 * keeps the same key and the translation stays attached. The only exception is
 * plain gettext (type 4), which has no location and falls back to text identity.
 *
 *     source_key = [ type : 1 ][ owner_id : 8 ][ H(ref1|ref2) : 8 ][ tail : 15 ]
 *
 * | Segment     | Bytes | Content                                                         |
 * |-------------|-------|-----------------------------------------------------------------|
 * | type        | 1     | 1=post · 2=postmeta · 3=terms · 4=i18n (gettext) · 5=html        |
 * | owner_id    | 8     | pack('J', post_id) for 1-2; pack('J', tt_id) for 3; zeros 4-5   |
 * | H(ref1|ref2)| 8     | first 8 bytes of SHA-256 of ref1 . '|' . (ref2 ?? '')           |
 * | tail        | 15    | types 1,2,3,5: 15 ZERO bytes (location is the identity). type 4 |
 * |             |       | ONLY: first 15 bytes of the text hash (gettext has no location) |
 *
 * This is the ONLY place keys are constructed (§1 contract rule 1). The byte
 * layout is a versioned contract: any change requires a key-rebuild migration.
 */
final class TL_Key
{
    public const TYPE_POST     = 1;
    public const TYPE_POSTMETA = 2;
    public const TYPE_TERMS    = 3;
    public const TYPE_I18N     = 4;
    public const TYPE_HTML     = 5;

    /** Type-5 DOM selector length cap, enforced here (§1 rule, D15). */
    public const SELECTOR_MAX_LENGTH = 100;

    private function __construct() {}

    /**
     * Build the 32-byte binary source_key for one source string.
     *
     * The key is locational: for types 1,2,3,5 the text is irrelevant (zero
     * tail), so editing a string keeps the same key. Only type 4 (gettext) hashes
     * the text into the tail, since it has no location.
     *
     * @param int         $type    one of the TYPE_* constants
     * @param int|null    $ownerId post_id (types 1-2), term_taxonomy_id (type 3); null for 4-5
     * @param string      $ref1    NOT NULL per type: field / meta_key / domain / selector
     * @param string|null $ref2    nullable: meta context / gettext context / reference
     * @param string      $text    raw source text; consumed ONLY for the type-4 tail
     *
     * @throws InvalidArgumentException when the (type, owner, ref1) shape is illegal.
     */
    public static function make(int $type, ?int $ownerId, string $ref1, ?string $ref2, string $text): string
    {
        self::assertShape($type, $ownerId, $ref1);

        $ownerBytes = self::packOwner($type, $ownerId);
        $refBytes   = substr(hash('sha256', $ref1 . '|' . ($ref2 ?? ''), true), 0, 8);
        $tailBytes  = $type === self::TYPE_I18N
            ? substr(self::currentHash($text), 0, 15)
            : str_repeat("\0", 15);

        return chr($type) . $ownerBytes . $refBytes . $tailBytes;
    }

    /**
     * Full 32-byte SHA-256 of the normalized current text. Stored verbatim in
     * wp_tl_source.current_hash (staleness detection, NOT identity); its first
     * 15 bytes also feed the type-4 key tail.
     */
    public static function currentHash(string $text): string
    {
        return hash('sha256', self::normalize($text), true);
    }

    /**
     * Deterministic normalization, applied identically at discovery and render (§1):
     * line-ending normalization → HTML-entity normalization → trim →
     * internal-whitespace collapse → UTF-8. Delegated to StringNormalizer, which
     * already implements this exact order; the chokepoint owns the contract.
     */
    public static function normalize(string $text): string
    {
        return StringNormalizer::normalize($text);
    }

    /**
     * 8 big-endian owner bytes: post_id / term_taxonomy_id for owned types,
     * 8 zero bytes for the parent-less types 4-5.
     */
    private static function packOwner(int $type, ?int $ownerId): string
    {
        if ($type === self::TYPE_I18N || $type === self::TYPE_HTML) {
            return str_repeat("\0", 8);
        }

        // assertShape guarantees a positive owner id here for types 1-3.
        return pack('J', $ownerId);
    }

    /**
     * Per-type shape enforcement (architecture §2 table). Invalid combinations
     * fail loudly before any DB write can be attempted with a malformed key.
     */
    private static function assertShape(int $type, ?int $ownerId, string $ref1): void
    {
        if ($type < self::TYPE_POST || $type > self::TYPE_HTML) {
            throw new InvalidArgumentException("TL_Key: unknown source type {$type}.");
        }

        if ($ref1 === '') {
            throw new InvalidArgumentException("TL_Key: ref1 is required (NOT NULL) for type {$type}.");
        }

        $ownedType = $type === self::TYPE_POST
            || $type === self::TYPE_POSTMETA
            || $type === self::TYPE_TERMS;

        if ($ownedType) {
            if ($ownerId === null || $ownerId <= 0) {
                throw new InvalidArgumentException("TL_Key: type {$type} requires a positive owner id.");
            }
        } elseif ($ownerId !== null) {
            throw new InvalidArgumentException("TL_Key: type {$type} must not carry an owner id.");
        }

        if ($type === self::TYPE_HTML && mb_strlen($ref1, 'UTF-8') > self::SELECTOR_MAX_LENGTH) {
            throw new InvalidArgumentException(
                'TL_Key: type 5 selector exceeds ' . self::SELECTOR_MAX_LENGTH . ' characters.'
            );
        }
    }
}
