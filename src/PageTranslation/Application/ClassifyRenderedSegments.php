<?php

namespace TransLangs\PageTranslation\Application;

use TransLangs\PageTranslation\Domain\ClassifiedTranslationCandidate;
use TransLangs\PageTranslation\Domain\TranslatableSegment;
use TransLangs\TranslationMemory\Domain\StringHashGenerator;
use TransLangs\TranslationMemory\Domain\StringNormalizer;
use TransLangs\TranslationMemory\Domain\TranslationMemoryRepository;
use TransLangs\TranslationMemory\Domain\TranslationSourceType;

/**
 * Assigns each rendered HTML segment its correct source identity by matching it
 * against known structured source strings already stored in the translation
 * memory.
 *
 * The HTML parser emits segments with a DOM-level context (e.g. "h1", "img@alt")
 * but no entity identity. This service resolves the real identity so lookups and
 * discovery use the same (source_type, source_id, context) key as the structured
 * source readers — preventing the same text being stored twice under different
 * identities.
 *
 * Classification priority (applied per segment hash):
 *   1. Current entity's own field   — source matches (currentSourceType, currentSourceId)
 *   2. Referenced structured entity — source is media / taxonomy / menu (source_id > 0)
 *   3. Rendered / template chrome   — no structured match; falls back to
 *                                     (currentSourceType, 0, segmentContext)
 *
 * All segments with the same resolved identity are collapsed to one candidate;
 * each occurrence records its locator so render() can replace every spot.
 */
class ClassifyRenderedSegments
{
    /** Source types that can be referenced from any page without being owned. */
    private const REFERENCED_TYPES = [
        TranslationSourceType::MEDIA,
        TranslationSourceType::TAXONOMY,
        TranslationSourceType::MENU,
    ];

    public function __construct(
        private StringNormalizer $normalizer,
        private StringHashGenerator $hasher,
        private TranslationMemoryRepository $repository
    ) {
    }

    /**
     * Classify raw parser segments into identity-keyed candidates.
     *
     * @param  TranslatableSegment[] $segments
     * @param  int                   $currentSourceId   Queried-object ID; 0 for archives/404.
     * @param  string                $currentSourceType TranslationSourceType value for this page.
     * @param  string                $sourceLang        Default / source language code.
     * @return ClassifiedTranslationCandidate[]         Keyed by identity key.
     */
    public function classify(
        array $segments,
        int $currentSourceId,
        string $currentSourceType,
        string $sourceLang
    ): array {
        if ($segments === []) {
            return [];
        }

        // Step 1: normalize and hash every non-empty segment.
        $normalizedMap = [];
        $hashMap       = [];

        foreach ($segments as $i => $segment) {
            $norm = $this->normalizer->normalize($segment->value);

            if ($norm === '') {
                continue;
            }

            $normalizedMap[$i] = $norm;
            $hashMap[$i]       = $this->hasher->generate($norm);
        }

        if ($hashMap === []) {
            return [];
        }

        // Step 2: one bulk DB lookup for all distinct hashes.
        $knownByHash = $this->repository->findStructuredIdentitiesByHashes(
            array_unique(array_values($hashMap)),
            $sourceLang
        );

        // Step 3: apply priority rules and dedupe by identity key.
        $byIdentity = [];

        foreach ($segments as $i => $segment) {
            if (!isset($hashMap[$i])) {
                continue;
            }

            $hash = $hashMap[$i];
            $norm = $normalizedMap[$i];

            [$sourceId, $sourceType, $context] = $this->pickIdentity(
                $currentSourceId,
                $currentSourceType,
                $segment->context,
                $knownByHash[$hash] ?? []
            );

            $identityKey = ClassifiedTranslationCandidate::makeIdentityKey(
                $sourceType,
                $sourceId,
                $context,
                $hash
            );

            if (isset($byIdentity[$identityKey])) {
                $byIdentity[$identityKey]->addLocator($segment->locator);
                continue;
            }

            $byIdentity[$identityKey] = new ClassifiedTranslationCandidate(
                sourceId: $sourceId,
                sourceType: $sourceType,
                context: $context,
                originalText: $segment->value,
                normalizedText: $norm,
                originalHash: $hash,
                type: $segment->type,
                locators: [$segment->locator]
            );
        }

        return $byIdentity;
    }

    /**
     * Pick the best source identity for one segment given the structured
     * candidates found in the DB for its hash.
     *
     * @param  list<array{source_id: int, source_type: string, context: string}> $candidates
     * @return array{0: int, 1: string, 2: string}  [sourceId, sourceType, context]
     */
    private function pickIdentity(
        int $currentSourceId,
        string $currentSourceType,
        string $segmentContext,
        array $candidates
    ): array {
        // Priority 1: current entity's own field.
        foreach ($candidates as $c) {
            if ($c['source_type'] === $currentSourceType && $c['source_id'] === $currentSourceId) {
                return [$c['source_id'], $c['source_type'], $c['context']];
            }
        }

        // Priority 2: referenced structured entity (media / taxonomy / menu).
        foreach ($candidates as $c) {
            if (in_array($c['source_type'], self::REFERENCED_TYPES, true)) {
                return [$c['source_id'], $c['source_type'], $c['context']];
            }
        }

        // Priority 3: rendered / template chrome.
        return [0, $currentSourceType, $segmentContext];
    }
}
