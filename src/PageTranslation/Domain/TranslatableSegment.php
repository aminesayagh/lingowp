<?php

namespace TransLangs\PageTranslation\Domain;

/**
 * One translatable piece discovered in the document.
 *
 * A segment is parser-agnostic: it describes *what* was found and *where*,
 * without exposing the underlying DOM node or cursor. The extractor and
 * renderer work entirely with these, so swapping the parser implementation
 * never touches downstream code.
 */
final class TranslatableSegment
{
    public const TYPE_TEXT      = 'text';
    public const TYPE_ATTRIBUTE = 'attribute';

    /**
     * @param string $type     One of the TYPE_* constants.
     * @param string $value    The raw text/attribute value as found.
     * @param string $context  Where it lives, e.g. "h1", "img@alt", "title".
     * @param string $locator  Opaque token the parser uses to find this spot
     *                          again during replacement (e.g. a bookmark name).
     */
    public function __construct(
        public readonly string $type,
        public readonly string $value,
        public readonly string $context,
        public readonly string $locator
    ) {
    }

    public static function text(string $value, string $context, string $locator): self
    {
        return new self(self::TYPE_TEXT, $value, $context, $locator);
    }

    public static function attribute(string $value, string $context, string $locator): self
    {
        return new self(self::TYPE_ATTRIBUTE, $value, $context, $locator);
    }
}