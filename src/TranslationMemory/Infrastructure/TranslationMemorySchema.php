<?php

namespace TransLangs\TranslationMemory\Infrastructure;

use TransLangs\TranslationMemory\Domain\TranslationSourceType;

/**
 * Database schema and default options.
 *
 * Keeps all table SQL in one place so the Activator stays small and
 * future migrations have a single source of truth.
 */
class TranslationMemorySchema
{
    /**
     * Bump this when the table structure changes, so upgrades can re-run dbDelta.
     */
    public const DB_VERSION = '4';

    public const OPTION_DB_VERSION = 'translangs_db_version';

    /**
     * Create or update plugin tables using dbDelta.
     */
    public static function install(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charsetCollate = $wpdb->get_charset_collate();
        $table          = self::stringsTable();

        // dbDelta is strict about formatting: two spaces after PRIMARY KEY,
        // one field per line, lowercase types.
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,

            source_lang varchar(10) NOT NULL,
            target_lang varchar(10) NOT NULL,

            source_id bigint(20) unsigned NOT NULL DEFAULT 0,
            source_type varchar(50) NOT NULL,

            original_hash char(64) NOT NULL,
            original_text longtext NOT NULL,
            normalized_text longtext NULL,
            translated_text longtext NULL,

            context varchar(255) NOT NULL,

            status tinyint(3) unsigned NOT NULL DEFAULT 0,
            provider varchar(50) NULL,

            last_seen_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,

            PRIMARY KEY  (id),

            UNIQUE KEY uniq_translation (source_lang,target_lang,source_id,source_type,context(191),original_hash),
            KEY idx_original_hash (source_lang,target_lang,original_hash),
            KEY idx_lang_pair (source_lang,target_lang),
            KEY idx_source (source_type,source_id),
            KEY idx_status (status),
            KEY idx_last_seen_at (last_seen_at)
        ) {$charsetCollate};";

        dbDelta($sql);
        self::migrateIdentitySchema();

        update_option(self::OPTION_DB_VERSION, self::DB_VERSION);
    }

    public static function maybeUpgrade(): void
    {
        $installed = (string) get_option(self::OPTION_DB_VERSION, '0');

        if (version_compare($installed, self::DB_VERSION, '>=')) {
            return;
        }

        self::install();
    }

    private static function migrateIdentitySchema(): void
    {
        global $wpdb;

        $table              = self::stringsTable();
        $fallbackSourceType = TranslationSourceType::PAGE;

        if (self::indexExists($table, 'uniq_translation')) {
            $wpdb->query("ALTER TABLE {$table} DROP INDEX uniq_translation");
        }

        if (!self::columnExists($table, 'source_id')) {
            $wpdb->query("ALTER TABLE {$table} ADD source_id bigint(20) unsigned NOT NULL DEFAULT 0 AFTER target_lang");
        }

        if (!self::columnExists($table, 'source_type')) {
            $wpdb->query(
                $wpdb->prepare(
                    "ALTER TABLE {$table} ADD source_type varchar(20) NOT NULL DEFAULT %s AFTER source_id",
                    $fallbackSourceType
                )
            );
        }

        $wpdb->query("UPDATE {$table} SET source_id = 0 WHERE source_id IS NULL");
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET source_type = %s WHERE source_type IS NULL OR source_type = '' OR source_type = 'template'",
                $fallbackSourceType
            )
        );
        $wpdb->query("UPDATE {$table} SET context = '' WHERE context IS NULL");
        $wpdb->query("ALTER TABLE {$table} MODIFY source_id bigint(20) unsigned NOT NULL DEFAULT 0");
        $wpdb->query("ALTER TABLE {$table} MODIFY source_type varchar(20) NOT NULL");
        $wpdb->query("ALTER TABLE {$table} MODIFY context varchar(255) NOT NULL");

        if (self::columnExists($table, 'source_id_key')) {
            $wpdb->query("ALTER TABLE {$table} DROP COLUMN source_id_key");
        }

        if (self::columnExists($table, 'source_type_key')) {
            $wpdb->query("ALTER TABLE {$table} DROP COLUMN source_type_key");
        }

        $wpdb->query(
            "ALTER TABLE {$table}
             ADD UNIQUE KEY uniq_translation
             (source_lang,target_lang,source_id,source_type,context(191),original_hash)"
        );

        if (!self::indexExists($table, 'idx_source')) {
            $wpdb->query("ALTER TABLE {$table} ADD KEY idx_source (source_type,source_id)");
        }

        if (self::columnExists($table, 'source_url')) {
            $wpdb->query("ALTER TABLE {$table} DROP COLUMN source_url");
        }
    }

    private static function columnExists(string $table, string $column): bool
    {
        global $wpdb;

        return (bool) $wpdb->get_var(
            $wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $column)
        );
    }

    private static function indexExists(string $table, string $index): bool
    {
        global $wpdb;

        return (bool) $wpdb->get_var(
            $wpdb->prepare("SHOW INDEX FROM {$table} WHERE Key_name = %s", $index)
        );
    }

    /**
     * Set default options only if they do not already exist.
     */
    public static function setDefaultOptions(): void
    {
        add_option('translangs_default_language', 'en');
        add_option('translangs_enabled_languages', ['en', 'fr']);
        add_option('translangs_prefix_default_language', false);
        add_option('translangs_cookie_preference', true);
        add_option('translangs_browser_detection', false);
        add_option('translangs_auto_redirect', false);
    }

    /**
     * Fully qualified strings table name.
     */
    public static function stringsTable(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'translangs_strings';
    }
}
