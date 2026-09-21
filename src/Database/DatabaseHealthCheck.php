<?php

namespace LingoWP\Database;

final class DatabaseHealthCheck
{
    private const DEFERRED_INDEX_ROW_THRESHOLD = 500000;

    private \wpdb $wpdb;

    public function __construct(\wpdb $wpdb)
    {
        $this->wpdb = $wpdb;
    }

    public function gather(): array
    {
        $missing = (new ConstraintVerifier($this->wpdb))->findMissing(
            TranslationMemorySchema::expectedForeignKeys()
        );

        $rows = 0;
        foreach (TranslationMemorySchema::childTables() as $table) {
            $rows += (int) $this->wpdb->get_var('SELECT COUNT(*) FROM ' . $table); // nosemgrep: wpdb-interpolated-sql -- table name only, from the TranslationMemorySchema map / $wpdb prefix; no values interpolated
        }

        return [
            'posts_engine'               => $this->engineOf($this->wpdb->posts),
            'tt_engine'                  => $this->engineOf($this->wpdb->term_taxonomy),
            'missing_fks'                => $missing,
            'translation_rows'           => $rows,
            'deferred_indexes_suggested' => $rows > self::DEFERRED_INDEX_ROW_THRESHOLD,
            'last_sweep'                 => (string) get_option(OrphanTranslationSweep::OPTION_LAST_RUN, ''),
        ];
    }

    private function engineOf(string $table): ?string
    {
        $engine = $this->wpdb->get_var( // phpcs:ignore WordPress.DB
            $this->wpdb->prepare(
                "SELECT ENGINE FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
                $table
            )
        );

        return is_string($engine) ? $engine : null;
    }
}
