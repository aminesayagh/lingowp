<?php

namespace TransLangs\PageTranslation\Application;

use TransLangs\PageTranslation\Domain\TranslatableSegment;
use TransLangs\TranslationMemory\Domain\StringHashGenerator;
use TransLangs\TranslationMemory\Domain\StringNormalizer;

/**
 * Classifies rendered HTML segments against the preloaded translation map.
 *
 * No database queries are issued here. The caller (ResolveTranslations) builds
 * the preload map in one shot and passes it in; this service normalizes and
 * hashes each segment then looks it up in-memory.
 *
 * Lookup strategy: every preload map key ends with original_hash_hex as its
 * last colon-delimited token (field:<field>:<hex>, meta:<mkh>:<cth>:<hex>,
 * term:<ttId>:<field>:<hex>, i18n:<src>:<refh>:<hex>). A reverse index
 * hexHash => translation is built once and shared across all segments.
 *
 * When two map keys share the same original hash (same normalized text under
 * different entities), the reverse index keeps the first match — collisions are
 * rare and acceptable for the render path.
 */
final class ClassifyRenderedSegments
{
    public function __construct(
        private StringNormalizer $normalizer,
        private StringHashGenerator $hasher,
    ) {}

    /**
     * @param  TranslatableSegment[]  $segments
     * @param  array<string, string>  $preloadMap  mapKey => translated_text (from PreloadTranslations)
     * @return array{found: array<string, string>, missed: TranslatableSegment[]}
     */
    public function classify(array $segments, array $preloadMap): array
    {
        if ($segments === []) {
            return ['found' => [], 'missed' => []];
        }

        $byHash = $this->buildHashIndex($preloadMap);

        $found  = [];
        $missed = [];

        foreach ($segments as $segment) {
            $normalized = $this->normalizer->normalize($segment->value);

            if ($normalized === '') {
                continue;
            }

            $hexHash = bin2hex($this->hasher->hashNormalized($normalized));

            if (isset($byHash[$hexHash])) {
                $found[$segment->locator] = $byHash[$hexHash];
            } else {
                $missed[] = $segment;
            }
        }

        return ['found' => $found, 'missed' => $missed];
    }

    /**
     * Build a reverse index: original_hash_hex => translated_text.
     * Every map key format has the original hash hex as its last colon token.
     * First match wins when multiple keys share the same hash.
     *
     * @param  array<string, string> $preloadMap
     * @return array<string, string>
     */
    private function buildHashIndex(array $preloadMap): array
    {
        $index = [];

        foreach ($preloadMap as $key => $translation) {
            $pos = strrpos($key, ':');

            if ($pos === false) {
                continue;
            }

            $hashHex = substr($key, $pos + 1);

            if (!isset($index[$hashHex])) {
                $index[$hashHex] = $translation;
            }
        }

        return $index;
    }
}
