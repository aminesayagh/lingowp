<?php

namespace TransLangs\ContentSource\Domain;

/**
 * Contract for generating a representative set of URLs to scan.
 *
 * Each implementation decides HOW to discover URLs (WordPress query, sitemap,
 * manual list, …). The Application layer (BuildScanTargets) consumes this
 * interface and is therefore independent of the WordPress API.
 */
interface UrlSampler
{
    /**
     * Return a list of scan targets covering each distinct page archetype.
     * The list may contain duplicates; BuildScanTargets deduplicates by URL.
     *
     * @return ScanTarget[]
     */
    public function sample(): array;
}
