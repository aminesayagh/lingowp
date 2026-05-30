<?php

namespace TransLangs\PageTranslation\Domain;

/**
 * A translatable string after normalization, hashing, AND source
 * classification — the unit the translation memory stores and looks up.
 *
 * This supersedes the older hash-only candidate model. Identity is the full
 * tuple (sourceType, sourceId, context, originalHash), never the hash alone,
 * because the same text can legitimately exist under different identities
 * (a product title vs. a menu label vs. an image alt). Resolving by hash alone
 * would let those collide.
 *
 * Until per-segment classification lands (Phase 5), sourceId/sourceType are
 * populated from the page-level source. The data shape does not change when
 * classification arrives — only who sets these fields.
 */
final class ClassifiedTranslationCandidate
{
    /**
     * @param int      $sourceId       Owning entity ID; 0 for template chrome.
     * @param string   $sourceType     'page'|'post'|'product'|'media'|... .
     * @param string   $context        Field name for entities; DOM/area hint for template.
     * @param string   $originalText   Raw text as found.
     * @param string   $normalizedText Whitespace/entity-normalized form.
     * @param string   $originalHash   sha256 of the normalized text.
     * @param string   $type           TranslatableSegment::TYPE_TEXT | TYPE_ATTRIBUTE.
     * @param string[] $locators        Every spot in the doc holding this identity.
     */
    public function __construct(
        public readonly int $sourceId,
        public readonly string $sourceType,
        public readonly string $context,
        public readonly string $originalText,
        public readonly string $normalizedText,
        public readonly string $originalHash,
        public readonly string $type,
        public array $locators
    ) {
    }

    /**
     * Stable composite key for this candidate's identity. Used to dedupe
     * candidates and to map translations back without hash-only collisions.
     */
    public function identityKey(): string
    {
        return self::makeIdentityKey($this->sourceType, $this->sourceId, $this->context, $this->originalHash);
    }

    /**
     * Build an identity key from parts (so callers can look one up without an
     * instance).
     */
    public static function makeIdentityKey(
        string $sourceType,
        int $sourceId,
        string $context,
        string $originalHash
    ): string {
        return $sourceType . '|' . $sourceId . '|' . $context . '|' . $originalHash;
    }

    public function addLocator(string $locator): void
    {
        if (!in_array($locator, $this->locators, true)) {
            $this->locators[] = $locator;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source_id'       => $this->sourceId,
            'source_type'     => $this->sourceType,
            'context'         => $this->context,
            'original_text'   => $this->originalText,
            'normalized_text' => $this->normalizedText,
            'original_hash'   => $this->originalHash,
            'type'            => $this->type,
            'locators'        => $this->locators,
        ];
    }
}
