<?php

namespace TransLangs\TranslationMemory\Infrastructure\Schema;

/**
 * Pure DDL definitions for the v4 TransLangs tables (architecture §2).
 *
 * Two tables only:
 *   - wp_tl_source       one row per translatable source string of every type
 *   - wp_tl_translation  one row per (source_key, lang_id); absence = untranslated
 *
 * Methods return CREATE TABLE SQL with every FK clause inlined. No execution
 * logic here — the orchestrator calls $wpdb->query() with raw DDL (never
 * dbDelta(), which mangles FOREIGN KEY clauses, D13). All table and reference
 * names are passed in as concrete strings so this class has no global state and
 * is trivially testable.
 */
final class TableDefinitions
{
    private function __construct() {}

    /**
     * wp_tl_source — every source string, identified by the §1 binary key.
     * FKs to wp_posts (types 1-2) and wp_term_taxonomy (type 3) give the
     * engine-enforced deletion chain; types 4-5 have NULL owners (no parent).
     */
    public static function source(
        string $table,
        string $postsTable,
        string $termTaxonomyTable,
        string $charsetCollate
    ): string {
        return "CREATE TABLE IF NOT EXISTS {$table} (
    id               bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    source_key       binary(32)          NOT NULL,
    type             tinyint unsigned    NOT NULL,
    post_id          bigint(20) unsigned NULL,
    term_taxonomy_id bigint(20) unsigned NULL,
    ref1             varchar(191)        NOT NULL,
    ref2             text                NULL,
    original_hash    binary(32)          NOT NULL,
    original_text    longtext            NOT NULL,
    status           tinyint unsigned    NOT NULL DEFAULT 0,
    source_url       text                NULL,
    created_at       datetime            NOT NULL,
    updated_at       datetime            NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_key (source_key),
    KEY idx_post (post_id),
    KEY idx_tt (term_taxonomy_id),
    KEY idx_admin_queue (status, updated_at),
    CONSTRAINT fk_src_post FOREIGN KEY (post_id)
        REFERENCES {$postsTable} (ID) ON DELETE CASCADE,
    CONSTRAINT fk_src_tt FOREIGN KEY (term_taxonomy_id)
        REFERENCES {$termTaxonomyTable} (term_taxonomy_id) ON DELETE CASCADE
) ENGINE=InnoDB {$charsetCollate}";
    }

    /**
     * wp_tl_translation — one row per produced (source, language) pair.
     * The clustered PK (source_key, lang_id) is the only index: it serves
     * render dives, FK satisfaction, cascade ranges, and prefix scans (§2).
     */
    public static function translation(
        string $table,
        string $sourceTable,
        string $charsetCollate
    ): string {
        return "CREATE TABLE IF NOT EXISTS {$table} (
    source_key      binary(32)       NOT NULL,
    lang_id         tinyint unsigned NOT NULL,
    translated_text longtext         NOT NULL,
    status          tinyint unsigned NOT NULL DEFAULT 1,
    provider_id     tinyint unsigned NULL,
    created_at      datetime         NOT NULL,
    updated_at      datetime         NOT NULL,
    PRIMARY KEY (source_key, lang_id),
    CONSTRAINT fk_tr_source FOREIGN KEY (source_key)
        REFERENCES {$sourceTable} (source_key) ON DELETE CASCADE
) ENGINE=InnoDB {$charsetCollate}";
    }
}
