<?php

namespace TransLangs\TranslationMemory\Infrastructure;

use TransLangs\TranslationMemory\Infrastructure\Schema\ConstraintVerifier;
use TransLangs\TranslationMemory\Infrastructure\Schema\SchemaFailure;
use TransLangs\TranslationMemory\Infrastructure\Schema\TableDefinitions;

/**
 * Schema orchestrator — install, verify, and version-gate the v4 TransLangs tables.
 *
 * v4 is a clean reset (no migration of old translation-memory data): legacy
 * split tables are dropped, then the two v4 tables (wp_tl_source,
 * wp_tl_translation) are created with raw DDL — never dbDelta() (D13). Parent
 * engines are checked first (§10) and every FK is verified after creation;
 * version is bumped only once verification passes.
 */
class TranslationMemorySchema
{
    public const DB_VERSION        = '10';
    public const OPTION_DB_VERSION = 'translangs_db_version';

    /** Legacy v3 split tables, dropped during the v4 clean reset (FK-safe order: children first). */
    private const LEGACY_TABLES = [
        'tl_translations_html',
        'tl_translations_i18n_meta',
        'tl_translations_i18n',
        'tl_translations_terms',
        'tl_translations_postmeta',
        'tl_translations_post',
        'tl_languages',
    ];

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
     * Full install: verify parent engines → drop legacy tables → create v4
     * tables → verify the three FKs → bump version.
     *
     * @throws SchemaFailure if a parent table is not InnoDB, or if any FK
     *                       constraint is absent after creation. Created v4
     *                       tables are dropped before throwing on FK failure.
     */
    public static function install(): void
    {
        global $wpdb;

        self::assertParentTablesAreInnoDB();

        $charsetCollate = $wpdb->get_charset_collate();

        // 1. Clean reset: drop every legacy split table (children before parents).
        foreach (self::LEGACY_TABLES as $suffix) {
            $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . $suffix); // phpcs:ignore WordPress.DB
        }

        // 2. Create wp_tl_source first — it is the FK target for translations.
        $wpdb->query( // phpcs:ignore WordPress.DB
            TableDefinitions::source(
                self::tlSourceTable(),
                $wpdb->posts,
                $wpdb->term_taxonomy,
                $charsetCollate
            )
        );

        // 3. Create wp_tl_translation, which FKs back to source_key.
        $wpdb->query( // phpcs:ignore WordPress.DB
            TableDefinitions::translation(
                self::tlTranslationTable(),
                self::tlSourceTable(),
                $charsetCollate
            )
        );

        // 4. Verify every v4 FK exists in information_schema.
        $missing = (new ConstraintVerifier($wpdb))->findMissing([
            'fk_src_post'  => self::tlSourceTable(),
            'fk_src_tt'    => self::tlSourceTable(),
            'fk_tr_source' => self::tlTranslationTable(),
        ]);

        // 5. On any missing FK: drop v4 tables in reverse dependency order, then fail.
        if ($missing !== []) {
            $wpdb->query('DROP TABLE IF EXISTS ' . self::tlTranslationTable()); // phpcs:ignore WordPress.DB
            $wpdb->query('DROP TABLE IF EXISTS ' . self::tlSourceTable());      // phpcs:ignore WordPress.DB

            throw SchemaFailure::missingForeignKeys($missing);
        }

        // 6. Version is written only after verification passes.
        update_option(self::OPTION_DB_VERSION, self::DB_VERSION);
    }

    /**
     * Abort before creating tables if wp_posts or wp_term_taxonomy is not
     * InnoDB — the FK chain that makes translation orphans impossible cannot be
     * built otherwise (§10). Throws a specific, human-readable SchemaFailure.
     */
    private static function assertParentTablesAreInnoDB(): void
    {
        global $wpdb;

        foreach ([$wpdb->posts, $wpdb->term_taxonomy] as $table) {
            $engine = self::engineOf($table);

            if ($engine !== null && strtoupper($engine) !== 'INNODB') {
                throw SchemaFailure::parentNotInnoDB($table, $engine);
            }
        }
    }

    /** Storage engine of one table per information_schema, or null if unknown. */
    private static function engineOf(string $table): ?string
    {
        global $wpdb;

        $engine = $wpdb->get_var( // phpcs:ignore WordPress.DB
            $wpdb->prepare(
                "SELECT ENGINE FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
                $table
            )
        );

        return is_string($engine) ? $engine : null;
    }

    /**
     * Set behavior options on first activation only (add_option is a no-op if they exist).
     * Source language is NOT stored — it comes from get_locale().
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

    public static function tlSourceTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'tl_source';
    }

    public static function tlTranslationTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'tl_translation';
    }
}
