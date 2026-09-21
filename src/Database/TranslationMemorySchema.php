<?php

namespace LingoWP\Database;

use LingoWP\Database\ConstraintVerifier;
use LingoWP\Database\SchemaFailure;
use LingoWP\Database\TableDefinitions;

class TranslationMemorySchema
{
    public const DB_VERSION        = '6';
    public const OPTION_DB_VERSION = 'lingowp_db_version';

    public const HASH_LINK_CHILDREN = ['post', 'term', 'html', 'option', 'user'];

    public const PARENT_ID_CHILD_FKS = [
        'fk_meta_tr_parent'      => 'meta',
        'fk_term_meta_tr_parent' => 'term_meta',
    ];

    public static function maybeUpgrade(): void
    {
        $installed = (string) get_option(self::OPTION_DB_VERSION, '0');

        if (version_compare($installed, self::DB_VERSION, '>=')) {
            return;
        }

        self::install();
    }

    public static function install(): void
    {
        global $wpdb;

        self::assertParentTablesAreInnoDB();

        $cc = $wpdb->get_charset_collate();
        $p  = self::parentTables();

        $wpdb->query(TableDefinitions::post($p['post'], $wpdb->posts, $cc));                         // phpcs:ignore WordPress.DB
        $wpdb->query(TableDefinitions::meta($p['meta'], $wpdb->posts, $cc));                         // phpcs:ignore WordPress.DB
        $wpdb->query(TableDefinitions::term($p['term'], $wpdb->term_taxonomy, $cc));                 // phpcs:ignore WordPress.DB
        $wpdb->query(TableDefinitions::html($p['html'], $cc));                                       // phpcs:ignore WordPress.DB
        $wpdb->query(TableDefinitions::option($p['option'], $wpdb->options, $cc));                   // phpcs:ignore WordPress.DB
        $wpdb->query(TableDefinitions::user($p['user'], $wpdb->users, $cc));                         // phpcs:ignore WordPress.DB
        $wpdb->query(TableDefinitions::termMeta($p['term_meta'], $wpdb->terms, $cc));                // phpcs:ignore WordPress.DB

        foreach (self::HASH_LINK_CHILDREN as $parentKey) {
            $wpdb->query( // phpcs:ignore WordPress.DB
                TableDefinitions::hashLinkTranslation($p[$parentKey] . '_translation', $cc)
            );
        }

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

        $missing = (new ConstraintVerifier($wpdb))->findMissing(self::expectedForeignKeys());

        if ($missing !== []) {
            foreach ([...self::HASH_LINK_CHILDREN, ...array_values(self::PARENT_ID_CHILD_FKS)] as $parentKey) {
                $wpdb->query('DROP TABLE IF EXISTS ' . $p[$parentKey] . '_translation'); // nosemgrep: wpdb-interpolated-sql -- table name only, from the TranslationMemorySchema map / $wpdb prefix; no values interpolated
            }
            foreach ($p as $table) {
                $wpdb->query('DROP TABLE IF EXISTS ' . $table); // nosemgrep: wpdb-interpolated-sql -- table name only, from the TranslationMemorySchema map / $wpdb prefix; no values interpolated
            }

            throw SchemaFailure::missingForeignKeys($missing);
        }

        self::migrateGettextUnitV6($cc);
        $wpdb->query(TableDefinitions::gettextUnit(self::gettextUnitTable(), $cc)); // phpcs:ignore WordPress.DB
        self::migrateGettextIgnoredUnits();

        update_option(self::OPTION_DB_VERSION, self::DB_VERSION);
    }

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

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- table name only, from the schema map
        $ignored = (array) $wpdb->get_results("SELECT type, domain, HEX(unit_hash) AS h, msgid, context FROM {$table} WHERE status = -1", ARRAY_A); // nosemgrep: wpdb-interpolated-sql -- table name only, from the schema map

        // nosemgrep: wpdb-interpolated-sql -- table name only, from the schema map
        $wpdb->query("DROP TABLE IF EXISTS {$table}"); // phpcs:ignore WordPress.DB -- table name only
        $wpdb->query(TableDefinitions::gettextUnit($table, $charsetCollate)); // phpcs:ignore WordPress.DB

        $now = current_time('mysql', true);
        foreach ($ignored as $row) {
            if (! isset($row['type'], $row['domain'], $row['h'])) {
                continue;
            }
            $context = $row['context'] !== null && $row['context'] !== '' ? (string) $row['context'] : null;
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

    public static function setDefaultOptions(): void
    {
        add_option('lingowp_enabled_languages', []);
        add_option('lingowp_prefix_default_language', false);
        add_option('lingowp_cookie_preference', true);
        add_option('lingowp_browser_detection', false);
        add_option('lingowp_auto_redirect', false);

        add_option(\LingoWP\Backend\ConnectionRepository::OPTION_COMPLETED, false);
        add_option(\LingoWP\Backend\SiteMetadataProvider::OPTION_CATEGORIES, []);
        add_option(\LingoWP\Language\Infrastructure\LanguageMetadataStore::OPTION, []);

        add_option(
            \LingoWP\Language\Infrastructure\OptionLanguageRegistry::OPTION_SOURCE_LOCALE,
            \LingoWP\Language\Domain\LocaleNormalizer::current()
        );

        (new \LingoWP\Language\Infrastructure\OptionLanguageRegistry())
            ->getDefaultLanguageDetails();
    }

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

    public static function childTables(): array
    {
        $children = [];
        foreach (self::parentTables() as $key => $table) {
            $children[$key] = $table . '_translation';
        }

        return $children;
    }

    public static function gettextUnitTable(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'lingowp_gettext_unit';
    }

    public static function dropAllTables(\wpdb $wpdb): void
    {
        $tables = [
            ...array_values(self::childTables()),
            ...array_values(self::parentTables()),
            self::gettextUnitTable(),
        ];

        foreach ($tables as $table) {
            // nosemgrep: wpdb-interpolated-sql -- table name only, from this class's own map / $wpdb prefix; no values interpolated
            $wpdb->query('DROP TABLE IF EXISTS ' . $table);
        }
    }
}
