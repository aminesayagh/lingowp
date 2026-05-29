<?php

namespace TransLangs\Frontend;

use TransLangs\Core\HashGenerator;
use TransLangs\Core\Normalizer;
use TransLangs\Frontend\Parser\Segment;

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
class StringExtractor
{
    public function __construct(
        private Normalizer $normalizer,
        private HashGenerator $hasher
    ) {
    }

    /**
     * @param Segment[] $segments
     * @return Candidate[] Unique candidates, keyed by hash.
     */
    public function extract(array $segments): array
    {
        /** @var array<string, Candidate> $byHash */
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

            $byHash[$hash] = new Candidate(
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
     * @param Candidate[] $candidates
     * @return string[]
     */
    public function hashes(array $candidates): array
    {
        return array_values(array_map(
            static fn(Candidate $c): string => $c->hash,
            $candidates
        ));
    }
}