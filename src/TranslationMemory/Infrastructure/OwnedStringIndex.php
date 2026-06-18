<?php

namespace TransLangs\TranslationMemory\Infrastructure;

/**
 * Tier-2 ownership gate (v5 §5): the authoritative check of whether a text hash
 * is already owned by a structured source (post/term/meta/html). Run in the
 * background gettext-capture worker before inserting static-text rows, so a
 * string that exists as structured content is never duplicated as static text.
 *
 * Reads only the four structured parent tables (never tl_staticText itself).
 */
final class OwnedStringIndex
{
    /** Structured parent tables a static-text string could collide with. */
    private const STRUCTURED = ['tl_post', 'tl_meta', 'tl_term', 'tl_html'];

    public function __construct(private \wpdb $wpdb) {}

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
        $owned        = [];

        foreach (self::STRUCTURED as $key) {
            $sql = "SELECT LOWER(HEX(original_hash)) AS h
                    FROM {$tables[$key]} WHERE original_hash IN ({$placeholders})";

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            $rows = $this->wpdb->get_col($this->wpdb->prepare($sql, ...$hexes));
            foreach ((array) $rows as $hex) {
                $owned[strtolower((string) $hex)] = true;
            }
        }

        return $owned;
    }
}
