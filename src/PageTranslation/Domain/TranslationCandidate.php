<?php

namespace TransLangs\PageTranslation\Domain;

use TransLangs\PageTranslation\Domain\TranslatableSegment;

/**
 * A translatable string after normalization and hashing — the unit the
 * translation memory stores and looks up.
 *
 * Mirrors the candidate shape from the architecture:
 *   original_text, normalized_text, hash, type, context, locator(s).
 *
 * Note on "node": the architecture's example used a live DOM node reference.
 * We deliberately store an opaque locator (and possibly several, when the same
 * string appears in multiple places) instead of a node, so candidates stay
 * serializable, cacheable, and decoupled from any specific parser.
 */
final class TranslationCandidate
{
    /**
     * @param string   $originalText   Raw text as found in the document.
     * @param string   $normalizedText Whitespace/entity-normalized form.
     * @param string   $hash           sha256 of the normalized text.
     * @param string   $type           TranslatableSegment::TYPE_TEXT | TYPE_ATTRIBUTE.
     * @param string   $context        Where it appears, e.g. "body>h1", "img@alt".
     * @param string[] $locators       Every spot in the doc holding this string.
     */
    public function __construct(
        public readonly string $originalText,
        public readonly string $normalizedText,
        public readonly string $hash,
        public readonly string $type,
        public readonly string $context,
        public array $locators
    ) {
    }

    public function addLocator(string $locator): void
    {
        if (!in_array($locator, $this->locators, true)) {
            $this->locators[] = $locator;
        }
    }

    /**
     * Array form matching the architecture's candidate object (for storage/debug).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'original_text'   => $this->originalText,
            'normalized_text' => $this->normalizedText,
            'hash'            => $this->hash,
            'type'            => $this->type,
            'context'         => $this->context,
            'locators'        => $this->locators,
        ];
    }
}