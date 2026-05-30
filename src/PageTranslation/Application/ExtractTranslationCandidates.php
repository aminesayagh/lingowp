<?php

namespace TransLangs\PageTranslation\Application;

use TransLangs\TranslationMemory\Domain\StringHashGenerator;
use TransLangs\TranslationMemory\Domain\StringNormalizer;
use TransLangs\PageTranslation\Domain\TranslationCandidate;
use TransLangs\PageTranslation\Domain\TranslatableSegment;

/**
 * Turns raw parser segments into translation candidates.
 *
 * The parser (Step 13) already decided WHAT is translatable and skipped the
 * non-translatable subtrees. The extractor's job is the engine-facing half:
 *
 *   normalize -> hash -> filter empties -> deduplicate by hash
 *
 * Deduplication matters: a page may show "Read more" twenty times. We want one
 * candidate (one DB row, one translation lookup) that remembers all twenty
 * locations, so the renderer can replace every occurrence from a single
 * translation. This is what keeps the "100 strings = 1 query" rule achievable.
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
     * @return TranslationCandidate[] Unique candidates, keyed by hash.
     */
    public function extract(array $segments): array
    {
        /** @var array<string, TranslationCandidate> $byHash */
        $byHash = [];

        foreach ($segments as $segment) {
            $normalized = $this->normalizer->normalize($segment->value);

            // Normalization can empty out a string that was only whitespace or
            // entities; drop those. (The parser's text filter handles numbers
            // and symbols, but normalization is the final gate.)
            if ($normalized === '') {
                continue;
            }

            $hash = $this->hasher->generate($normalized);

            if (isset($byHash[$hash])) {
                // Same string seen again elsewhere: record the extra location.
                $byHash[$hash]->addLocator($segment->locator);
                continue;
            }

            $byHash[$hash] = new TranslationCandidate(
                originalText: $segment->value,
                normalizedText: $normalized,
                hash: $hash,
                type: $segment->type,
                context: $segment->context,
                locators: [$segment->locator]
            );
        }

        return $byHash;
    }

    /**
     * Convenience: just the hashes, for a bulk translation-memory lookup.
     *
     * @param TranslationCandidate[] $candidates
     * @return string[]
     */
    public function hashes(array $candidates): array
    {
        return array_values(array_map(
            static fn(TranslationCandidate $c): string => $c->hash,
            $candidates
        ));
    }
}
