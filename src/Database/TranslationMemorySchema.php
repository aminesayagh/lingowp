<?php

namespace LingoWP\Database;

use LingoWP\Database\ConstraintVerifier;
use LingoWP\Database\TableDefinitions;

/**
 * Schema orchestrator — install, verify, and version-gate the LingoWP tables.
 *
 * Fourteen content tables: seven readable PARENT source tables and seven CHILD
 * per-language translation tables. Created with dbDelta(); foreign keys are
 * then added by a prepared ALTER TABLE where the database supports them
 * (dbDelta() cannot create them), and verified. The version is bumped last.
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
    /** '1' when the content tables carry foreign keys, '0' when the database refused them. */
    public const OPTION_FOREIGN_KEYS = 'lingowp_foreign_keys';

    /** Children keyed by original_hash with no FK to their parent — see OrphanTranslationSweep. */
    public const HASH_LINK_CHILDREN = ['post', 'term', 'html', 'option', 'user'];

    /**
     * parent_id children (meta, term_meta): parent table keys whose child is
     * FK'd back by parent_id. Public (like HASH_LINK_CHILDREN): WpDbSourceRepository
     * loops over it to stay generic across every parent_id table, not just 'meta'.
     */
    public const PARENT_ID_CHILD_FKS = ['meta', 'term_meta'];

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
     * Full install: check parent engines → dbDelta() the tables → add and
     * verify the foreign keys → bump version.
     *
     * Never throws for the database's capabilities: without InnoDB parents or
     * foreign-key support, the tables are created without foreign keys.
     */
    public static function install(): void
    {
        global $wpdb;

        $cc = $wpdb->get_charset_collate();

        // Foreign keys where the database supports them. They need WordPress's
        // own tables on InnoDB and a server that accepts them; when either is
        // missing, the content tables are created without them instead of
        // aborting activation (WordPress.org review). Without them a deleted
        // post, term, option or user keeps its translations: an accepted
        // limit on those databases. A site already installed without them
        // stays that way: its tables may hold rows a new foreign key would
        // reject.
        $useForeignKeys = get_option(self::OPTION_FOREIGN_KEYS) !== '0' && self::parentTablesAreInnoDB();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Gettext source-string tracking (Plugins & Themes board): on the < 6
        // upgrade the table is dropped and rebuilt (adds the ai_* columns),
        // manual ignores snapshotted and re-seeded. Before dbDelta() below, so
        // dbDelta() never tries to ALTER the old shape in place.
        self::migrateGettextUnitV6($cc);

        $p = self::parentTables();
        self::createContentTables($p, $cc);
        dbDelta(TableDefinitions::gettextUnit(self::gettextUnitTable(), $cc));

        // Every foreign key in place, or none relied on: a server that refused
        // one is treated as a database without foreign keys.
        if ($useForeignKeys) {
            $useForeignKeys = self::addForeignKeys($p);
        }

        update_option(self::OPTION_FOREIGN_KEYS, $useForeignKeys ? '1' : '0', false);

        // Fold any pre-existing ignored-units option data into gettext_unit, once.
        self::migrateGettextIgnoredUnits();

        // Version last, once every table is in place.
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

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders -- table names come from $wpdb and the plugin's schema map; every value goes through a placeholder.
        $exists = (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
            DB_NAME,
            $table
        ));
        // phpcs:enable
        if (! $exists) {
            return;
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders -- table names come from $wpdb and the plugin's schema map; every value goes through a placeholder.
        $hasNormHash = (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            DB_NAME,
            $table,
            'norm_hash'
        ));
        // phpcs:enable
        if ($hasNormHash) {
            return;
        }

        // msgid/context are snapshotted too, not just the hash — bulkUpsert()'s
        // later ON DUPLICATE KEY UPDATE only ever touches last_seen_at/updated_at
        // (deliberately, so a real rescan never overwrites status), so a blank
        // msgid inserted here would stay blank forever instead of being filled
        // in by that rescan.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders -- table name only, from the schema map
        $ignored = (array) $wpdb->get_results($wpdb->prepare('SELECT type, domain, HEX(unit_hash) AS h, msgid, context FROM %i WHERE status = -1', $table), ARRAY_A); // nosemgrep: wpdb-interpolated-sql -- table name only, from the schema map
        // phpcs:enable

        // phpcs:disable PluginCheck.Security.DirectDB, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders -- the plugin's own tables: schema install and teardown, run once, nothing to cache.
        // nosemgrep: wpdb-interpolated-sql -- table name only, from the schema map
        $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $table)); // phpcs:ignore WordPress.DB -- table name only
        // phpcs:enable
        dbDelta(TableDefinitions::gettextUnit($table, $charsetCollate));

        $now = current_time('mysql', true);
        foreach ($ignored as $row) {
            if (! isset($row['type'], $row['domain'], $row['h'])) {
                continue;
            }
            $context = $row['context'] !== null && $row['context'] !== '' ? (string) $row['context'] : null;
            // %s can't carry a real NULL through wpdb::prepare() (it casts to
            // ''), so — same as bulkUpsert() above — the column is a literal
            // NULL in the SQL text when there's no context, never a bound arg.
            // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders -- table names come from $wpdb and the plugin's schema map; every value goes through a placeholder.
            $wpdb->query($wpdb->prepare(
                "INSERT INTO %i (type, domain, unit_hash, msgid, context, status, created_at, updated_at)
                 VALUES (%s, %s, UNHEX(%s), %s, NULLIF(%s, ''), -1, %s, %s)
                 ON DUPLICATE KEY UPDATE status = -1",
                $table,
                (string) $row['type'],
                (string) $row['domain'],
                (string) $row['h'],
                (string) $row['msgid'],
                (string) $context, // '' becomes NULL: prepare() cannot bind a NULL
                $now,
                $now
            ));
            // phpcs:enable
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

                // phpcs:disable WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders -- table names come from $wpdb and the plugin's schema map; every value goes through a placeholder.
                $wpdb->query($wpdb->prepare(
                    "INSERT INTO %i
                        (type, domain, unit_hash, msgid, context, status, created_at, updated_at)
                     VALUES (%s, %s, UNHEX(%s), %s, NULLIF(%s, ''), -1, %s, %s)
                     ON DUPLICATE KEY UPDATE status = -1, updated_at = VALUES(updated_at)",
                    $table,
                    $type,
                    $domain,
                    bin2hex($hash),
                    $msgid,
                    (string) $context, // '' becomes NULL: prepare() cannot bind a NULL
                    $now,
                    $now
                ));
                // phpcs:enable
            }
        }

        delete_option('lingowp_gettext_ignored_units');
    }

    /**
     * Every FK this schema must contain: six parent→WordPress owners plus the
     * two parent_id child→parent links. Hash-link children have no FK.
     * Unnamed on purpose: MySQL constraint names are unique per DATABASE, so a
     * fixed name made every multisite site after the first fail to install;
     * InnoDB names them `<table>_ibfk_N`, and ConstraintVerifier checks them
     * by table pair.
     *
     * @param array<string, string> $p parent table names, as parentTables()
     * @return array<int, array{0: string, 1: string, 2: string, 3: string}> [table, column, referenced table, referenced column]
     */
    public static function foreignKeyDefinitions(array $p): array
    {
        global $wpdb;

        return [
            [$p['post'], 'post_id', $wpdb->posts, 'ID'],
            [$p['meta'], 'post_id', $wpdb->posts, 'ID'],
            [$p['term'], 'term_taxonomy_id', $wpdb->term_taxonomy, 'term_taxonomy_id'],
            [$p['option'], 'option_id', $wpdb->options, 'option_id'],
            [$p['user'], 'user_id', $wpdb->users, 'ID'],
            [$p['term_meta'], 'term_id', $wpdb->terms, 'term_id'],
            [$p['meta'] . '_translation', 'parent_id', $p['meta'], 'id'],
            [$p['term_meta'] . '_translation', 'parent_id', $p['term_meta'], 'id'],
        ];
    }

    /** @return array<string, string> table carrying the FK => table it references */
    public static function expectedForeignKeys(): array
    {
        $expected = [];
        foreach (self::foreignKeyDefinitions(self::parentTables()) as [$table, , $referenced]) {
            $expected[$table] = $referenced;
        }

        return $expected;
    }

    /**
     * Add each foreign key that is not there yet. True when all of them are
     * in place afterwards; false when the server refused one (no REFERENCES
     * privilege, an engine without foreign keys…). Errors are suppressed: a
     * refusal is an expected outcome here, and activation must print nothing.
     *
     * @param array<string, string> $p parent table names, as parentTables()
     */
    public static function addForeignKeys(array $p): bool
    {
        global $wpdb;

        $definitions = self::foreignKeyDefinitions($p);
        $expected    = [];
        foreach ($definitions as [$table, , $referenced]) {
            $expected[$table] = $referenced;
        }

        $verifier = new ConstraintVerifier($wpdb);
        $missing  = $verifier->findMissing($expected);
        if ($missing === []) {
            return true;
        }

        $suppressed = $wpdb->suppress_errors(true);
        foreach ($definitions as [$table, $column, $referenced, $referencedColumn]) {
            if (in_array($table, $missing, true)) {
                // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.SchemaChange, PluginCheck.Security.DirectDB, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders -- the plugin's own table, during install; identifiers through %i.
                $wpdb->query($wpdb->prepare(
                    'ALTER TABLE %i ADD FOREIGN KEY (%i) REFERENCES %i (%i) ON DELETE CASCADE',
                    $table,
                    $column,
                    $referenced,
                    $referencedColumn
                ));
                // phpcs:enable
            }
        }
        $wpdb->suppress_errors($suppressed);

        return $verifier->findMissing($expected) === [];
    }

    /**
     * Whether wp_posts, wp_term_taxonomy, wp_options, wp_users and wp_terms are
     * all InnoDB — a foreign key can only reference an InnoDB table.
     */
    private static function parentTablesAreInnoDB(): bool
    {
        global $wpdb;

        foreach ([$wpdb->posts, $wpdb->term_taxonomy, $wpdb->options, $wpdb->users, $wpdb->terms] as $table) {
            $engine = self::engineOf($table);

            if ($engine !== null && strtoupper($engine) !== 'INNODB') {
                return false;
            }
        }

        return true;
    }

    /** Whether foreign keys are in use on this site (see install()). */
    public static function usesForeignKeys(): bool
    {
        return get_option(self::OPTION_FOREIGN_KEYS) !== '0';
    }

    /**
     * The fourteen content tables through dbDelta(): created when missing,
     * left as they are when already current. Foreign keys come after, from
     * addForeignKeys().
     *
     * @param array<string, string> $p parent table names, as parentTables()
     */
    public static function createContentTables(array $p, string $cc): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $statements = [
            TableDefinitions::post($p['post'], $cc),
            TableDefinitions::meta($p['meta'], $cc),
            TableDefinitions::term($p['term'], $cc),
            TableDefinitions::html($p['html'], $cc),
            TableDefinitions::option($p['option'], $cc),
            TableDefinitions::user($p['user'], $cc),
            TableDefinitions::termMeta($p['term_meta'], $cc),
        ];
        // Hash-link children (post/term/html/option/user): keyed by original_hash, never a foreign key.
        foreach (self::HASH_LINK_CHILDREN as $parentKey) {
            $statements[] = TableDefinitions::hashLinkTranslation($p[$parentKey] . '_translation', $cc);
        }
        // parent_id children (meta, term_meta): linked back to their parent row.
        foreach (self::PARENT_ID_CHILD_FKS as $parentKey) {
            $statements[] = TableDefinitions::parentIdTranslation($p[$parentKey] . '_translation', $cc);
        }

        dbDelta($statements);
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
        add_option(\LingoWP\LocalizationRouting\SlugMap::OPTION_ENABLED, false);

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
    /**
     * Every table of the current site, children before parents, so each DROP
     * succeeds despite the FKs.
     *
     * @return string[]
     */
    public static function allTablesInDropOrder(): array
    {
        // array_values: childTables() and parentTables() share the same string
        // keys (post, meta, …), so unpacking them directly makes the parent
        // entries overwrite the children and no *_translation table is ever
        // dropped — plus the two parent_id parents then survive on their child
        // FK. Re-index to plain lists first.
        return [
            ...array_values(self::childTables()),
            ...array_values(self::parentTables()),
            self::gettextUnitTable(),
        ];
    }

    /** True once install() has completed on the current site. */
    public static function isInstalled(): bool
    {
        return get_option(self::OPTION_DB_VERSION) !== false;
    }

    /**
     * wpmu_drop_tables: deleting a network site drops Linvara's tables first.
     * Their FKs reference the site's posts/options/terms tables, so core's own
     * DROPs failed and left 19 tables behind. Core runs this switched into the
     * site being deleted, so the names carry its prefix.
     *
     * @param string[] $tables
     * @return string[]
     */
    public static function addTablesForSiteDeletion($tables): array
    {
        return array_merge(self::allTablesInDropOrder(), (array) $tables);
    }

    public static function dropAllTables(\wpdb $wpdb): void
    {
        foreach (self::allTablesInDropOrder() as $table) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders -- the plugin's own tables: schema install and teardown, run once, nothing to cache.
            // nosemgrep: wpdb-interpolated-sql -- table name only, from this class's own map / $wpdb prefix; no values interpolated
            $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $table));
            // phpcs:enable
        }
    }

}
