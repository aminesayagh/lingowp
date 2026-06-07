<?php

namespace TransLangs\PublicSite\Infrastructure;

use TransLangs\Language\Application\ResolveRequestLanguage;
use TransLangs\TranslationMemory\Domain\TranslationMapKey;
use TransLangs\TranslationMemory\Domain\TranslationMemoryRepository;
use TransLangs\TranslationMemory\Infrastructure\CaptureBatchQueue;

final class PublicRenderPipeline
{
    private ?string $targetLang = null;
    private ?string $currentUrl = null;

    public function __construct(
        private ResolveRequestLanguage $languageResolver,
        private RequestContext $requestContext,
        private TranslationMemoryRepository $translationMemory,
        private HtmlRenderParserFactory $parserFactory,
        private PublicLinkRewriter $linkRewriter,
        private GettextCaptureBuffer $gettextCapture,
        private CaptureBatchQueue $captureQueue
    ) {}

    public function register(): void
    {
        add_action('template_redirect', [$this, 'maybeStart'], 0);
    }

    public function maybeStart(): void
    {
        if (!$this->requestContext->shouldTranslate() || !$this->languageResolver->isTranslatedRequest()) {
            return;
        }

        $this->targetLang = $this->languageResolver->resolve();
        $this->currentUrl = $this->currentUrl();

        $this->gettextCapture->start();

        ob_start(
            [$this, 'finish'],
            0,
            PHP_OUTPUT_HANDLER_STDFLAGS ^ PHP_OUTPUT_HANDLER_FLUSHABLE
        );
    }

    public function finish(string $html, int $phase = 0): string
    {
        $requestArea = $this->gettextCapture->capturedRequestArea();
        $gettextCandidates = $this->gettextCapture->stop();

        if (
            ($phase & PHP_OUTPUT_HANDLER_CLEAN) !== 0
            || $this->targetLang === null
            || $this->currentUrl === null
            || !$this->isHtmlResponse()
        ) {
            return $html;
        }

        $maps = $this->preloadMaps($this->targetLang);
        $result = $this->parserFactory->translate(
            $html,
            $this->replacementMap($maps),
            $this->ownedHashSet($maps)
        );

        if (!$result->success) {
            return $this->linkRewriter->rewrite($html, $this->targetLang);
        }

        $finalHtml = $this->linkRewriter->rewrite($result->html, $this->targetLang);

        if ($result->fallbackCandidates !== [] || $gettextCandidates !== []) {
            $this->captureQueue->enqueue([
                'target_lang'         => $this->targetLang,
                'source_url'          => $this->currentUrl,
                'request_area'        => $requestArea,
                'gettext_candidates'  => $gettextCandidates,
                'fallback_candidates' => $result->fallbackCandidates,
            ]);
        }

        return $finalHtml;
    }

    /**
     * @return array{post: array<string, string>, postmeta: array<string, string>, terms: array<string, string>, gettext: array<string, string>, rendered_html: array<string, string>}
     */
    private function preloadMaps(string $targetLang): array
    {
        $postId          = $this->currentPostId();
        $termTaxonomyIds = $this->currentTermTaxonomyIds($postId);

        return [
            'post'          => $postId > 0 ? $this->translationMemory->getPostTranslations($targetLang, $postId) : [],
            'postmeta'      => $postId > 0 ? $this->translationMemory->getPostmetaTranslations($targetLang, $postId) : [],
            'terms'         => $this->translationMemory->getTermTranslations($targetLang, $termTaxonomyIds),
            'gettext'       => $this->translationMemory->getI18nTranslations($targetLang, 'gettext'),
            'rendered_html' => $this->translationMemory->getI18nTranslations($targetLang, 'rendered_html'),
        ];
    }

    /**
     * @param array<string, array<string, string>> $maps
     * @return array<string, string>
     */
    private function replacementMap(array $maps): array
    {
        $replacements = [];

        foreach (['post', 'postmeta', 'terms', 'gettext', 'rendered_html'] as $route) {
            foreach ($maps[$route] ?? [] as $mapKey => $translatedText) {
                $hashHex = TranslationMapKey::originalHashHexFromMapKey($mapKey);
                if ($hashHex === null || $translatedText === '') {
                    continue;
                }

                $replacements[$hashHex] ??= $translatedText;
            }
        }

        return $replacements;
    }

    /**
     * @param array<string, array<string, string>> $maps
     * @return array<string, true>
     */
    private function ownedHashSet(array $maps): array
    {
        $owned = [];

        foreach (['post', 'postmeta', 'terms'] as $route) {
            foreach (array_keys($maps[$route] ?? []) as $mapKey) {
                $hashHex = TranslationMapKey::originalHashHexFromMapKey($mapKey);
                if ($hashHex !== null) {
                    $owned[$hashHex] = true;
                }
            }
        }

        return $owned;
    }

    private function currentPostId(): int
    {
        if (function_exists('is_singular') && is_singular() && function_exists('get_queried_object_id')) {
            return max(0, (int) get_queried_object_id());
        }

        return 0;
    }

    /**
     * @return int[]
     */
    private function currentTermTaxonomyIds(int $postId): array
    {
        $ids = [];

        if ($postId > 0 && function_exists('get_post_type') && function_exists('get_object_taxonomies')) {
            $postType   = get_post_type($postId);
            $taxonomies = is_string($postType) ? get_object_taxonomies($postType, 'names') : [];

            foreach ($taxonomies as $taxonomy) {
                $terms = function_exists('get_the_terms') ? get_the_terms($postId, $taxonomy) : false;
                if (!is_array($terms)) {
                    continue;
                }

                foreach ($terms as $term) {
                    if (isset($term->term_taxonomy_id)) {
                        $ids[] = (int) $term->term_taxonomy_id;
                    }
                }
            }
        }

        if (function_exists('get_queried_object')) {
            $queried = get_queried_object();
            if (is_object($queried) && isset($queried->term_taxonomy_id)) {
                $ids[] = (int) $queried->term_taxonomy_id;
            }
        }

        return array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
    }

    private function currentUrl(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $uri = '/' . ltrim($uri, '/');

        return function_exists('home_url') ? (string) home_url($uri) : $uri;
    }

    private function isHtmlResponse(): bool
    {
        foreach (headers_list() as $header) {
            [$name, $value] = array_pad(explode(':', strtolower($header), 2), 2, '');
            if (trim($name) !== 'content-type') {
                continue;
            }

            $mediaType = trim(strtok($value, ';') ?: '');
            return in_array($mediaType, ['text/html', 'application/xhtml+xml'], true);
        }

        return true;
    }
}
