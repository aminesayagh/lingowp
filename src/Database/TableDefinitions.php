<?php

namespace LingoWP\Database;

final class TableDefinitions
{
    private function __construct() {}

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
    CONSTRAINT fk_term_meta_owner FOREIGN KEY (term_id)
        REFERENCES {$termsTable} (term_id) ON DELETE CASCADE
) ENGINE=InnoDB {$charsetCollate}";
    }

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
    CONSTRAINT fk_user_owner FOREIGN KEY (user_id)
        REFERENCES {$usersTable} (ID) ON DELETE CASCADE
) ENGINE=InnoDB {$charsetCollate}";
    }

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

    public static function parentIdTranslation(
        string $table,
        string $parentTable,
        string $fkName,
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
    CONSTRAINT {$fkName} FOREIGN KEY (parent_id)
        REFERENCES {$parentTable} (id) ON DELETE CASCADE
) ENGINE=InnoDB {$charsetCollate}";
    }

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
