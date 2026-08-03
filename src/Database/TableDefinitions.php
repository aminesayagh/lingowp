<?php

namespace TransLangs\Database;

/**
 * Pure DDL definitions for the v5 TransLangs tables (parent_child_unique_review_v5).
 *
 * Thirteen content tables: six readable PARENT source tables (post, meta, term,
 * staticText, html, option), six CHILD translation tables in TWO key variants,
 * plus tl_staticPluralText which extends its staticText anchor:
 *   - hash-link children (post, term, html, option): keyed (original_hash, lang_code),
 *     no parent_id. original_hash is the SHA-256 of the normalized original text
 *     (the same value stored on the parent), so identical text shares one
 *     translation across every position that has it.
 *   - parent_id children (meta, staticText): keyed (parent_id, lang_code) —
 *     context can make identical text translate differently, so the translation
 *     is owned by the specific position.
 *
 *   parent.status  0 discovered · -1 ignored        (SIGNED tinyint)
 *   child.status   1 machine · 2 reviewed · -1 needs_review  (done = status > 0)
 *
 * There is no translated_against_hash: a source-text change deletes the stale
 * parent and seeds the new one's translation as needs_review (carry-forward),
 * so child status alone drives the admin queue.
 *
 * Languages stay option-backed (no languages table), so lang_code is a plain
 * column with no FK. Methods return CREATE TABLE SQL with FK clauses inlined —
 * never dbDelta() (it mangles FOREIGN KEY clauses).
 */
final class TableDefinitions
{
    private function __construct() {}

    /** wp_tl_post — identity (post_id, field, original_hash). post_content rows are fragments. */
    public static function post(string $table, string $postsTable, string $charsetCollate): string
    {
        return "CREATE TABLE IF NOT EXISTS {$table} (
    id                     bigint unsigned  NOT NULL AUTO_INCREMENT,
    post_id                bigint unsigned  NOT NULL,
    field                  varchar(32)      NOT NULL,
    original_hash          binary(32)       NOT NULL,
    original_text          longtext         NOT NULL,
    status                 tinyint          NOT NULL DEFAULT 0,
    created_at             datetime         NOT NULL,
    updated_at             datetime         NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_source (post_id, field, original_hash),
    KEY idx_hash (original_hash),
    KEY idx_queue (status, id),
    CONSTRAINT fk_post_owner FOREIGN KEY (post_id)
        REFERENCES {$postsTable} (ID) ON DELETE CASCADE
) ENGINE=InnoDB {$charsetCollate}";
    }

    /** wp_tl_meta — identity (post_id, meta_key_hash, context_hash, original_hash). */
    public static function meta(string $table, string $postsTable, string $charsetCollate): string
    {
        return "CREATE TABLE IF NOT EXISTS {$table} (
    id                     bigint unsigned  NOT NULL AUTO_INCREMENT,
    post_id                bigint unsigned  NOT NULL,
    meta_key               varchar(191)     NOT NULL,
    meta_key_hash          binary(32)       NOT NULL,
    context                text             NULL,
    context_hash           binary(32)       NOT NULL,
    original_hash          binary(32)       NOT NULL,
    original_text          longtext         NOT NULL,
    status                 tinyint          NOT NULL DEFAULT 0,
    created_at             datetime         NOT NULL,
    updated_at             datetime         NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_source (post_id, meta_key_hash, context_hash, original_hash),
    KEY idx_hash (original_hash),
    KEY idx_queue (status, id),
    CONSTRAINT fk_meta_owner FOREIGN KEY (post_id)
        REFERENCES {$postsTable} (ID) ON DELETE CASCADE
) ENGINE=InnoDB {$charsetCollate}";
    }

    /** wp_tl_option — identity (option_id, option_path_hash, original_hash). Owner FK to wp_options. */
    public static function option(string $table, string $optionsTable, string $charsetCollate): string
    {
        return "CREATE TABLE IF NOT EXISTS {$table} (
    id                     bigint unsigned  NOT NULL AUTO_INCREMENT,
    option_id              bigint unsigned  NOT NULL,
    option_name            varchar(191)     NOT NULL,
    option_path            text             NULL,
    option_path_hash       binary(32)       NOT NULL,
    original_hash          binary(32)       NOT NULL,
    original_text          longtext         NOT NULL,
    status                 tinyint          NOT NULL DEFAULT 0,
    created_at             datetime         NOT NULL,
    updated_at             datetime         NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_source (option_id, option_path_hash, original_hash),
    KEY idx_hash (original_hash),
    KEY idx_queue (status, id),
    CONSTRAINT fk_option_owner FOREIGN KEY (option_id)
        REFERENCES {$optionsTable} (option_id) ON DELETE CASCADE
) ENGINE=InnoDB {$charsetCollate}";
    }

    /** wp_tl_term — identity (term_taxonomy_id, field, original_hash). */
    public static function term(string $table, string $termTaxonomyTable, string $charsetCollate): string
    {
        return "CREATE TABLE IF NOT EXISTS {$table} (
    id                     bigint unsigned  NOT NULL AUTO_INCREMENT,
    term_taxonomy_id       bigint unsigned  NOT NULL,
    field                  varchar(32)      NOT NULL,
    original_hash          binary(32)       NOT NULL,
    original_text          longtext         NOT NULL,
    status                 tinyint          NOT NULL DEFAULT 0,
    created_at             datetime         NOT NULL,
    updated_at             datetime         NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_source (term_taxonomy_id, field, original_hash),
    KEY idx_hash (original_hash),
    KEY idx_queue (status, id),
    CONSTRAINT fk_term_owner FOREIGN KEY (term_taxonomy_id)
        REFERENCES {$termTaxonomyTable} (term_taxonomy_id) ON DELETE CASCADE
) ENGINE=InnoDB {$charsetCollate}";
    }

    /**
     * wp_tl_html — identity is original_hash alone. No content FK.
     *
     * The same text is exactly one row regardless of how many DOM locations
     * render it — the translation is already shared via the hash-link child
     * table, so a second parent row per location would only duplicate review
     * effort. selector/source_url are descriptive-only (admin preview/"field"
     * display), first-discovered values kept as-is except source_url, which
     * refreshHtmlSourceUrls() keeps current on rediscovery. See
     * HtmlSource's class doc for the full reasoning.
     *
     * last_seen_at is bumped by every discovery pass (marking mode and the
     * background crawl alike) that re-encounters a row's hash, distinct from
     * created_at/updated_at: it exists so a row that only a bounded crawl scope
     * or a scan failure would otherwise miss doesn't look abandoned just
     * because THIS pass didn't cover it — see HtmlDiscovery::discover().
     */
    public static function html(string $table, string $charsetCollate): string
    {
        return "CREATE TABLE IF NOT EXISTS {$table} (
    id                     bigint unsigned  NOT NULL AUTO_INCREMENT,
    selector               varchar(255)     NOT NULL,
    source_url             text             NULL,
    original_hash          binary(32)       NOT NULL,
    original_text          longtext         NOT NULL,
    status                 tinyint          NOT NULL DEFAULT 0,
    last_seen_at           datetime         NULL,
    created_at             datetime         NOT NULL,
    updated_at             datetime         NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_source (original_hash),
    KEY idx_queue (status, id)
) ENGINE=InnoDB {$charsetCollate}";
    }

    /**
     * Hash-link child (post, term, html): one translation per (original_hash,
     * lang_code), shared by every parent position with the same text. No
     * parent_id and no FK — the link is original_hash (idx_hash on the parent);
     * orphaned rows are reclaimed by the background sweep.
     */
    public static function hashLinkTranslation(string $table, string $charsetCollate): string
    {
        return "CREATE TABLE IF NOT EXISTS {$table} (
    id              bigint unsigned  NOT NULL AUTO_INCREMENT,
    original_hash   binary(32)       NOT NULL,
    lang_code       varchar(20)      NOT NULL,
    translated_text longtext         NOT NULL,
    status          tinyint          NOT NULL DEFAULT 1,
    provider        varchar(50)      NULL,
    created_at      datetime         NOT NULL,
    updated_at      datetime         NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_translation (original_hash, lang_code),
    KEY idx_queue (lang_code, status, id)
) ENGINE=InnoDB {$charsetCollate}";
    }

    /**
     * parent_id child (meta): one translation per (parent_id, lang_code). The
     * position owns its translation; parent deletion cascades.
     *
     * @param string $fkName unique FK constraint name for this child→parent link
     */
    public static function parentIdTranslation(
        string $table,
        string $parentTable,
        string $fkName,
        string $charsetCollate
    ): string {
        return "CREATE TABLE IF NOT EXISTS {$table} (
    id              bigint unsigned  NOT NULL AUTO_INCREMENT,
    parent_id       bigint unsigned  NOT NULL,
    lang_code       varchar(20)      NOT NULL,
    translated_text longtext         NOT NULL,
    status          tinyint          NOT NULL DEFAULT 1,
    provider        varchar(50)      NULL,
    created_at      datetime         NOT NULL,
    updated_at      datetime         NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_translation (parent_id, lang_code),
    KEY idx_queue (lang_code, status, id),
    CONSTRAINT {$fkName} FOREIGN KEY (parent_id)
        REFERENCES {$parentTable} (id) ON DELETE CASCADE
) ENGINE=InnoDB {$charsetCollate}";
    }
}
