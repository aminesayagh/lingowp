<?php

namespace TransLangs\PageTranslation\Application;

use TransLangs\Language\Application\ResolveRequestLanguage;
use TransLangs\PageTranslation\Domain\ClassifiedTranslationCandidate;
use TransLangs\PageTranslation\Infrastructure\Html\HtmlParserFactory;
use TransLangs\PublicSite\Infrastructure\LinkRewriter;
use TransLangs\TranslationMemory\Application\ResolveTranslations;
use TransLangs\TranslationMemory\Domain\TranslationSourceType;

/**
 * Replaces original strings with translations in the rendered page.
 *
 * This is the join point of the public pipeline. It implements the Step 16
 * flow end to end:
 *
 *   1. parse the captured HTML once (HtmlParserFactory picks the driver)
 *   2. read translatable segments
 *   3. extract -> normalize -> hash -> dedupe into candidates
 *   4. bulk lookup + discover missing (ResolveTranslations)
 *   5. map each translated hash back onto every locator that produced it
 *   6. replace in place and return the serialized HTML
 *
 * Missing/untranslated strings are simply absent from the replacement map, so
 * they remain in the source language — the page never breaks.
 *
 * The render() method is shaped to be used directly as the OutputBufferMiddleware
 * pipeline: fn(string $html, string $targetLang): string.
 */
class TranslateHtmlPage
{
    public function __construct(
        private HtmlParserFactory $parserFactory,
        private ExtractTranslationCandidates $extractor,
        private ResolveTranslations $lookup,
        private ResolveRequestLanguage $resolver,
        private LinkRewriter $linkRewriter
    ) {
    }

    /**
     * Pipeline entry point. Returns translated HTML, or the original when the
     * request is in the default language (discover-only mode).
     *
     * Two modes, decided by target vs. source language:
     *   - default language  -> parse, DISCOVER for all languages, no text replace
     *   - translated         -> parse, DISCOVER, then REPLACE text + rewrite links
     *
     * Discovery runs in both modes so the memory fills on every visit.
     */
    public function render(string $html, string $targetLang): string
    {
        $sourceLang  = $this->resolver->getDefaultLanguage();
        $isTranslating = $targetLang !== $sourceLang;

        $parser = $this->parserFactory->create();

        if ($parser === null || !$parser->load($html)) {
            return $html; // no usable parser, or unparseable input: leave as-is
        }

        $segments = $parser->segments();

        if ($segments === [] && $parser->links() === []) {
            return $html;
        }

        $source     = $this->resolveCurrentSource();
        $candidates = $segments !== []
            ? $this->extractor->extract($segments, $source['source_id'], $source['source_type'])
            : [];

        // DISCOVERY (both modes): record every candidate for all enabled
        // non-default languages, so the memory fills even on default visits.
        if ($candidates !== []) {
            $this->lookup->discoverForAllLanguages($candidates);
        }

        // Default-language request: discover only, do not alter the page text.
        // (Links are already in the default language, so no rewrite needed.)
        if (!$isTranslating) {
            return $html;
        }

        // TRANSLATION mode: resolve translations for this target and replace.
        $replacements = [];

        if ($candidates !== []) {
            $translationsByIdentity = $this->lookup->resolve(
                $candidates,
                $sourceLang,
                $targetLang
            );

            $replacements = $this->mapToLocators($candidates, $translationsByIdentity);
        }

        // Rewrite internal links so the visitor stays in this language, even on
        // pages where no text is translated yet.
        $replacements += $this->buildLinkReplacements($parser->links(), $targetLang);

        if ($replacements === []) {
            return $html;
        }

        return $parser->render($replacements);
    }

    /**
     * @return array{source_id: int, source_type: string}
     */
    private function resolveCurrentSource(): array
    {
        $object = function_exists('get_queried_object') ? get_queried_object() : null;

        if ($object instanceof \WP_Post) {
            return [
                'source_id'   => (int) $object->ID,
                'source_type' => $this->normalizePostSourceType((string) $object->post_type),
            ];
        }

        return [
            'source_id'   => 0,
            'source_type' => TranslationSourceType::PAGE,
        ];
    }

    private function normalizePostSourceType(string $postType): string
    {
        return match ($postType) {
            TranslationSourceType::PAGE => TranslationSourceType::PAGE,
            TranslationSourceType::POST => TranslationSourceType::POST,
            TranslationSourceType::PRODUCT => TranslationSourceType::PRODUCT,
            default => $postType !== '' ? $postType : TranslationSourceType::PAGE,
        };
    }

    /**
     * Compute locator => prefixed-href for every rewritable internal link.
     *
     * @param \TransLangs\PageTranslation\Domain\TranslatableSegment[] $links
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

    /**
     * Build locator => translated_text from identityKey => translated_text by
     * walking each candidate's locator list. One stored translation replaces
     * every on-page occurrence that shares its identity.
     *
     * @param ClassifiedTranslationCandidate[] $candidates          keyed by identity key
     * @param array<string, string>            $translationsByIdentity identityKey => translated_text
     * @return array<string, string>                                  locator => translated_text
     */
    private function mapToLocators(array $candidates, array $translationsByIdentity): array
    {
        $replacements = [];

        foreach ($candidates as $identityKey => $candidate) {
            if (!isset($translationsByIdentity[$identityKey])) {
                continue;
            }

            $translated = $translationsByIdentity[$identityKey];

            foreach ($candidate->locators as $locator) {
                $replacements[$locator] = $translated;
            }
        }

        return $replacements;
    }

}
