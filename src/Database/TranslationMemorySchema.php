<?php

namespace LingoWP\Database;

use LingoWP\Database\ConstraintVerifier;
use LingoWP\Database\SchemaFailure;
use LingoWP\Database\TableDefinitions;

/**
 * Schema orchestrator — install, verify, and version-gate the LingoWP tables.
 *
 * Fourteen content tables: seven readable PARENT source tables and seven CHILD
 * per-language translation tables. Created with raw DDL — never dbDelta().
 * Parent engines are checked first, and every FK is verified after creation;
 * version is bumped only once verification passes. There is no data migration
 * here: pre-existing tables are assumed dropped out of band, and
 * CREATE TABLE IF NOT EXISTS is idempotent.
 *
 * A fifteenth table, gettext_unit, is created alongside these but is
 * deliberately NOT part of the fourteen-table parent/child model above: no
 * FK, no per-language child (translated text stays in `.mo` files), and not
 * registered in HASH_LINK_CHILDREN/PARENT_ID_CHILD_FKS, so it stays invisible
 * to those two constants' generic child-table-creation loops and to
 * WpDbBoardQueries::languageProgress()'s summation over them. See
 * TableDefinitions::gettextUnit()'s own doc.
 */
class TranslationMemorySchema
{
    public const DB_VERSION        = '6';
    public const OPTION_DB_VERSION = 'lingowp_db_version';

    /** Children keyed by original_hash with no FK to their parent — see OrphanTranslationSweep. */
    public const HASH_LINK_CHILDREN = ['post', 'term', 'html', 'option', 'user'];

    /**
     * parent_id children (meta, term_meta): FK constraint name => parent table key.
     * Public (like HASH_LINK_CHILDREN): WpDbSourceRepository loops over its values
     * to stay generic across every parent_id table, not just 'meta'.
     */
    public const PARENT_ID_CHILD_FKS = [
        'fk_meta_tr_parent'      => 'meta',
        'fk_term_meta_tr_parent' => 'term_meta',
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
     * Full install: verify parent engines → create seven parents → create
     * seven children → verify every FK → bump version.
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

        // 1. Create the seven parent tables first — they are the child FK targets.
        $wpdb->query(TableDefinitions::post($p['post'], $wpdb->posts, $cc));                         // phpcs:ignore WordPress.DB
        $wpdb->query(TableDefinitions::meta($p['meta'], $wpdb->posts, $cc));                         // phpcs:ignore WordPress.DB
        $wpdb->query(TableDefinitions::term($p['term'], $wpdb->term_taxonomy, $cc));                 // phpcs:ignore WordPress.DB
        $wpdb->query(TableDefinitions::html($p['html'], $cc));                                       // phpcs:ignore WordPress.DB
        $wpdb->query(TableDefinitions::option($p['option'], $wpdb->options, $cc));                   // phpcs:ignore WordPress.DB
        $wpdb->query(TableDefinitions::user($p['user'], $wpdb->users, $cc));                         // phpcs:ignore WordPress.DB
        $wpdb->query(TableDefinitions::termMeta($p['term_meta'], $wpdb->terms, $cc));                // phpcs:ignore WordPress.DB

        // 2a. Hash-link children (post/term/html/option/user): keyed by original_hash, no FK.
        foreach (self::HASH_LINK_CHILDREN as $parentKey) {
            $wpdb->query( // phpcs:ignore WordPress.DB
                TableDefinitions::hashLinkTranslation($p[$parentKey] . '_translation', $cc)
            );
        }

        // 2b. parent_id children (meta, term_meta): FK'd back to their parent id.
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

        // 5. Gettext source-string tracking (Plugins & Themes board) — no FK,
        // no per-language child, deliberately outside the FK chain verified
        // above so it never participates in that rollback. On the < 6 upgrade
        // the table is dropped and rebuilt (adds the ai_* columns); manual
        // ignores are snapshotted and re-seeded there. Then fold any
        // pre-existing ignored-units option data into it, once.
        self::migrateGettextUnitV6($cc);
        $wpdb->query(TableDefinitions::gettextUnit(self::gettextUnitTable(), $cc)); // phpcs:ignore WordPress.DB
        self::migrateGettextIgnoredUnits();

        // 6. Version is written only after verification passes.
        update_option(self::OPTION_DB_VERSION, self::DB_VERSION);
    }

    /**
     * One-time, idempotent fold of the old per-locale JSON option
     * (lingowp_gettext_ignored_units, written by the now-removed
     * GettextIgnoredUnitsStore) into gettext_unit rows. Reads the option
     * directly rather than depending on that deleted class.
     *
     * Ignore status is global per string in the new table (not per locale,
     * see gettextUnit()'s doc), so entries for the same string ignored in
     * different locales are unioned — a one-way migration should never
     * silently un-hide something a translator explicitly hid.
     *
     * Runs before any real source scan for these domains: unit_hash matches
     * the old store's own key format exactly (context."\x04".msgid, or bare
     * msgid), so a later scan's ON DUPLICATE KEY UPDATE (which never touches
     * status) finds these rows by hash and leaves them ignored.
     */
    /**
     * DB_VERSION 5 → 6: the gettext_unit table grew ai_* / norm_hash columns
     * for the async "Translate All" queue. This codebase has no ALTER TABLE
     * path (schema changes are drop + recreate), so the table is dropped and
     * TableDefinitions::gettextUnit() (with the new columns) recreates it in
     * step 5. Two things must survive the drop:
     *   - manual ignores (status = -1) — after the v5 migration these live
     *     ONLY in this table, so they are snapshotted by hash and re-seeded
     *     (the first real rescan's ON DUPLICATE KEY UPDATE fills in msgid and
     *     leaves status = -1, same trick migrateGettextIgnoredUnits() uses);
     *   - the "which domains were scanned" option is cleared so every
     *     component re-scans its now-empty source rows on the next board read.
     * No-ops on a fresh install (table absent) or once already on 6+.
     */
    private static function migrateGettextUnitV6(string $charsetCollate): void
    {
        global $wpdb;

        if (version_compare((string) get_option(self::OPTION_DB_VERSION, '0'), '6', '>=')) {
            return;
        }

        $table = self::gettextUnitTable();

        $exists = (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
            DB_NAME,
            $table
        ));
        if (! $exists) {
            return;
        }

        $hasNormHash = (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            DB_NAME,
            $table,
            'norm_hash'
        ));
        if ($hasNormHash) {
            return;
        }

        // msgid/context are snapshotted too, not just the hash — bulkUpsert()'s
        // later ON DUPLICATE KEY UPDATE only ever touches last_seen_at/updated_at
        // (deliberately, so a real rescan never overwrites status), so a blank
        // msgid inserted here would stay blank forever instead of being filled
        // in by that rescan.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- table name only, from the schema map
        $ignored = (array) $wpdb->get_results("SELECT type, domain, HEX(unit_hash) AS h, msgid, context FROM {$table} WHERE status = -1", ARRAY_A);

        $wpdb->query("DROP TABLE IF EXISTS {$table}"); // phpcs:ignore WordPress.DB -- table name only
        $wpdb->query(TableDefinitions::gettextUnit($table, $charsetCollate)); // phpcs:ignore WordPress.DB

        $now = current_time('mysql', true);
        foreach ($ignored as $row) {
            if (! isset($row['type'], $row['domain'], $row['h'])) {
                continue;
            }
            $context = $row['context'] !== null && $row['context'] !== '' ? (string) $row['context'] : null;
            // %s can't carry a real NULL through wpdb::prepare() (it casts to
            // ''), so — same as bulkUpsert() above — the column is a literal
            // NULL in the SQL text when there's no context, never a bound arg.
            $args = [(string) $row['type'], (string) $row['domain'], (string) $row['h'], (string) $row['msgid']];
            if ($context !== null) {
                $args[] = $context;
            }
            $args[] = $now;
            $args[] = $now;
            $wpdb->query($wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only
                "INSERT INTO {$table} (type, domain, unit_hash, msgid, context, status, created_at, updated_at)
                 VALUES (%s, %s, UNHEX(%s), %s, " . ($context === null ? 'NULL' : '%s') . ", -1, %s, %s)
                 ON DUPLICATE KEY UPDATE status = -1",
                ...$args
            ));
        }

        delete_option('lingowp_gettext_scanned_domains');
    }

    private static function migrateGettextIgnoredUnits(): void
    {
        global $wpdb;

        $raw = json_decode((string) get_option('lingowp_gettext_ignored_units', '{}'), true);
        if (! is_array($raw) || $raw === []) {
            delete_option('lingowp_gettext_ignored_units');
            return;
        }

        $table = self::gettextUnitTable();
        $now   = current_time('mysql', true);

        // Union across locales: type:domain => Set of unitKey (bare msgid, or
        // context."\x04".msgid — same shape the old store used).
        $unioned = [];
        foreach ($raw as $groupKey => $unitKeys) {
            $parts = explode(':', (string) $groupKey, 3);
            if (count($parts) !== 3 || ! is_array($unitKeys)) {
                continue;
            }
            [$type, $domain] = $parts;
            $unioned[$type . ':' . $domain] ??= [];
            foreach ($unitKeys as $unitKey) {
                $unioned[$type . ':' . $domain][(string) $unitKey] = true;
            }
        }

        foreach ($unioned as $typeDomain => $unitKeys) {
            [$type, $domain] = explode(':', $typeDomain, 2);

            foreach (array_keys($unitKeys) as $unitKey) {
                $sep = strpos($unitKey, "\x04");
                if ($sep === false) {
                    $msgid   = $unitKey;
                    $context = null;
                } else {
                    $context = substr($unitKey, 0, $sep);
                    $msgid   = substr($unitKey, $sep + 1);
                }

                $hash = hash('sha256', $context !== null && $context !== ''
                    ? $context . "\x04" . $msgid
                    : $msgid, true);

                // Same NULL-literal-vs-placeholder convention as
                // WpDbSourceRepository::saveTranslationByHash() — $wpdb->prepare()
                // has no clean way to bind a literal SQL NULL through %s.
                $contextExpr = $context === null ? 'NULL' : '%s';
                $args        = [$type, $domain, bin2hex($hash), $msgid];
                if ($context !== null) {
                    $args[] = $context;
                }
                $args[] = $now;
                $args[] = $now;

                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
                $wpdb->query($wpdb->prepare(
                    "INSERT INTO {$table}
                        (type, domain, unit_hash, msgid, context, status, created_at, updated_at)
                     VALUES (%s, %s, UNHEX(%s), %s, {$contextExpr}, -1, %s, %s)
                     ON DUPLICATE KEY UPDATE status = -1, updated_at = VALUES(updated_at)",
                    ...$args
                ));
            }
        }

        delete_option('lingowp_gettext_ignored_units');
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
            'fk_post_owner'      => $p['post'],
            'fk_meta_owner'      => $p['meta'],
            'fk_term_owner'      => $p['term'],
            'fk_option_owner'    => $p['option'],
            'fk_user_owner'      => $p['user'],
            'fk_term_meta_owner' => $p['term_meta'],
        ];

        foreach (self::PARENT_ID_CHILD_FKS as $fkName => $parentKey) {
            $expected[$fkName] = $p[$parentKey] . '_translation';
        }

        return $expected;
    }

    /**
     * Abort before creating tables if wp_posts, wp_term_taxonomy, wp_options,
     * wp_users or wp_terms is not InnoDB — the FK chain that makes translation
     * orphans impossible cannot be built otherwise (§10). Throws a
     * human-readable SchemaFailure.
     */
    private static function assertParentTablesAreInnoDB(): void
    {
        global $wpdb;

        foreach ([$wpdb->posts, $wpdb->term_taxonomy, $wpdb->options, $wpdb->users, $wpdb->terms] as $table) {
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
     * The reciprocal delete lives in PluginDataCleanup::OPTIONS (shared by
     * uninstall.php and the reset button).
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

    /** The seven prefixed parent table names, keyed by their unprefixed suffix. */
    public static function parentTables(): array
    {
        global $wpdb;

        return [
            'post'      => $wpdb->prefix . 'lingowp_post',
            'meta'      => $wpdb->prefix . 'lingowp_meta',
            'term'      => $wpdb->prefix . 'lingowp_term',
            'html'      => $wpdb->prefix . 'lingowp_html',
            'option'    => $wpdb->prefix . 'lingowp_option',
            'user'      => $wpdb->prefix . 'lingowp_user',
            'term_meta' => $wpdb->prefix . 'lingowp_term_meta',
        ];
    }


    /** The seven prefixed child (translation) table names, keyed by parent suffix. */
    public static function childTables(): array
    {
        $children = [];
        foreach (self::parentTables() as $key => $table) {
            $children[$key] = $table . '_translation';
        }

        return $children;
    }

    /**
     * The gettext_unit table name — kept out of parentTables()/childTables()
     * on purpose, since those two maps exist to drive the generic hash-link/
     * parent_id child-table machinery this table deliberately isn't part of.
     */
    public static function gettextUnitTable(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'lingowp_gettext_unit';
    }

    /**
     * Reset-button tool: drop every LingoWP table, children before parents
     * (FK-safe, same order as uninstall.php's DROP), plus gettext_unit (no
     * FK, so its position relative to the rest doesn't matter).
     */
    public static function dropAllTables(\wpdb $wpdb): void
    {
        foreach ([...self::childTables(), ...self::parentTables(), self::gettextUnitTable()] as $table) {
            // nosemgrep: wpdb-interpolated-sql -- table name only, from this class's own map / $wpdb prefix; no values interpolated
            $wpdb->query('DROP TABLE IF EXISTS ' . $table);
        }
    }

}
