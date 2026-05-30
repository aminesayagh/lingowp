<?php

namespace TransLangs\PageTranslation\Application;

use TransLangs\Language\Application\ResolveRequestLanguage;
use TransLangs\PageTranslation\Domain\TranslationCandidate;
use TransLangs\PageTranslation\Infrastructure\Html\HtmlParserFactory;
use TransLangs\PublicSite\Infrastructure\LinkRewriter;
use TransLangs\TranslationMemory\Application\ResolveTranslations;

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
     * Pipeline entry point. Returns translated HTML, or the original on any
     * condition where translation cannot or should not happen.
     */
    public function render(string $html, string $targetLang): string
    {
        $sourceLang = $this->resolver->getDefaultLanguage();

        // Nothing to do if the target is the source language.
        if ($targetLang === $sourceLang) {
            return $html;
        }

        $parser = $this->parserFactory->create();

        if ($parser === null || !$parser->load($html)) {
            return $html; // no usable parser, or unparseable input: leave as-is
        }

        $segments = $parser->segments();

        // Build text replacements (locator => translated text), if any.
        $replacements = [];

        if ($segments !== []) {
            $candidates = $this->extractor->extract($segments);

            if ($candidates !== []) {
                // hash => translated_text for already-translated strings; newly
                // discovered strings are inserted as "discovered" inside resolve().
                $translationsByHash = $this->lookup->resolve(
                    $candidates,
                    $sourceLang,
                    $targetLang,
                    $this->currentSourceUrl()
                );

                // Fan each per-hash translation out to every locator it occupies.
                $replacements = $this->mapToLocators($candidates, $translationsByHash);
            }
        }

        // Always rewrite internal links so the visitor stays in this language,
        // even on pages where no text is translated yet.
        $replacements += $this->buildLinkReplacements($parser->links(), $targetLang);

        if ($replacements === []) {
            return $html;
        }

        return $parser->render($replacements);
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
     * Build locator => translated_text from hash => translated_text by walking
     * each candidate's locator list. This is what lets one stored translation
     * replace every on-page occurrence of the same string.
     *
     * @param TranslationCandidate[]            $candidates         hash => TranslationCandidate
     * @param array<string, string>  $translationsByHash hash => translated_text
     * @return array<string, string>                     locator => translated_text
     */
    private function mapToLocators(array $candidates, array $translationsByHash): array
    {
        $replacements = [];

        foreach ($translationsByHash as $hash => $translated) {
            if (!isset($candidates[$hash])) {
                continue;
            }

            foreach ($candidates[$hash]->locators as $locator) {
                $replacements[$locator] = $translated;
            }
        }

        return $replacements;
    }

    /**
     * Best-effort current URL, stored with discovered strings for context.
     */
    private function currentSourceUrl(): ?string
    {
        $path = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';

        if ($path === '') {
            return null;
        }

        return function_exists('esc_url_raw') ? esc_url_raw($path) : $path;
    }
}
