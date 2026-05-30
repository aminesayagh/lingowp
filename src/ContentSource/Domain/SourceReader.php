<?php

namespace TransLangs\ContentSource\Domain;

/**
 * Contract for a structured-content reader.
 *
 * A reader knows how to pull translatable strings for ONE kind of source
 * (media, taxonomy, menu, a post type, …) directly from WordPress data —
 * never by rendering or parsing pages. This is what lets the plugin discover
 * 1000 imported products without visiting 1000 URLs.
 *
 * Readers must obey the ownership rule: emit only the strings their source
 * OWNS. A product reader emits the product's own fields (title, description,
 * custom fields) but NOT its featured image alt or its category name — those
 * are owned by the media reader and the taxonomy reader respectively, and are
 * discovered there exactly once.
 */
interface SourceReader
{
    /**
     * A short, stable identifier for this reader (e.g. "media", "taxonomy",
     * "post_type:product"). Used for reporting and scoping a scan.
     */
    public function key(): string;

    /**
     * Whether this reader can run in the current environment (e.g. the
     * WooCommerce reader is only available when WooCommerce is active).
     */
    public function isAvailable(): bool;

    /**
     * Read a batch of source strings.
     *
     * Pagination keeps memory bounded on large sites: callers loop, advancing
     * $offset by $limit, until an empty array comes back.
     *
     * @return SourceString[]
     */
    public function read(int $offset, int $limit): array;
}