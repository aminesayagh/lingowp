<?php

namespace TransLangs\TranslationMemory\Infrastructure;

use TransLangs\TranslationMemory\Infrastructure\Schema\ConstraintVerifier;
use TransLangs\TranslationMemory\Infrastructure\Schema\SchemaFailure;
use TransLangs\TranslationMemory\Infrastructure\Schema\TableDefinitions;

/**
 * Schema orchestrator — install, verify, and version-gate the v5 TransLangs tables.
 *
 * v5 splits the single packed table into ten content tables: five readable
 * PARENT source tables and five CHILD per-language translation tables. Created
 * with raw DDL — never dbDelta() (D13). Parent engines are checked first (§10)
 * and every FK is verified after creation; version is bumped only once
 * verification passes. There is no data migration here: pre-existing tables are
 * assumed dropped out of band, and CREATE TABLE IF NOT EXISTS is idempotent.
 */
class TranslationMemorySchema
{
    public const DB_VERSION        = '17';
    public const OPTION_DB_VERSION = 'translangs_db_version';

    /** Hash-link children (post/term/html/option): keyed (original_hash, lang_code), no FK. */
    private const HASH_LINK_CHILDREN = ['tl_post', 'tl_term', 'tl_html', 'tl_option'];

    /** parent_id children (meta/staticText): FK constraint name => parent table key. */
    private const PARENT_ID_CHILD_FKS = [
        'fk_meta_tr_parent'   => 'tl_meta',
        'fk_static_tr_parent' => 'tl_staticText',
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
     * Full install: verify parent engines → create five parents → create five
     * children → verify every FK → bump version.
     *
     * @throws SchemaFailure if a WordPress parent table is not InnoDB, or if any
     *                       FK constraint is absent after creation. Created v5
     *                       tables are dropped before throwing on FK failure.
     */
    public static function install(): void
    {
        global $wpdb;

        self::assertParentTablesAreInnoDB();
        self::dropStaleOptionTables();

        $cc = $wpdb->get_charset_collate();
        $p  = self::parentTables();

        // 1. Create the five parent tables first — they are the child FK targets.
        $wpdb->query(TableDefinitions::post($p['tl_post'], $wpdb->posts, $cc));                         // phpcs:ignore WordPress.DB
        $wpdb->query(TableDefinitions::meta($p['tl_meta'], $wpdb->posts, $cc));                         // phpcs:ignore WordPress.DB
        $wpdb->query(TableDefinitions::term($p['tl_term'], $wpdb->term_taxonomy, $cc));                 // phpcs:ignore WordPress.DB
        $wpdb->query(TableDefinitions::staticText($p['tl_staticText'], $cc));                           // phpcs:ignore WordPress.DB
        $wpdb->query(TableDefinitions::html($p['tl_html'], $cc));                                       // phpcs:ignore WordPress.DB
        $wpdb->query(TableDefinitions::option($p['tl_option'], $wpdb->options, $cc));                   // phpcs:ignore WordPress.DB

        // 2a. Hash-link children (post/term/html): keyed by original_hash, no FK.
        foreach (self::HASH_LINK_CHILDREN as $parentKey) {
            $wpdb->query( // phpcs:ignore WordPress.DB
                TableDefinitions::hashLinkTranslation($p[$parentKey] . '_translation', $cc)
            );
        }

        // 2b. parent_id children (meta/staticText): FK'd back to their parent id.
        foreach (self::PARENT_ID_CHILD_FKS as $fkName => $parentKey) {
            $wpdb->query( // phpcs:ignore WordPress.DB
                TableDefinitions::parentIdTranslation($p[$parentKey] . '_translation', $p[$parentKey], $fkName, $cc)
            );
        }

        // 3. Verify every FK exists in information_schema (parent owners + parent_id child links).
        $missing = (new ConstraintVerifier($wpdb))->findMissing(self::expectedForeignKeys());

        // 4. On any missing FK: drop v5 tables (children first), then fail.
        if ($missing !== []) {
            foreach ([...self::HASH_LINK_CHILDREN, ...array_values(self::PARENT_ID_CHILD_FKS)] as $parentKey) {
                $wpdb->query('DROP TABLE IF EXISTS ' . $p[$parentKey] . '_translation'); // phpcs:ignore WordPress.DB
            }
            foreach ($p as $table) {
                $wpdb->query('DROP TABLE IF EXISTS ' . $table); // phpcs:ignore WordPress.DB
            }

            throw SchemaFailure::missingForeignKeys($missing);
        }

        // 5. Version is written only after verification passes.
        update_option(self::OPTION_DB_VERSION, self::DB_VERSION);
    }

    /**
     * Every FK this schema must contain: three parent→WordPress owners plus the
     * two parent_id child→parent links. Hash-link children have no FK.
     *
     * @return array<string, string> constraint_name => table_name
     */
    public static function expectedForeignKeys(): array
    {
        $p        = self::parentTables();
        $expected = [
            'fk_post_owner'   => $p['tl_post'],
            'fk_meta_owner'   => $p['tl_meta'],
            'fk_term_owner'   => $p['tl_term'],
            'fk_option_owner' => $p['tl_option'],
        ];

        foreach (self::PARENT_ID_CHILD_FKS as $fkName => $parentKey) {
            $expected[$fkName] = $p[$parentKey] . '_translation';
        }

        return $expected;
    }

    /**
     * Abort before creating tables if wp_posts or wp_term_taxonomy is not
     * InnoDB — the FK chain that makes translation orphans impossible cannot be
     * built otherwise (§10). Throws a specific, human-readable SchemaFailure.
     */
    private static function assertParentTablesAreInnoDB(): void
    {
        global $wpdb;

        foreach ([$wpdb->posts, $wpdb->term_taxonomy, $wpdb->options] as $table) {
            $engine = self::engineOf($table);

            if ($engine !== null && strtoupper($engine) !== 'INNODB') {
                throw SchemaFailure::parentNotInnoDB($table, $engine);
            }
        }
    }

    /**
     * One-time migration: tl_option's child switched from the parent_id model to
     * hash-link. CREATE TABLE IF NOT EXISTS cannot reshape an existing table, so
     * if the child still carries the old `parent_id` column, drop both option
     * tables (child first for the FK) and let the install recreate them in the
     * current hash-link shape. Guarded — it never touches a correct table.
     */
    private static function dropStaleOptionTables(): void
    {
        global $wpdb;

        $child = $wpdb->prefix . 'tl_option_translation';

        $hasParentId = $wpdb->get_var( // phpcs:ignore WordPress.DB
            $wpdb->prepare(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'parent_id'",
                $child
            )
        );

        if ($hasParentId === 'parent_id') {
            $wpdb->query('DROP TABLE IF EXISTS ' . $child);                       // phpcs:ignore WordPress.DB
            $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'tl_option');  // phpcs:ignore WordPress.DB
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
     * The source language is captured ONCE here (v5 §1.1): the normalized site
     * locale is frozen into translangs_source_locale so runtime never reads the
     * filtered, mid-request-switchable get_locale().
     */
    public static function setDefaultOptions(): void
    {
        add_option('translangs_enabled_languages', []);
        add_option('translangs_prefix_default_language', false);
        add_option('translangs_cookie_preference', true);
        add_option('translangs_browser_detection', false);
        add_option('translangs_auto_redirect', false);
        add_option(
            \TransLangs\Language\Infrastructure\OptionLanguageRegistry::OPTION_SOURCE_LOCALE,
            \TransLangs\Language\Domain\LocaleNormalizer::current()
        );
    }

    // ── Table name helpers ────────────────────────────────────────────────────

    /** The five prefixed parent table names, keyed by their unprefixed suffix. */
    public static function parentTables(): array
    {
        global $wpdb;

        return [
            'tl_post'       => $wpdb->prefix . 'tl_post',
            'tl_meta'       => $wpdb->prefix . 'tl_meta',
            'tl_term'       => $wpdb->prefix . 'tl_term',
            'tl_staticText' => $wpdb->prefix . 'tl_staticText',
            'tl_html'       => $wpdb->prefix . 'tl_html',
            'tl_option'     => $wpdb->prefix . 'tl_option',
        ];
    }

    /** The five prefixed child (translation) table names, keyed by parent suffix. */
    public static function childTables(): array
    {
        $children = [];
        foreach (self::parentTables() as $key => $table) {
            $children[$key] = $table . '_translation';
        }

        return $children;
    }
}
