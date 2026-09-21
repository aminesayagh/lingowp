<?php

namespace LingoWP\Extraction;
use LingoWP\Database\TranslationMemorySchema;

final class OwnedStringIndex
{
    private const STRUCTURED = ['post', 'meta', 'term', 'html', 'option'];

    private \wpdb $wpdb;

    public function __construct(\wpdb $wpdb)
    {
        $this->wpdb = $wpdb;
    }

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
