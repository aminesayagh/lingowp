<?php

namespace TransLangs\Database;

use TransLangs\Database\ConstraintVerifier;
use TransLangs\Database\SchemaFailure;
use TransLangs\Database\TableDefinitions;

/**
 * Schema orchestrator — install, verify, and version-gate the TransLangs tables.
 *
 * Ten content tables: five readable PARENT source tables and five CHILD
 * per-language translation tables. Created with raw DDL — never dbDelta().
 * Parent engines are checked first, and every FK is verified after creation;
 * version is bumped only once verification passes. There is no data migration
 * here: pre-existing tables are assumed dropped out of band, and
 * CREATE TABLE IF NOT EXISTS is idempotent.
 */
class TranslationMemorySchema
{
    public const DB_VERSION        = '23';
    public const OPTION_DB_VERSION = 'translangs_db_version';

    /** Children keyed by original_hash with no FK to their parent — see OrphanTranslationSweep. */
    public const HASH_LINK_CHILDREN = ['tl_post', 'tl_term', 'tl_html', 'tl_option'];

    /** parent_id children (meta): FK constraint name => parent table key. */
    private const PARENT_ID_CHILD_FKS = [
        'fk_meta_tr_parent' => 'tl_meta',
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
     *                       FK constraint is absent after creation. Created
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
        $wpdb->query(TableDefinitions::html($p['tl_html'], $cc));                                       // phpcs:ignore WordPress.DB
        $wpdb->query(TableDefinitions::option($p['tl_option'], $wpdb->options, $cc));                   // phpcs:ignore WordPress.DB

        // 2a. Hash-link children (post/term/html): keyed by original_hash, no FK.
        foreach (self::HASH_LINK_CHILDREN as $parentKey) {
            $wpdb->query( // phpcs:ignore WordPress.DB
                TableDefinitions::hashLinkTranslation($p[$parentKey] . '_translation', $cc)
            );
        }

        // 2b. parent_id children (meta): FK'd back to their parent id.
        foreach (self::PARENT_ID_CHILD_FKS as $fkName => $parentKey) {
            $wpdb->query( // phpcs:ignore WordPress.DB
                TableDefinitions::parentIdTranslation(
                    $p[$parentKey] . '_translation',
                    $p[$parentKey],
                    $fkName,
                    $cc
                )
            );
        }

        // 2c. Upgrade path: an existing wp_tl_html (pre-v21) lacks last_seen_at.
        self::migrateHtmlLastSeenAt();

        // 2d. Upgrade path: an existing wp_tl_html (pre-v22) is keyed
        // (selector_hash, original_hash) instead of original_hash alone.
        self::migrateHtmlIdentityToHashOnly();

        // 3. Verify every FK exists in information_schema (parent owners + parent_id child links).
        $missing = (new ConstraintVerifier($wpdb))->findMissing(self::expectedForeignKeys());

        // 4. On any missing FK: drop the tables (children first), then fail.
        if ($missing !== []) {
            foreach ([...self::HASH_LINK_CHILDREN, ...array_values(self::PARENT_ID_CHILD_FKS)] as $parentKey) {
                $wpdb->query('DROP TABLE IF EXISTS ' . $p[$parentKey] . '_translation'); // nosemgrep: wpdb-interpolated-sql -- table name only, from the TranslationMemorySchema map / $wpdb prefix; no values interpolated
            }
            foreach ($p as $table) {
                $wpdb->query('DROP TABLE IF EXISTS ' . $table); // nosemgrep: wpdb-interpolated-sql -- table name only, from the TranslationMemorySchema map / $wpdb prefix; no values interpolated
            }

            throw SchemaFailure::missingForeignKeys($missing);
        }

        // 5. Version is written only after verification passes.
        update_option(self::OPTION_DB_VERSION, self::DB_VERSION);
    }

    /**
     * Every FK this schema must contain: four parent→WordPress owners plus the
     * one parent_id child→parent link. Hash-link children have no FK.
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
     * Abort before creating tables if wp_posts, wp_term_taxonomy or wp_options
     * is not InnoDB — the FK chain that makes translation orphans impossible
     * cannot be built otherwise (§10). Throws a human-readable SchemaFailure.
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

        if (self::columnExists($child, 'parent_id')) {
            $wpdb->query('DROP TABLE IF EXISTS ' . $child);                       // nosemgrep: wpdb-interpolated-sql -- table name only, from the TranslationMemorySchema map / $wpdb prefix; no values interpolated
            $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'tl_option');  // nosemgrep: wpdb-interpolated-sql -- table name only, from the TranslationMemorySchema map / $wpdb prefix; no values interpolated
        }
    }

    /**
     * Add last_seen_at to an existing wp_tl_html (pre-v21). Guarded by an
     * information_schema column check so it never re-runs or touches a freshly
     * created table (which already has the column via TableDefinitions::html()).
     * Existing rows get NULL, which the staleness query never matches — nothing
     * is retroactively flagged stale on upgrade day (see HtmlDiscovery, the html
     * repository staleness methods).
     */
    private static function migrateHtmlLastSeenAt(): void
    {
        global $wpdb;

        $table = self::parentTables()['tl_html'];

        if (self::columnExists($table, 'last_seen_at')) {
            return;
        }

        $wpdb->query("ALTER TABLE {$table} ADD COLUMN last_seen_at datetime NULL"); // nosemgrep: wpdb-interpolated-sql -- table name only, from the TranslationMemorySchema map / $wpdb prefix; no values interpolated
    }

    /**
     * Collapse an existing wp_tl_html (pre-v22) from identity
     * (selector_hash, original_hash) to original_hash alone. The same text at
     * two DOM locations used to mint two parent rows sharing one translation
     * (the child table was always keyed by original_hash alone) — cluttering
     * the flat html:all board without ever producing a second translation, so
     * collapsing the parent identity loses nothing translation-wise.
     *
     * Guarded by an information_schema column check so it never re-runs or
     * touches a freshly created table (which is already original_hash-only
     * via TableDefinitions::html()). Existing duplicates are deduped — lowest
     * id per original_hash kept — before the unique key is rebuilt, since
     * MySQL would otherwise reject a UNIQUE KEY the current duplicate rows
     * already violate.
     */
    private static function migrateHtmlIdentityToHashOnly(): void
    {
        global $wpdb;

        $table = self::parentTables()['tl_html'];

        if (!self::columnExists($table, 'selector_hash')) {
            return;
        }

        // Keep the earliest row per original_hash; later duplicates are pure
        // location clutter now that the parent identity is hash-only.
        $wpdb->query( // nosemgrep: wpdb-interpolated-sql -- table name only, from the TranslationMemorySchema map / $wpdb prefix; no values interpolated
            "DELETE p1 FROM {$table} p1
                INNER JOIN {$table} p2
                    ON p2.original_hash = p1.original_hash AND p2.id < p1.id"
        );

        $wpdb->query( // nosemgrep: wpdb-interpolated-sql -- table name only, from the TranslationMemorySchema map / $wpdb prefix; no values interpolated
            "ALTER TABLE {$table}
                DROP INDEX uniq_source,
                DROP INDEX idx_hash,
                DROP COLUMN selector_hash,
                ADD UNIQUE KEY uniq_source (original_hash)"
        );
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

    /** Whether $table currently has a column named $column, per information_schema. */
    private static function columnExists(string $table, string $column): bool
    {
        global $wpdb;

        $found = $wpdb->get_var( // phpcs:ignore WordPress.DB
            $wpdb->prepare(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                $table,
                $column
            )
        );

        return $found === $column;
    }

    /**
     * Set behavior options on first activation only (add_option is a no-op if they exist).
     * The source language is captured ONCE here: the normalized site
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

        // Onboarding: not yet completed. Credentials (token/site_id) are written on
        // connect with autoload=false and are deliberately not seeded here.
        add_option(\TransLangs\Backend\ConnectionRepository::OPTION_COMPLETED, false);
        add_option(\TransLangs\Backend\SiteMetadataProvider::OPTION_CATEGORIES, []);
        add_option(\TransLangs\Language\Infrastructure\LanguageMetadataStore::OPTION, []);

        add_option(
            \TransLangs\Language\Infrastructure\OptionLanguageRegistry::OPTION_SOURCE_LOCALE,
            \TransLangs\Language\Domain\LocaleNormalizer::current()
        );

        // Capture a stable, readable label alongside the frozen source locale.
        // The workspace can continue displaying the original language correctly
        // even if WordPress's WPLANG option changes later.
        (new \TransLangs\Language\Infrastructure\OptionLanguageRegistry())
            ->getDefaultLanguageDetails();
    }

    // ── Table name helpers ────────────────────────────────────────────────────

    /** The five prefixed parent table names, keyed by their unprefixed suffix. */
    public static function parentTables(): array
    {
        global $wpdb;

        return [
            'tl_post'   => $wpdb->prefix . 'tl_post',
            'tl_meta'   => $wpdb->prefix . 'tl_meta',
            'tl_term'   => $wpdb->prefix . 'tl_term',
            'tl_html'   => $wpdb->prefix . 'tl_html',
            'tl_option' => $wpdb->prefix . 'tl_option',
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

    /**
     * Reset-button tool: drop every TransLangs table, children before parents
     * (FK-safe, same order as uninstall.php's DROP).
     */
    public static function dropAllTables(\wpdb $wpdb): void
    {
        foreach ([...self::childTables(), ...self::parentTables()] as $table) {
            // nosemgrep: wpdb-interpolated-sql -- table name only, from this class's own map / $wpdb prefix; no values interpolated
            $wpdb->query('DROP TABLE IF EXISTS ' . $table);
        }
    }

    /** Dev-only reset tool: the reciprocal of setDefaultOptions() above. */
    public static function deleteConfigOptions(): void
    {
        delete_option('translangs_enabled_languages');
        delete_option('translangs_prefix_default_language');
        delete_option('translangs_cookie_preference');
        delete_option('translangs_browser_detection');
        delete_option('translangs_auto_redirect');
    }
}
