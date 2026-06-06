<?php

namespace TransLangs\ContentSource\Application;

use TransLangs\ContentSource\Domain\ScanTarget;
use TransLangs\ContentSource\Domain\SourceRoute;
use TransLangs\Language\Application\ResolveRequestLanguage;
use TransLangs\PageTranslation\Application\ClassifyRenderedSegments;
use TransLangs\PageTranslation\Infrastructure\Html\HtmlParserFactory;
use TransLangs\TranslationMemory\Application\DiscoverSourceStrings;
use TransLangs\TranslationMemory\Domain\StringHashGenerator;
use TransLangs\TranslationMemory\Domain\TranslationSourceIdentity;

/**
 * Fetches each archetype URL, parses the rendered HTML, classifies segments,
 * and discovers new strings into the translation memory.
 *
 * NOTE: candidatesToIdentities() uses a temporary bridge mapping pre-Step-8
 * ClassifiedTranslationCandidate (old shape) to the new identity model.
 * Step 8 will replace this with a proper SourceRoute-aware classifier.
 */
class ScanRenderedArchetypes
{
    private const FETCH_TIMEOUT = 30;

    public function __construct(
        private BuildScanTargets $targets,
        private HtmlParserFactory $parserFactory,
        private ClassifyRenderedSegments $classifier,
        private DiscoverSourceStrings $discovery,
        private ResolveRequestLanguage $languages,
        private StringHashGenerator $hasher,
    ) {}

    public function scan(): ArchetypeScanResult
    {
        $sourceLang = $this->languages->getDefaultLanguage();
        $targets    = $this->targets->build();

        $scanned    = 0;
        $discovered = 0;
        $errors     = [];

        foreach ($targets as $target) {
            $scanned++;
            ['discovered' => $d, 'error' => $err] = $this->scanOne($target, $sourceLang);
            $discovered += $d;

            if ($err !== null) {
                $errors[] = [
                    'url'       => $target->url,
                    'archetype' => $target->archetype,
                    'error'     => $err,
                ];
            }
        }

        return new ArchetypeScanResult($scanned, $discovered, $errors);
    }

    /**
     * @return array{discovered: int, error: string|null}
     */
    private function scanOne(ScanTarget $target, string $sourceLang): array
    {
        $html = $this->fetchHtml($target->url);

        if ($html === null) {
            return ['discovered' => 0, 'error' => 'fetch failed or empty response'];
        }

        $parser = $this->parserFactory->create();

        if ($parser === null || !$parser->load($html)) {
            return ['discovered' => 0, 'error' => 'HTML could not be parsed'];
        }

        $segments = $parser->segments();

        if ($segments === []) {
            return ['discovered' => 0, 'error' => null];
        }

        // classifier signature will be updated in Step 8
        $candidates = $this->classifier->classify(
            $segments,
            $target->sourceId,
            $target->sourceType,
            $sourceLang
        );

        if ($candidates === []) {
            return ['discovered' => 0, 'error' => null];
        }

        $identities = $this->candidatesToIdentities($candidates);
        $inserted   = $this->discovery->discover($identities);

        return ['discovered' => $inserted, 'error' => null];
    }

    private function fetchHtml(string $url): ?string
    {
        $response = wp_remote_get($url, [
            'timeout'     => self::FETCH_TIMEOUT,
            'sslverify'   => false,
            'redirection' => 3,
            'headers'     => ['X-TransLangs-Archetype-Scan' => '1'],
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code($response);

        if (!in_array($code, [200, 404], true)) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);

        return $body !== '' ? $body : null;
    }

    /**
     * Convert pre-Step-8 ClassifiedTranslationCandidate objects to the new
     * TranslationSourceIdentity shape.
     *
     * Mapping:
     *   sourceId = 0  → SourceRoute::I18n  (template chrome, no entity FK)
     *   sourceId > 0  → SourceRoute::Post  (entity-owned; Step 8 will refine
     *                                        routing for media/taxonomy segments)
     *
     * @param \TransLangs\PageTranslation\Domain\ClassifiedTranslationCandidate[] $candidates
     * @return TranslationSourceIdentity[]
     */
    private function candidatesToIdentities(array $candidates): array
    {
        $identities = [];

        foreach ($candidates as $candidate) {
            $originalHash = $this->hasher->hashNormalized($candidate->normalizedText);

            if ($candidate->sourceId === 0) {
                $identities[] = new TranslationSourceIdentity(
                    routeTable:    SourceRoute::I18n,
                    parentId:      0,
                    field:         null,
                    metaKey:       null,
                    metaKeyHash:   null,
                    context:       null,
                    contextHash:   null,
                    source:        'theme',
                    reference:     $candidate->context,
                    referenceHash: $this->hasher->hashReference($candidate->context),
                    originalText:  $candidate->originalText,
                    normalizedText: $candidate->normalizedText,
                    originalHash:  $originalHash,
                );
                continue;
            }

            $identities[] = new TranslationSourceIdentity(
                routeTable:    SourceRoute::Post,
                parentId:      $candidate->sourceId,
                field:         $candidate->context,
                metaKey:       null,
                metaKeyHash:   null,
                context:       null,
                contextHash:   null,
                source:        null,
                reference:     null,
                referenceHash: null,
                originalText:  $candidate->originalText,
                normalizedText: $candidate->normalizedText,
                originalHash:  $originalHash,
            );
        }

        return $identities;
    }
}
