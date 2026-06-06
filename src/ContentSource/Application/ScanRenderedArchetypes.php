<?php

namespace TransLangs\ContentSource\Application;

use TransLangs\ContentSource\Domain\SourceRoute;
use TransLangs\ContentSource\Domain\ScanTarget;
use TransLangs\PageTranslation\Infrastructure\Html\HtmlParserFactory;
use TransLangs\TranslationMemory\Application\DiscoverSourceStrings;
use TransLangs\TranslationMemory\Domain\StringHashGenerator;
use TransLangs\TranslationMemory\Domain\StringNormalizer;
use TransLangs\TranslationMemory\Domain\TranslationSourceIdentity;

/**
 * Fetches each archetype URL, parses the rendered HTML, and discovers all
 * visible segments as i18n strings.
 *
 * Only template chrome (things not already covered by the structured source
 * readers) ends up here permanently. Structured entity text (post fields, term
 * names, media alt) is discovered more precisely by ScanStructuredSources; the
 * i18n rows created here serve as a safety net for dynamic/widget/shortcode
 * text that has no DB-backed origin.
 *
 * All segments are routed to SourceRoute::I18n with source='theme' and
 * reference=<DOM context hint>. INSERT IGNORE makes repeated scans idempotent.
 */
class ScanRenderedArchetypes
{
    private const FETCH_TIMEOUT = 30;

    public function __construct(
        private BuildScanTargets $targets,
        private HtmlParserFactory $parserFactory,
        private DiscoverSourceStrings $discovery,
        private StringNormalizer $normalizer,
        private StringHashGenerator $hasher,
    ) {}

    public function scan(): ArchetypeScanResult
    {
        $targets    = $this->targets->build();
        $scanned    = 0;
        $discovered = 0;
        $errors     = [];

        foreach ($targets as $target) {
            $scanned++;
            ['discovered' => $d, 'error' => $err] = $this->scanOne($target);
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
    private function scanOne(ScanTarget $target): array
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

        $identities = $this->segmentsToI18nIdentities($segments);

        if ($identities === []) {
            return ['discovered' => 0, 'error' => null];
        }

        $inserted = $this->discovery->discover($identities);

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
     * Convert parser segments to i18n-route identities.
     * DOM context (e.g. "h1", "img@alt") becomes the i18n reference.
     * Deduped by (context + normalized) before handing to the discovery service.
     *
     * @param  \TransLangs\PageTranslation\Domain\TranslatableSegment[] $segments
     * @return TranslationSourceIdentity[]
     */
    private function segmentsToI18nIdentities(array $segments): array
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
                source:         'theme',
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
