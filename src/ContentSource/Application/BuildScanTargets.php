<?php

namespace TransLangs\ContentSource\Application;

use TransLangs\ContentSource\Domain\ScanTarget;
use TransLangs\ContentSource\Domain\UrlSampler;

/**
 * Builds a deduplicated, ordered list of page archetypes to scan.
 *
 * This is the Application entry point for URL discovery. It delegates the
 * WordPress-specific sampling work to a UrlSampler implementation, then
 * applies business rules that the sampler must not know about:
 *
 *   - Deduplicate by URL (two archetypes resolving to the same URL only scan once).
 *   - Preserve stable ordering (front page first, then pages, posts, products, CPTs,
 *     archives, and finally the synthetic 404/search targets).
 *
 * The returned list is ready to hand directly to ScanRenderedArchetypes::scan().
 */
class BuildScanTargets
{
    public function __construct(private UrlSampler $sampler)
    {
    }

    /**
     * @return ScanTarget[] Deduplicated by URL, in sampler order.
     */
    public function build(): array
    {
        $raw = $this->sampler->sample();

        return $this->deduplicateByUrl($raw);
    }

    /**
     * First occurrence of each URL wins; later duplicates (same URL, different
     * archetype label) are dropped.
     *
     * @param  ScanTarget[] $targets
     * @return ScanTarget[]
     */
    private function deduplicateByUrl(array $targets): array
    {
        $seen = [];
        $out  = [];

        foreach ($targets as $target) {
            if (isset($seen[$target->url])) {
                continue;
            }

            $seen[$target->url] = true;
            $out[]              = $target;
        }

        return $out;
    }
}
