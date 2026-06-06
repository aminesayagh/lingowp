<?php

namespace TransLangs\TranslationMemory\Application;

use TransLangs\TranslationMemory\Domain\TranslationMemoryRepository;

/**
 * Loads all renderable translations for a single page render into one map.
 *
 * Merges the four split-table preloads (post, postmeta, terms, i18n) into a
 * single array<mapKey, translatedText>. The returned map is consumed by
 * ClassifyRenderedSegments to translate segments without any per-string query.
 *
 * Only rows with renderable statuses are included by each repository method
 * (machine, reviewed, needs_review). Discovered and ignored rows are excluded.
 */
final class PreloadTranslations
{
    public function __construct(private TranslationMemoryRepository $repository) {}

    /**
     * Build the combined translation map for one page render.
     *
     * @param  int[]  $termTaxonomyIds  All term_taxonomy_id values visible on this page.
     * @return array<string, string>    mapKey => translated_text
     */
    public function preload(
        string $targetLang,
        int $postId = 0,
        array $termTaxonomyIds = [],
        string $i18nSource = 'theme'
    ): array {
        $map = [];

        if ($postId > 0) {
            $map += $this->repository->getPostTranslations($targetLang, $postId);
            $map += $this->repository->getPostmetaTranslations($targetLang, $postId);
        }

        if ($termTaxonomyIds !== []) {
            $map += $this->repository->getTermTranslations($targetLang, $termTaxonomyIds);
        }

        $map += $this->repository->getI18nTranslations($targetLang, $i18nSource);

        return $map;
    }
}
