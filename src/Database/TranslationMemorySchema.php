<?php

namespace LingoWP\Database;

use LingoWP\Database\ConstraintVerifier;
use LingoWP\Database\SchemaFailure;
use LingoWP\Database\TableDefinitions;

/**
 * Schema orchestrator — install, verify, and version-gate the LingoWP tables.
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
    public const DB_VERSION        = '2';
    public const OPTION_DB_VERSION = 'lingowp_db_version';

    /** Children keyed by original_hash with no FK to their parent — see OrphanTranslationSweep. */
    public const HASH_LINK_CHILDREN = ['post', 'term', 'html', 'option'];

    /** parent_id children (meta): FK constraint name => parent table key. */
    private const PARENT_ID_CHILD_FKS = [
        'fk_meta_tr_parent' => 'meta',
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

        $cc = $wpdb->get_charset_collate();
        $p  = self::parentTables();

        // 1. Create the five parent tables first — they are the child FK targets.
        $wpdb->query(TableDefinitions::post($p['post'], $wpdb->posts, $cc));                         // phpcs:ignore WordPress.DB
        $wpdb->query(TableDefinitions::meta($p['meta'], $wpdb->posts, $cc));                         // phpcs:ignore WordPress.DB
        $wpdb->query(TableDefinitions::term($p['term'], $wpdb->term_taxonomy, $cc));                 // phpcs:ignore WordPress.DB
        $wpdb->query(TableDefinitions::html($p['html'], $cc));                                       // phpcs:ignore WordPress.DB
        $wpdb->query(TableDefinitions::option($p['option'], $wpdb->options, $cc));                   // phpcs:ignore WordPress.DB

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
            'fk_post_owner'   => $p['post'],
            'fk_meta_owner'   => $p['meta'],
            'fk_term_owner'   => $p['term'],
            'fk_option_owner' => $p['option'],
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
     * The source language is captured ONCE here: the normalized site
     * locale is frozen into lingowp_source_locale so runtime never reads the
     * filtered, mid-request-switchable get_locale().
     */
    public static function setDefaultOptions(): void
    {
        add_option('lingowp_enabled_languages', []);
        add_option('lingowp_prefix_default_language', false);
        add_option('lingowp_cookie_preference', true);
        add_option('lingowp_browser_detection', false);
        add_option('lingowp_auto_redirect', false);

        // Onboarding: not yet completed. Credentials (token/site_id) are written on
        // connect with autoload=false and are deliberately not seeded here.
        add_option(\LingoWP\Backend\ConnectionRepository::OPTION_COMPLETED, false);
        add_option(\LingoWP\Backend\SiteMetadataProvider::OPTION_CATEGORIES, []);
        add_option(\LingoWP\Language\Infrastructure\LanguageMetadataStore::OPTION, []);

        add_option(
            \LingoWP\Language\Infrastructure\OptionLanguageRegistry::OPTION_SOURCE_LOCALE,
            \LingoWP\Language\Domain\LocaleNormalizer::current()
        );

        // Capture a stable, readable label alongside the frozen source locale.
        // The workspace can continue displaying the original language correctly
        // even if WordPress's WPLANG option changes later.
        (new \LingoWP\Language\Infrastructure\OptionLanguageRegistry())
            ->getDefaultLanguageDetails();
    }

    // ── Table name helpers ────────────────────────────────────────────────────

    /** The five prefixed parent table names, keyed by their unprefixed suffix. */
    public static function parentTables(): array
    {
        global $wpdb;

        return [
            'post'   => $wpdb->prefix . 'lingowp_post',
            'meta'   => $wpdb->prefix . 'lingowp_meta',
            'term'   => $wpdb->prefix . 'lingowp_term',
            'html'   => $wpdb->prefix . 'lingowp_html',
            'option' => $wpdb->prefix . 'lingowp_option',
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
     * Reset-button tool: drop every LingoWP table, children before parents
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
        delete_option('lingowp_enabled_languages');
        delete_option('lingowp_prefix_default_language');
        delete_option('lingowp_cookie_preference');
        delete_option('lingowp_browser_detection');
        delete_option('lingowp_auto_redirect');
    }
}
