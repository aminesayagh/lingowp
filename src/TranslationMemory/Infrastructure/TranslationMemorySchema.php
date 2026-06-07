<?php

namespace TransLangs\TranslationMemory\Infrastructure;

use TransLangs\TranslationMemory\Infrastructure\Schema\ConstraintVerifier;
use TransLangs\TranslationMemory\Infrastructure\Schema\SchemaFailure;
use TransLangs\TranslationMemory\Infrastructure\Schema\TableDefinitions;

/**
 * Schema orchestrator — install, verify, and version-gate the TransLangs tables.
 *
 * Delegates SQL to TableDefinitions; delegates FK verification to ConstraintVerifier.
 * Never uses dbDelta. Version is bumped only after FK verification passes.
 */
class TranslationMemorySchema
{
    public const DB_VERSION        = '7';
    public const OPTION_DB_VERSION = 'translangs_db_version';

    public static function maybeUpgrade(): void
    {
        // If the version is current or higher, do nothing. Otherwise, run a full install.
        $installed = (string) get_option(self::OPTION_DB_VERSION, '0');

        if (version_compare($installed, self::DB_VERSION, '>=')) {
            return;
        }

        self::install();
    }

    /**
     * Full install: create registry + translation tables → verify FKs → bump version.
     *
     * @throws SchemaFailure if any FK constraint is absent after creation.
     *                       All created tables are dropped before throwing.
     */
    public static function install(): void
    {
        global $wpdb;

        $charsetCollate = $wpdb->get_charset_collate();

        // 1. Create language registry first — it is the FK target for all translation tables.
        $wpdb->query( // phpcs:ignore WordPress.DB
            TableDefinitions::languages(self::tlLanguagesTable(), $charsetCollate)
        );

        // 2. Create the four translation tables with inline FK clauses.
        $wpdb->query( // phpcs:ignore WordPress.DB
            TableDefinitions::post(
                self::tlPostTable(),
                self::tlLanguagesTable(),
                $wpdb->posts,
                $charsetCollate
            )
        );
        $wpdb->query( // phpcs:ignore WordPress.DB
            TableDefinitions::postmeta(
                self::tlPostmetaTable(),
                self::tlLanguagesTable(),
                $wpdb->posts,
                $charsetCollate
            )
        );
        $wpdb->query( // phpcs:ignore WordPress.DB
            TableDefinitions::terms(
                self::tlTermsTable(),
                self::tlLanguagesTable(),
                $wpdb->term_taxonomy,
                $charsetCollate
            )
        );
        $wpdb->query( // phpcs:ignore WordPress.DB
            TableDefinitions::i18n(
                self::tlI18nTable(),
                self::tlLanguagesTable(),
                $charsetCollate
            )
        );
        $wpdb->query( // phpcs:ignore WordPress.DB
            TableDefinitions::i18nMeta(
                self::tlI18nMetaTable(),
                self::tlI18nTable(),
                $charsetCollate
            )
        );

        self::ensureOwnedHashIndexes();

        // 3. Verify every FK exists in information_schema.
        $missing = (new ConstraintVerifier($wpdb))->findMissing([
            'fk_post_parent'     => self::tlPostTable(),
            'fk_post_lang'       => self::tlPostTable(),
            'fk_postmeta_parent' => self::tlPostmetaTable(),
            'fk_postmeta_lang'   => self::tlPostmetaTable(),
            'fk_term_parent'     => self::tlTermsTable(),
            'fk_term_lang'       => self::tlTermsTable(),
            'fk_i18n_lang'       => self::tlI18nTable(),
            'fk_i18n_meta'       => self::tlI18nMetaTable(),
        ]);

        // 4. On any missing FK: drop translation tables in reverse dependency order, then fail.
        if ($missing !== []) {
            $wpdb->query('DROP TABLE IF EXISTS ' . self::tlI18nMetaTable()); // phpcs:ignore WordPress.DB
            $wpdb->query('DROP TABLE IF EXISTS ' . self::tlI18nTable());     // phpcs:ignore WordPress.DB
            $wpdb->query('DROP TABLE IF EXISTS ' . self::tlTermsTable());    // phpcs:ignore WordPress.DB
            $wpdb->query('DROP TABLE IF EXISTS ' . self::tlPostmetaTable()); // phpcs:ignore WordPress.DB
            $wpdb->query('DROP TABLE IF EXISTS ' . self::tlPostTable());     // phpcs:ignore WordPress.DB
            $wpdb->query('DROP TABLE IF EXISTS ' . self::tlLanguagesTable()); // phpcs:ignore WordPress.DB

            throw SchemaFailure::missingForeignKeys($missing);
        }

        // 5. Version is written only after verification passes.
        update_option(self::OPTION_DB_VERSION, self::DB_VERSION);
    }

    private static function ensureOwnedHashIndexes(): void
    {
        self::addIndexIfMissing(self::tlPostTable(), 'idx_original_hash', 'original_hash');
        self::addIndexIfMissing(self::tlPostmetaTable(), 'idx_original_hash', 'original_hash');
        self::addIndexIfMissing(self::tlTermsTable(), 'idx_original_hash', 'original_hash');
    }

    private static function addIndexIfMissing(string $table, string $index, string $column): void
    {
        global $wpdb;

        $exists = $wpdb->get_var( // phpcs:ignore WordPress.DB
            $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SHOW INDEX FROM {$table} WHERE Key_name = %s",
                $index
            )
        );

        if ($exists !== null) {
            return;
        }

        $wpdb->query("ALTER TABLE {$table} ADD KEY {$index} ({$column})"); // phpcs:ignore WordPress.DB
    }

    /**
     * Set behavior options on first activation only (add_option is a no-op if they exist).
     * Source language is NOT stored — it comes from get_locale().
     * translangs_enabled_languages is kept for backward-compat seeding only.
     */
    public static function setDefaultOptions(): void
    {
        add_option('translangs_enabled_languages', []);
        add_option('translangs_prefix_default_language', false);
        add_option('translangs_cookie_preference', true);
        add_option('translangs_browser_detection', false);
        add_option('translangs_auto_redirect', false);
    }

    // ── Table name helpers ────────────────────────────────────────────────────

    public static function tlLanguagesTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'tl_languages';
    }

    public static function tlPostTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'tl_translations_post';
    }

    public static function tlPostmetaTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'tl_translations_postmeta';
    }

    public static function tlTermsTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'tl_translations_terms';
    }

    public static function tlI18nTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'tl_translations_i18n';
    }

    public static function tlI18nMetaTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'tl_translations_i18n_meta';
    }
}
