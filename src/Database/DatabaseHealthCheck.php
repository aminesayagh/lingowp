<?php

namespace LingoWP\Database;

/**
 * Computes the current schema integrity state: storage engines, foreign
 * keys, translation row count, and the last orphan sweep run. Split out of
 * Admin\DatabaseHealthReport so Database owns its own health-check API
 * instead of Admin reaching into ConstraintVerifier/OrphanTranslationSweep/
 * TranslationMemorySchema internals directly.
 */
final class DatabaseHealthCheck
{
    /** Translation row count past which the deferred large-site indexes are suggested (§7, D21). */
    private const DEFERRED_INDEX_ROW_THRESHOLD = 500000;

    private \wpdb $wpdb;

    public function __construct(\wpdb $wpdb)
    {
        $this->wpdb = $wpdb;
    }

    /**
     * @return array{
     *     posts_engine: ?string,
     *     tt_engine: ?string,
     *     missing_fks: string[],
     *     translation_rows: int,
     *     deferred_indexes_suggested: bool,
     *     last_sweep: string
     * }
     */
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
