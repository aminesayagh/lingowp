<?php

namespace TransLangs\ContentSource\Domain;

/**
 * One translatable string pulled directly from a WordPress source (an entity
 * field, a term, a menu item, …) — before normalization/hashing.
 *
 * Readers emit these; the orchestrator turns them into TranslationSourceIdentity
 * and hands them to the discovery service. Keeping this separate from the
 * identity object means readers stay free of normalization/hash concerns.
 */
final class SourceString
{
    /**
     * @param int    $sourceId   Owning entity ID (attachment/term/post/…); 0 if none.
     * @param string $sourceType TranslationSourceType value (media/taxonomy/page/…).
     * @param string $context    Field identifier, e.g. "alt", "post_title",
     *                           "category.name". Pure metadata + part of identity.
     * @param string $text       The raw source text.
     */
    public function __construct(
        public readonly int $sourceId,
        public readonly string $sourceType,
        public readonly string $context,
        public readonly string $text
    ) {
    }
}