<?php

namespace TransLangs\ContentSource\Domain;

/**
 * One representative URL to visit during an archetype scan.
 *
 * A scan target carries the URL and enough source-identity context so the
 * classifier knows what kind of page it is rendering — without that context
 * every segment would fall through to the template-chrome fallback.
 *
 * sourceId = 0 for archive, search, 404, and front-page-as-blog contexts where
 * there is no single owning entity. sourceId > 0 for individual page/post/product
 * entities.
 */
final class ScanTarget
{
    /**
     * @param string $url        Absolute URL to fetch.
     * @param string $archetype  Short label for reporting, e.g. "product_simple",
     *                           "static_page", "category_archive".
     * @param string $sourceType TranslationSourceType constant, or runtime CPT name.
     * @param int    $sourceId   Post/entity ID, or 0 for archive-style pages.
     */
    public function __construct(
        public readonly string $url,
        public readonly string $archetype,
        public readonly string $sourceType,
        public readonly int $sourceId
    ) {
    }
}
