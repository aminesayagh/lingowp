<?php

namespace TransLangs\ContentSource\Application;

use TransLangs\ContentSource\Domain\ScanTarget;
use TransLangs\Language\Application\ResolveRequestLanguage;
use TransLangs\PageTranslation\Application\ClassifyRenderedSegments;
use TransLangs\PageTranslation\Infrastructure\Html\HtmlParserFactory;
use TransLangs\TranslationMemory\Application\DiscoverSourceStrings;
use TransLangs\TranslationMemory\Domain\TranslationSourceIdentity;

/**
 * Fetches each archetype URL, parses the rendered HTML, classifies segments,
 * and discovers new strings into the translation memory.
 *
 * This is the rendered-HTML auditor in batch mode. The normal render pipeline
 * (OutputBufferMiddleware → TranslateHtmlPage) does the same work on live
 * requests; this service does it proactively on a representative set of pages
 * so the admin doesn't need to visit every URL manually.
 *
 * Pipeline per URL:
 *   wp_remote_get → HtmlParser → ClassifyRenderedSegments → DiscoverSourceStrings
 *
 * The HTTP fetch uses WordPress's own HTTP API. Self-requests can fail in some
 * hosting environments (loopback blocked). When that happens the URL is skipped
 * and reported in ArchetypeScanResult::$errors, never throwing.
 */
class ScanRenderedArchetypes
{
    /** Seconds to wait for one page response. */
    private const FETCH_TIMEOUT = 30;

    public function __construct(
        private BuildScanTargets $targets,
        private HtmlParserFactory $parserFactory,
        private ClassifyRenderedSegments $classifier,
        private DiscoverSourceStrings $discovery,
        private ResolveRequestLanguage $languages
    ) {
    }

    /**
     * Run the full archetype scan and return a summary.
     */
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

    /**
     * Fetch the HTML of a URL using the WordPress HTTP API.
     *
     * Both 200 and 404 responses are accepted — 404 is intentionally requested
     * to capture the not-found page template chrome.
     */
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
     * @param \TransLangs\PageTranslation\Domain\ClassifiedTranslationCandidate[] $candidates
     * @return TranslationSourceIdentity[]
     */
    private function candidatesToIdentities(array $candidates): array
    {
        $identities = [];

        foreach ($candidates as $candidate) {
            $identities[] = TranslationSourceIdentity::source(
                $candidate->sourceId,
                $candidate->sourceType,
                $candidate->context,
                $candidate->originalText,
                $candidate->originalHash,
                $candidate->normalizedText
            );
        }

        return $identities;
    }
}
