<?php

namespace LingoWP\Database;

/**
 * Pure DDL definitions for the LingoWP content tables.
 *
 * Fourteen content tables: seven readable PARENT source tables (post, meta, term,
 * html, option, user, term_meta), each with its own CHILD translation table, in TWO key
 * variants:
 *   - hash-link children (post, term, html, option, user): keyed (original_hash, lang_code),
 *     no parent_id. original_hash is the SHA-256 of the normalized original text
 *     (the same value stored on the parent), so identical text shares one
 *     translation across every position that has it.
 *   - parent_id children (meta, term_meta): keyed (parent_id, lang_code) —
 *     context can make identical text translate differently, so the translation
 *     is owned by the specific position.
 *
 * original_hash is also the join key the async AI translate-all queue uses to
 * match a delivered result back to its row: the backend hashes on this exact
 * normalized form too (see StringNormalizer::normalize()'s doc — it names the
 * Python port and calls out that changing either side without the other
 * silently breaks every future lookup). Do not change this to a byte-exact
 * hash of the raw text without updating that port in lockstep.
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
 *
 * FKs are deliberately unnamed: MySQL constraint names are unique per
 * DATABASE, so a fixed name (fk_post_owner) made every multisite site after
 * the first fail to install. InnoDB names them `<table>_ibfk_N`, unique by
 * construction; ConstraintVerifier checks them by table, not by name.
 *
 * An eighth table, gettextUnit(), sits outside this parent/child model
 * entirely: it tracks original strings + ignore status for the Plugins &
 * Themes (gettext) board, which has no per-language child table at all —
 * translated text stays in `.mo` files, never in the database. See its own
 * doc for why.
 */
final class TableDefinitions
{
    private function __construct() {}

    /** wp_lingowp_post — identity (post_id, field, original_hash). post_content rows are fragments. */
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
    FOREIGN KEY (post_id)
        REFERENCES {$postsTable} (ID) ON DELETE CASCADE
) ENGINE=InnoDB {$charsetCollate}";
    }

    /** wp_lingowp_meta — identity (post_id, meta_key_hash, context_hash, original_hash). */
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
    FOREIGN KEY (post_id)
        REFERENCES {$postsTable} (ID) ON DELETE CASCADE
) ENGINE=InnoDB {$charsetCollate}";
    }

    /**
     * wp_lingowp_term_meta — identity (term_id, meta_key_hash, context_hash, original_hash).
     * Owner FK to wp_terms(term_id) — matches get_term_meta()/update_term_meta()'s own
     * relationship, distinct from wp_lingowp_term's FK to wp_term_taxonomy.
     */
    public static function termMeta(string $table, string $termsTable, string $charsetCollate): string
    {
        return "CREATE TABLE IF NOT EXISTS {$table} (
    id                     bigint unsigned  NOT NULL AUTO_INCREMENT,
    term_id                bigint unsigned  NOT NULL,
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
    UNIQUE KEY uniq_source (term_id, meta_key_hash, context_hash, original_hash),
    KEY idx_hash (original_hash),
    KEY idx_queue (status, id),
    FOREIGN KEY (term_id)
        REFERENCES {$termsTable} (term_id) ON DELETE CASCADE
) ENGINE=InnoDB {$charsetCollate}";
    }

    /** wp_lingowp_option — identity (option_id, option_path_hash, original_hash). Owner FK to wp_options. */
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
    FOREIGN KEY (option_id)
        REFERENCES {$optionsTable} (option_id) ON DELETE CASCADE
) ENGINE=InnoDB {$charsetCollate}";
    }

    /** wp_lingowp_term — identity (term_taxonomy_id, field, original_hash). */
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
    FOREIGN KEY (term_taxonomy_id)
        REFERENCES {$termTaxonomyTable} (term_taxonomy_id) ON DELETE CASCADE
) ENGINE=InnoDB {$charsetCollate}";
    }

    /** wp_lingowp_user — identity (user_id, field, original_hash). Owner FK to wp_users. */
    public static function user(string $table, string $usersTable, string $charsetCollate): string
    {
        return "CREATE TABLE IF NOT EXISTS {$table} (
    id                     bigint unsigned  NOT NULL AUTO_INCREMENT,
    user_id                bigint unsigned  NOT NULL,
    field                  varchar(32)      NOT NULL,
    original_hash          binary(32)       NOT NULL,
    original_text          longtext         NOT NULL,
    status                 tinyint          NOT NULL DEFAULT 0,
    created_at             datetime         NOT NULL,
    updated_at             datetime         NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_source (user_id, field, original_hash),
    KEY idx_hash (original_hash),
    KEY idx_queue (status, id),
    FOREIGN KEY (user_id)
        REFERENCES {$usersTable} (ID) ON DELETE CASCADE
) ENGINE=InnoDB {$charsetCollate}";
    }

    /**
     * wp_lingowp_html — identity is original_hash alone. No content FK.
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
    id                bigint unsigned  NOT NULL AUTO_INCREMENT,
    original_hash     binary(32)       NOT NULL,
    lang_code         varchar(20)      NOT NULL,
    translated_text   longtext         NULL,
    status            tinyint          NOT NULL DEFAULT 1,
    provider          varchar(50)      NULL,
    ai_pending_since  datetime         NULL,
    ai_error          varchar(190)     NULL,
    created_at        datetime         NOT NULL,
    updated_at        datetime         NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_translation (original_hash, lang_code),
    KEY idx_queue (lang_code, status, id),
    KEY idx_ai_pending (ai_pending_since)
) ENGINE=InnoDB {$charsetCollate}";
    }

    /**
     * parent_id child (meta): one translation per (parent_id, lang_code). The
     * position owns its translation; parent deletion cascades.
     */
    public static function parentIdTranslation(
        string $table,
        string $parentTable,
        string $charsetCollate
    ): string {
        return "CREATE TABLE IF NOT EXISTS {$table} (
    id                bigint unsigned  NOT NULL AUTO_INCREMENT,
    parent_id         bigint unsigned  NOT NULL,
    lang_code         varchar(20)      NOT NULL,
    translated_text   longtext         NULL,
    status            tinyint          NOT NULL DEFAULT 1,
    provider          varchar(50)      NULL,
    ai_pending_since  datetime         NULL,
    ai_error          varchar(190)     NULL,
    created_at        datetime         NOT NULL,
    updated_at        datetime         NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_translation (parent_id, lang_code),
    KEY idx_queue (lang_code, status, id),
    KEY idx_ai_pending (ai_pending_since),
    FOREIGN KEY (parent_id)
        REFERENCES {$parentTable} (id) ON DELETE CASCADE
) ENGINE=InnoDB {$charsetCollate}";
    }

    /**
     * wp_lingowp_gettext_unit — original strings + ignore status for the
     * Plugins & Themes board, one row per (type, domain, unit_hash). No FK
     * (a plugin/theme isn't a row in any WP table), no per-language child —
     * translated text is never stored here, it stays in the domain's `.mo`
     * file on disk; this table only replaces the full-source AST re-scan
     * that used to run on every read.
     *
     * unit_hash is deliberately NOT original_hash's SHA-256-of-normalized-text
     * contract (see StringNormalizer's doc): gettext msgid lookups everywhere
     * else in this codebase (.mo Translation_Entry::key(), the old
     * GettextIgnoredUnitsStore) are byte-exact and case-sensitive. Normalizing
     * here would silently collide distinct strings like "Save" and "save"
     * into one row. Formula instead matches WP core's own key shape exactly:
     * SHA256(context !== '' ? context."\x04".msgid : msgid).
     *
     * status is global per string, not per locale (0 = active, -1 = ignored)
     * — ignoring a string hides it from every target locale of that domain at
     * once, matching how every other table's parent status already treats
     * "ignored" as a source-level property independent of target language.
     *
     * last_seen_at is bumped by every scan/refresh that re-encounters a row's
     * hash, same purpose as html()'s own last_seen_at — distinguishes "still
     * in source" from "gone", without touching status (an ignored row that's
     * still in source stays ignored across rescans).
     *
     * The ai_* columns drive the board's async "Translate All" queue
     * (GettextAiQueue). Still one row per source string, still locale-blind:
     * ai_pending_locales is a pipe-list ("fr_FR|en_GB") of targets with an AI
     * request in flight; ai_pending_since anchors the 6h freshness TTL;
     * ai_error is the last failure code (single value, no per-locale key).
     * norm_hash is SHA-256 of StringNormalizer::normalize(msgid) — the hash
     * the backend echoes as source_hash, distinct from the byte-exact
     * unit_hash; NULL until this string's first AI submit.
     */
    public static function gettextUnit(string $table, string $charsetCollate): string
    {
        return "CREATE TABLE IF NOT EXISTS {$table} (
    id                     bigint unsigned  NOT NULL AUTO_INCREMENT,
    type                   varchar(10)      NOT NULL,
    domain                 varchar(191)     NOT NULL,
    unit_hash              binary(32)       NOT NULL,
    norm_hash              binary(32)       NULL,
    msgid                  longtext         NOT NULL,
    context                text             NULL,
    status                 tinyint          NOT NULL DEFAULT 0,
    ai_pending_locales     varchar(255)     NOT NULL DEFAULT '',
    ai_pending_since       datetime         NULL,
    ai_error               varchar(190)     NULL,
    last_seen_at           datetime         NULL,
    created_at             datetime         NOT NULL,
    updated_at             datetime         NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_unit (type, domain, unit_hash),
    KEY idx_domain_status (type, domain, status, id),
    KEY idx_ai_pending (ai_pending_since),
    KEY idx_ai_norm (norm_hash)
) ENGINE=InnoDB {$charsetCollate}";
    }
}
