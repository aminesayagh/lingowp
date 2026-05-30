<?php

namespace TransLangs\TranslationMemory\Infrastructure;

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
    public const DB_VERSION = '1';

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
            original_hash char(64) NOT NULL,
            original_text longtext NOT NULL,
            normalized_text longtext NULL,
            translated_text longtext NULL,
            context varchar(255) NULL,
            source_url text NULL,
            status tinyint(3) unsigned NOT NULL DEFAULT 0,
            provider varchar(50) NULL,
            last_seen_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_translation (source_lang,target_lang,original_hash),
            KEY idx_lang_pair (source_lang,target_lang),
            KEY idx_status (status),
            KEY idx_last_seen_at (last_seen_at)
        ) {$charsetCollate};";

        dbDelta($sql);

        update_option(self::OPTION_DB_VERSION, self::DB_VERSION);
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