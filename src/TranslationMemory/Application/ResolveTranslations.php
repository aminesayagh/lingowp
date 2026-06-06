<?php

namespace TransLangs\TranslationMemory\Application;

use TransLangs\ContentSource\Domain\SourceRoute;
use TransLangs\PageTranslation\Application\ClassifyRenderedSegments;
use TransLangs\PageTranslation\Domain\TranslatableSegment;
use TransLangs\TranslationMemory\Domain\StringHashGenerator;
use TransLangs\TranslationMemory\Domain\StringNormalizer;
use TransLangs\TranslationMemory\Domain\TranslationSourceIdentity;

/**
 * Orchestrates the render-time preload → classify → discover pipeline.
 *
 * For translated requests:
 *   1. Build the translation map for this page context (PreloadTranslations).
 *   2. Look each segment up in the map (ClassifyRenderedSegments).
 *   3. Discover segments that missed the map as i18n strings (INSERT IGNORE).
 *   4. Return locator => translated_text for immediate DOM replacement.
 *
 * For default-language requests (discovery-only mode):
 *   discoverAll() routes every segment to the i18n table so future target-
 *   language requests have rows ready to translate.
 *
 * Hard rule: no per-string DB query — one preload per render, nothing more.
 */
final class ResolveTranslations
{
    public function __construct(
        private PreloadTranslations $preloader,
        private ClassifyRenderedSegments $classifier,
        private DiscoverSourceStrings $discovery,
        private StringNormalizer $normalizer,
        private StringHashGenerator $hasher,
    ) {}

    /**
     * Preload, classify, discover misses, and return translations for one render.
     *
     * Segments found in the preload map are returned as locator => translation.
     * Segments not in the map are discovered as i18n strings (INSERT IGNORE).
     *
     * @param  TranslatableSegment[] $segments
     * @param  int[]                 $termTaxonomyIds
     * @return array<string, string> locator => translated_text
     */
    public function resolveForRender(
        array $segments,
        string $targetLang,
        int $postId = 0,
        array $termTaxonomyIds = [],
        string $i18nSource = 'theme'
    ): array {
        if ($segments === []) {
            return [];
        }

        $preloadMap = $this->preloader->preload($targetLang, $postId, $termTaxonomyIds, $i18nSource);

        ['found' => $found, 'missed' => $missed] = $this->classifier->classify($segments, $preloadMap);

        $this->discoverMissed($missed, $i18nSource);

        return $found;
    }

    /**
     * Discover all segments as i18n strings (default-language / discover-only mode).
     *
     * Deduped by (context, normalized text) before insertion. INSERT IGNORE
     * downstream makes every call idempotent.
     *
     * @param TranslatableSegment[] $segments
     */
    public function discoverAll(array $segments, string $i18nSource = 'theme'): void
    {
        $this->discoverMissed($segments, $i18nSource);
    }

    /**
     * @param TranslatableSegment[] $missed
     */
    private function discoverMissed(array $missed, string $i18nSource): void
    {
        if ($missed === []) {
            return;
        }

        $identities = $this->buildI18nIdentities($missed, $i18nSource);

        if ($identities !== []) {
            $this->discovery->discover($identities);
        }
    }

    /**
     * Convert segments to i18n-route identities for discovery.
     *
     * The DOM context (e.g. "h1", "img@alt") becomes the i18n reference so the
     * same text under two different DOM contexts is stored as two separate rows.
     * Dedup is by (context + normalized) to avoid redundant INSERT IGNORE calls.
     *
     * @param  TranslatableSegment[]      $segments
     * @return TranslationSourceIdentity[]
     */
    private function buildI18nIdentities(array $segments, string $source): array
    {
        $identities = [];
        $seen       = [];

        foreach ($segments as $segment) {
            $normalized = $this->normalizer->normalize($segment->value);

            if ($normalized === '') {
                continue;
            }

            $dedupeKey = $segment->context . "\0" . $normalized;

            if (isset($seen[$dedupeKey])) {
                continue;
            }

            $seen[$dedupeKey] = true;

            $identities[] = new TranslationSourceIdentity(
                routeTable:     SourceRoute::I18n,
                parentId:       0,
                field:          null,
                metaKey:        null,
                metaKeyHash:    null,
                context:        null,
                contextHash:    null,
                source:         $source,
                reference:      $segment->context,
                referenceHash:  $this->hasher->hashReference($segment->context),
                originalText:   $segment->value,
                normalizedText: $normalized,
                originalHash:   $this->hasher->hashNormalized($normalized),
            );
        }

        return $identities;
    }
}
