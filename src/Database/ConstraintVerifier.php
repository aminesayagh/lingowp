<?php

namespace LingoWP\Database;

final class ConstraintVerifier
{
    private \wpdb $wpdb;

    public function __construct(\wpdb $wpdb)
    {
        $this->wpdb = $wpdb;
    }

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
