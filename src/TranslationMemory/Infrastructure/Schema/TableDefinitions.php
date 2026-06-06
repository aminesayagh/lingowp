<?php

namespace TransLangs\TranslationMemory\Infrastructure\Schema;

/**
 * Pure DDL definitions for all TransLangs tables.
 *
 * Methods return CREATE TABLE IF NOT EXISTS SQL strings with all FK clauses
 * inlined. No execution logic here — the orchestrator calls $wpdb->query().
 *
 * All table and reference names are passed in as concrete strings so this class
 * has no global state and is trivially testable.
 */
final class TableDefinitions
{
    private function __construct() {}

    public static function languages(string $table, string $charsetCollate): string
    {
        return "CREATE TABLE IF NOT EXISTS {$table} (
    code         varchar(10)  NOT NULL,
    name         varchar(100) NOT NULL,
    native_name  varchar(100) NOT NULL DEFAULT '',
    direction    varchar(3)   NOT NULL DEFAULT 'ltr',
    status       tinyint(1)   NOT NULL DEFAULT 1,
    sort_order   int          NOT NULL DEFAULT 0,
    created_at   datetime     NOT NULL,
    updated_at   datetime     NOT NULL,
    PRIMARY KEY (code)
) ENGINE=InnoDB {$charsetCollate}";
    }

    public static function post(
        string $table,
        string $languagesTable,
        string $postsTable,
        string $charsetCollate
    ): string {
        return "CREATE TABLE IF NOT EXISTS {$table} (
    id              bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    post_id         bigint(20) unsigned NOT NULL,
    target_lang     varchar(10)         NOT NULL,
    field           varchar(32)         NOT NULL,
    original_hash   binary(32)          NOT NULL,
    original_text   longtext            NOT NULL,
    translated_text longtext            NULL,
    status          tinyint(3) unsigned NOT NULL DEFAULT 0,
    provider        varchar(50)         NULL,
    created_at      datetime            NOT NULL,
    updated_at      datetime            NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_identity (target_lang, post_id, field, original_hash),
    KEY idx_lang_status (target_lang, status),
    KEY idx_same_original (target_lang, original_hash),
    CONSTRAINT fk_post_parent
        FOREIGN KEY (post_id) REFERENCES {$postsTable} (ID) ON DELETE CASCADE,
    CONSTRAINT fk_post_lang
        FOREIGN KEY (target_lang) REFERENCES {$languagesTable} (code) ON DELETE CASCADE
) ENGINE=InnoDB {$charsetCollate}";
    }

    public static function postmeta(
        string $table,
        string $languagesTable,
        string $postsTable,
        string $charsetCollate
    ): string {
        return "CREATE TABLE IF NOT EXISTS {$table} (
    id              bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    post_id         bigint(20) unsigned NOT NULL,
    target_lang     varchar(10)         NOT NULL,
    meta_key        varchar(191)        NOT NULL,
    meta_key_hash   binary(32)          NOT NULL,
    context         text                NULL,
    context_hash    binary(32)          NOT NULL,
    original_hash   binary(32)          NOT NULL,
    original_text   longtext            NOT NULL,
    translated_text longtext            NULL,
    status          tinyint(3) unsigned NOT NULL DEFAULT 0,
    provider        varchar(50)         NULL,
    created_at      datetime            NOT NULL,
    updated_at      datetime            NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_identity (target_lang, post_id, meta_key_hash, context_hash, original_hash),
    KEY idx_lang_status (target_lang, status),
    KEY idx_same_original (target_lang, original_hash),
    CONSTRAINT fk_postmeta_parent
        FOREIGN KEY (post_id) REFERENCES {$postsTable} (ID) ON DELETE CASCADE,
    CONSTRAINT fk_postmeta_lang
        FOREIGN KEY (target_lang) REFERENCES {$languagesTable} (code) ON DELETE CASCADE
) ENGINE=InnoDB {$charsetCollate}";
    }

    public static function terms(
        string $table,
        string $languagesTable,
        string $termTaxonomyTable,
        string $charsetCollate
    ): string {
        return "CREATE TABLE IF NOT EXISTS {$table} (
    id               bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    term_taxonomy_id bigint(20) unsigned NOT NULL,
    target_lang      varchar(10)         NOT NULL,
    field            varchar(32)         NOT NULL,
    original_hash    binary(32)          NOT NULL,
    original_text    longtext            NOT NULL,
    translated_text  longtext            NULL,
    status           tinyint(3) unsigned NOT NULL DEFAULT 0,
    provider         varchar(50)         NULL,
    created_at       datetime            NOT NULL,
    updated_at       datetime            NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_identity (target_lang, term_taxonomy_id, field, original_hash),
    KEY idx_lang_status (target_lang, status),
    KEY idx_same_original (target_lang, original_hash),
    CONSTRAINT fk_term_parent
        FOREIGN KEY (term_taxonomy_id) REFERENCES {$termTaxonomyTable} (term_taxonomy_id) ON DELETE CASCADE,
    CONSTRAINT fk_term_lang
        FOREIGN KEY (target_lang) REFERENCES {$languagesTable} (code) ON DELETE CASCADE
) ENGINE=InnoDB {$charsetCollate}";
    }

    public static function i18n(
        string $table,
        string $languagesTable,
        string $charsetCollate
    ): string {
        return "CREATE TABLE IF NOT EXISTS {$table} (
    id              bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    target_lang     varchar(10)         NOT NULL,
    source          varchar(20)         NOT NULL,
    reference       varchar(191)        NOT NULL DEFAULT '',
    reference_hash  binary(32)          NOT NULL,
    original_hash   binary(32)          NOT NULL,
    original_text   longtext            NOT NULL,
    translated_text longtext            NULL,
    status          tinyint(3) unsigned NOT NULL DEFAULT 0,
    provider        varchar(50)         NULL,
    created_at      datetime            NOT NULL,
    updated_at      datetime            NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_identity (target_lang, source, reference_hash, original_hash),
    KEY idx_lang_status (target_lang, status),
    KEY idx_reference (reference_hash),
    CONSTRAINT fk_i18n_lang
        FOREIGN KEY (target_lang) REFERENCES {$languagesTable} (code) ON DELETE CASCADE
) ENGINE=InnoDB {$charsetCollate}";
    }

    public static function i18nMeta(
        string $table,
        string $i18nTable,
        string $charsetCollate
    ): string {
        return "CREATE TABLE IF NOT EXISTS {$table} (
    i18n_id       bigint(20) unsigned NOT NULL,
    source_url    text                NULL,
    selector      text                NULL,
    request_area  varchar(20)         NULL,
    seen_count    bigint(20) unsigned NOT NULL DEFAULT 0,
    first_seen_at datetime            NOT NULL,
    last_seen_at  datetime            NOT NULL,
    PRIMARY KEY (i18n_id),
    CONSTRAINT fk_i18n_meta
        FOREIGN KEY (i18n_id) REFERENCES {$i18nTable} (id) ON DELETE CASCADE
) ENGINE=InnoDB {$charsetCollate}";
    }
}
