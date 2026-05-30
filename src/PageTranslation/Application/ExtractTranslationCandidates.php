<?php

namespace TransLangs\PageTranslation\Application;

use TransLangs\TranslationMemory\Domain\StringHashGenerator;
use TransLangs\TranslationMemory\Domain\StringNormalizer;
use TransLangs\PageTranslation\Domain\ClassifiedTranslationCandidate;
use TransLangs\PageTranslation\Domain\TranslatableSegment;

/**
 * Turns raw parser segments into classified translation candidates.
 *
 * The parser already decided WHAT is translatable and skipped non-translatable
 * subtrees. The extractor's job is the engine-facing half:
 *
 *   normalize -> hash -> filter empties -> dedupe by FULL IDENTITY
 *
 * Dedup is by identity (sourceType, sourceId, context, hash), NOT by hash
 * alone. Twenty "Read more" links in the same context collapse to one candidate
 * with twenty locators (one row, one lookup). But the same text under a
 * different context stays a separate candidate, so identities never collide.
 *
 * sourceId/sourceType are the page-level source for now; Phase 5 will set them
 * per segment via classification without changing this shape.
 */
class ExtractTranslationCandidates
{
    public function __construct(
        private StringNormalizer $normalizer,
        private StringHashGenerator $hasher
    ) {
    }

    /**
     * @param TranslatableSegment[] $segments
     * @return ClassifiedTranslationCandidate[] Keyed by identity key.
     */
    public function extract(array $segments, int $sourceId = 0, string $sourceType = ''): array
    {
        /** @var array<string, ClassifiedTranslationCandidate> $byIdentity */
        $byIdentity = [];

        foreach ($segments as $segment) {
            $normalized = $this->normalizer->normalize($segment->value);

            // Normalization can empty out a whitespace/entity-only string.
            if ($normalized === '') {
                continue;
            }

            $hash = $this->hasher->generate($normalized);

            $key = ClassifiedTranslationCandidate::makeIdentityKey(
                $sourceType,
                $sourceId,
                $segment->context,
                $hash
            );

            if (isset($byIdentity[$key])) {
                // Same identity seen again: just record the extra location.
                $byIdentity[$key]->addLocator($segment->locator);
                continue;
            }

            $byIdentity[$key] = new ClassifiedTranslationCandidate(
                sourceId: $sourceId,
                sourceType: $sourceType,
                context: $segment->context,
                originalText: $segment->value,
                normalizedText: $normalized,
                originalHash: $hash,
                type: $segment->type,
                locators: [$segment->locator]
            );
        }

        return $byIdentity;
    }

    /**
     * Convenience: just the hashes, for a bulk translation-memory lookup.
     *
     * @param ClassifiedTranslationCandidate[] $candidates
     * @return string[]
     */
    public function hashes(array $candidates): array
    {
        return array_values(array_map(
            static fn(ClassifiedTranslationCandidate $c): string => $c->originalHash,
            $candidates
        ));
    }
}
