<?php

namespace TransLangs\PageTranslation\Application;

use TransLangs\Language\Application\ResolveRequestLanguage;
use TransLangs\PageTranslation\Infrastructure\Html\HtmlParserFactory;
use TransLangs\PublicSite\Infrastructure\LinkRewriter;
use TransLangs\TranslationMemory\Application\ResolveTranslations;

/**
 * Replaces original strings with translations in the rendered page.
 *
 * Public pipeline:
 *   1. Parse captured HTML once (HtmlParserFactory picks the driver).
 *   2. Determine current page context: postId, term taxonomy IDs.
 *   3. For translated requests: preload translation map → classify segments →
 *      discover misses → replace text → rewrite links → render.
 *   4. For default-language requests: discover all segments (discovery-only,
 *      no text replacement needed).
 *
 * No per-string DB query: one preload per render covers the full page.
 * Missing/untranslated strings stay in the source language — the page never
 * breaks.
 */
class TranslateHtmlPage
{
    public function __construct(
        private HtmlParserFactory $parserFactory,
        private ResolveTranslations $lookup,
        private ResolveRequestLanguage $resolver,
        private LinkRewriter $linkRewriter
    ) {}

    /**
     * Pipeline entry point. Returns translated HTML, or the original when the
     * request is in the default language (discover-only mode).
     */
    public function render(string $html, string $targetLang): string
    {
        $sourceLang    = $this->resolver->getDefaultLanguage();
        $isTranslating = $targetLang !== $sourceLang;

        $parser = $this->parserFactory->create();

        if ($parser === null || !$parser->load($html)) {
            return $html;
        }

        $segments = $parser->segments();
        $links    = $parser->links();

        if ($segments === [] && $links === []) {
            return $html;
        }

        if ($isTranslating) {
            [$postId, $termTaxonomyIds] = $this->resolvePageContext();

            $replacements = $segments !== []
                ? $this->lookup->resolveForRender($segments, $targetLang, $postId, $termTaxonomyIds)
                : [];

            $replacements += $this->buildLinkReplacements($links, $targetLang);

            if ($replacements === []) {
                return $html;
            }

            return $parser->render($replacements);
        }

        // Default-language request: discover all segments for future translation,
        // but leave the page text unchanged.
        if ($segments !== []) {
            $this->lookup->discoverAll($segments);
        }

        return $html;
    }

    /**
     * Determine the primary entity and associated terms for the current request.
     *
     * @return array{int, int[]} [postId, termTaxonomyIds]
     */
    private function resolvePageContext(): array
    {
        $object = function_exists('get_queried_object') ? get_queried_object() : null;

        if ($object instanceof \WP_Post) {
            return [(int) $object->ID, $this->collectTermTaxonomyIds((int) $object->ID)];
        }

        if ($object instanceof \WP_Term) {
            return [0, [(int) $object->term_taxonomy_id]];
        }

        return [0, []];
    }

    /**
     * Collect term_taxonomy_ids for all terms attached to a post so their
     * translations are preloaded alongside the post's own fields.
     *
     * @return int[]
     */
    private function collectTermTaxonomyIds(int $postId): array
    {
        if (
            !function_exists('get_post_type') ||
            !function_exists('get_object_taxonomies') ||
            !function_exists('wp_get_object_terms')
        ) {
            return [];
        }

        $postType = get_post_type($postId);

        if ($postType === false || $postType === '') {
            return [];
        }

        $taxonomies = get_object_taxonomies($postType, 'names');

        if ($taxonomies === []) {
            return [];
        }

        $terms = wp_get_object_terms($postId, $taxonomies, ['fields' => 'tt_ids']);

        if (is_wp_error($terms) || !is_array($terms)) {
            return [];
        }

        return array_map('intval', $terms);
    }

    /**
     * Compute locator => prefixed-href for every rewritable internal link.
     *
     * @param  \TransLangs\PageTranslation\Domain\TranslatableSegment[] $links
     * @return array<string, string>
     */
    private function buildLinkReplacements(array $links, string $targetLang): array
    {
        $out = [];

        foreach ($links as $link) {
            $rewritten = $this->linkRewriter->rewrite($link->value, $targetLang);

            if ($rewritten !== null && $rewritten !== $link->value) {
                $out[$link->locator] = $rewritten;
            }
        }

        return $out;
    }
}
