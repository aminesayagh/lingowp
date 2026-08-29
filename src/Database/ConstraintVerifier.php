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
    public function __construct(private \wpdb $wpdb) {}

    /**
     * Returns constraint names from $expected that are absent in information_schema.
     *
     * @param  array<string, string> $expected  constraint_name => table_name
     * @return string[]  Missing constraint names (empty array means all present).
     */
    public function findMissing(array $expected): array
    {
        if ($expected === []) {
            return [];
        }

        $tables       = array_unique(array_values($expected));
        $placeholders = implode(',', array_fill(0, count($tables), '%s'));

        $rows = (array) $this->wpdb->get_results( // phpcs:ignore WordPress.DB
            $this->wpdb->prepare(
                "SELECT CONSTRAINT_NAME
                 FROM information_schema.TABLE_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE()
                   AND CONSTRAINT_TYPE = 'FOREIGN KEY'
                   AND TABLE_NAME IN ({$placeholders})",
                ...$tables
            ),
            ARRAY_A
        );

        return array_values(array_diff(array_keys($expected), array_column($rows, 'CONSTRAINT_NAME')));
    }
}
