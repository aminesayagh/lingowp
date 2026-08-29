<?php

namespace LingoWP\Extraction;
use LingoWP\Database\TranslationMemorySchema;

/**
 * Tier-2 ownership gate: the authoritative check of whether a text hash
 * is already owned by a structured source (post/term/meta/html/option). Run in
 * the background gettext-capture worker (before inserting static-text rows) and
 * by HtmlDiscovery (before inserting html rows), so a string that exists as
 * structured content is never duplicated as static text or hardcoded HTML.
 *
 * option is included because branding text (blogname/tagline) discovered
 * there commonly renders inline on the page too — a theme's header markup for
 * the site name often differs between the home page and inner pages (e.g. an
 * <h1> on the front page vs. a plain <div> elsewhere), which — without this —
 * would mint the same text as two separate html rows (different selector,
 * same original_hash) instead of recognizing it as already-owned content.
 *
 * Reads only the five structured parent tables.
 */
final class OwnedStringIndex
{
    /** Structured parent tables a static-text string could collide with. */
    private const STRUCTURED = ['post', 'meta', 'term', 'html', 'option'];

    private \wpdb $wpdb;

    public function __construct(\wpdb $wpdb)
    {
        $this->wpdb = $wpdb;
    }

    /**
     * Which of the given hashes are already owned by a structured source.
     *
     * @param string[] $rawHashes
     * @return array<string, true> hex(original_hash) => true for owned hashes
     */
    public function ownedHexes(array $rawHashes): array
    {
        $rawHashes = array_values(array_unique($rawHashes));
        if ($rawHashes === []) {
            return [];
        }

        $tables       = TranslationMemorySchema::parentTables();
        $hexes        = array_map('bin2hex', $rawHashes);
        $placeholders = implode(', ', array_fill(0, count($hexes), 'UNHEX(%s)'));

        $selects = [];
        $args    = [];
        foreach (self::STRUCTURED as $key) {
            $selects[] = "SELECT LOWER(HEX(original_hash)) AS h
                          FROM {$tables[$key]} WHERE original_hash IN ({$placeholders})";
            array_push($args, ...$hexes);
        }
        $sql = implode(' UNION ALL ', $selects);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = $this->wpdb->get_col($this->wpdb->prepare($sql, ...$args));

        $owned = [];
        foreach ((array) $rows as $hex) {
            $owned[strtolower((string) $hex)] = true;
        }

        return $owned;
    }
}
