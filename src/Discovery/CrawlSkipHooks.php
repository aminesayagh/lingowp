<?php

namespace TransLangs\Discovery;

/**
 * The single source of truth for which WordPress hooks HtmlDiscoveryCrawl
 * marks to skip during its own crawl requests — never on marking-mode or
 * real visitor traffic (see HtmlDiscoveryCrawl::register()'s crawl-marker
 * gate). Adding a hook here is the whole change needed to skip it; no new
 * method or add_filter() call required.
 */
final class CrawlSkipHooks
{
    /** Hooks whose output is already translated via a separate mechanism
     * (StructuredRenderResolver + tl_post) or never translatable at all
     * (comments) — redundant or wrong to also discover through the HTML
     * crawl. Wrapped in <span data-tl-skip="1">. */
    private const REDUNDANT = ['comment_text', 'the_title', 'get_the_excerpt'];

    /** Same wrap, different reason: computed fresh at render time by a
     * dedicated resolver, never stored in any table — unlike REDUNDANT, no
     * database ownership check could ever catch these, so the wrap is the
     * only thing preventing a permanently-useless row. Value is which
     * resolver renders it — not consulted by any dispatcher yet (only one
     * resolver exists today), recorded since it costs nothing and is
     * self-documenting for whenever a second one exists. */
    private const DYNAMIC = ['wp_date' => 'date'];

    /** Hooks whose output may contain block-level child markup — wrapped in
     * <div data-tl-skip="1"> instead (a <span> wrapping block children is
     * invalid nesting). */
    private const BLOCK = ['the_content'];

    private function __construct()
    {
    }

    /** @return string[] */
    public static function redundantWithStructuredResolvers(): array
    {
        return self::REDUNDANT;
    }

    /** @return string[] */
    public static function dynamicallyComputed(): array
    {
        return array_keys(self::DYNAMIC);
    }

    /** @return string[] */
    public static function blockLevel(): array
    {
        return self::BLOCK;
    }
}
