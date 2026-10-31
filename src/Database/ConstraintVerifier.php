<?php

namespace LingoWP\Database;

/**
 * Verifies that FK constraints actually exist in information_schema after table creation.
 *
 * InnoDB silently ignores FK clauses when the parent table uses MyISAM or when
 * the DB user lacks REFERENCES privilege. Querying information_schema after CREATE
 * is the only reliable way to confirm the constraints were applied.
 */
final class ConstraintVerifier
{
    private \wpdb $wpdb;

    public function __construct(\wpdb $wpdb)
    {
        $this->wpdb = $wpdb;
    }

    /**
     * Returns the tables from $expected that carry no FK to their expected
     * referenced table. Matched by table pair, never by constraint name, so it
     * accepts both InnoDB's generated names and the fixed names (fk_post_owner…)
     * that installs from 1.6.3 and earlier still carry.
     *
     * @param  array<string, string> $expected  table => referenced table
     * @return string[]  Tables missing their FK (empty array means all present).
     */
    public function findMissing(array $expected): array
    {
        if ($expected === []) {
            return [];
        }

        $tables       = array_keys($expected);
        $placeholders = implode(',', array_fill(0, count($tables), '%s'));

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders -- table names come from $wpdb and the plugin's schema map; every value goes through a placeholder.
        $rows = (array) $this->wpdb->get_results( // phpcs:ignore WordPress.DB
            $this->wpdb->prepare(
                "SELECT TABLE_NAME AS tbl, REFERENCED_TABLE_NAME AS ref
                 FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND REFERENCED_TABLE_NAME IS NOT NULL
                   AND TABLE_NAME IN ({$placeholders})",
                ...$tables
            ),
            ARRAY_A
        );
        // phpcs:enable

        // Lowercased: lower_case_table_names=1 hosts report names lowercased.
        $found = [];
        foreach ($rows as $row) {
            $found[strtolower($row['tbl'] . '>' . $row['ref'])] = true;
        }

        $missing = [];
        foreach ($expected as $table => $referenced) {
            if (! isset($found[strtolower($table . '>' . $referenced)])) {
                $missing[] = $table;
            }
        }

        return $missing;
    }
}
