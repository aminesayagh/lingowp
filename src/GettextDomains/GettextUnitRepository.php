<?php

namespace LingoWP\GettextDomains;

use LingoWP\Database\TranslationMemorySchema;

/**
 * Original strings + ignore status for the Plugins & Themes board, backed by
 * wp_lingowp_gettext_unit (TableDefinitions::gettextUnit()). Replaces the full
 * AST source-scan that used to run on every read: a domain is scanned once
 * (ensureScanned() in GettextTranslationUnitsService), then every subsequent
 * read here is a plain indexed SELECT.
 *
 * Translated text never lives here — msgstr stays in the domain+locale's
 * `.mo` file on disk, same as always. This repository only tracks "which
 * strings exist" and "is this one ignored", both locale-independent.
 *
 * Standalone on purpose, not WpDbSourceRepository: that class's hash-link/
 * parent_id machinery exists to drive the Content board's generic
 * per-language child-table and languageProgress() summation loops — this
 * table has no per-language child at all and must stay invisible to both.
 */
final class GettextUnitRepository
{
    // Unrelated to the REST layer's own `?status=untranslated` query param
    // (GettextDomainRestController::units(), about msgstr emptiness) — this
    // is the DB column, about active vs ignored. Don't wire one into the other.
    private const STATUS_ACTIVE  = 0;
    private const STATUS_IGNORED = -1;
    private const INSERT_CHUNK   = 100;
    // "_v2": the previous key, lingowp_gettext_scanned_domains, was written
    // by a scanner that silently found nothing whenever another plugin's
    // older php-parser was loaded (see WordPressPhpScanner), so its "scanned"
    // entries can't be trusted. A new key makes every component scan once
    // more with the fixed scanner, on every site, with no migration. The old
    // key is left in place and removed by PluginDataCleanup on uninstall.
    public const OPTION_SCANNED = 'lingowp_gettext_scanned_v2';
    // Per (type:domain:locale) count of translated entries in the `.mo`,
    // written whenever this plugin touches that file, so the board's percent
    // is countActive() (DB) over this stored int — no `.mo` parse and no
    // activeUnits() pull on the read path. Ceiling: one small int per
    // installed component × active locale (a few KB); same growth shape as
    // OPTION_SCANNED above.
    private const OPTION_TRANSLATED = 'lingowp_gettext_translated_counts';

    private \wpdb $wpdb;

    public function __construct(\wpdb $wpdb)
    {
        $this->wpdb = $wpdb;
    }

    /**
     * Distinguishes "scanned, found zero strings" from "never scanned" — the
     * only signal ensureScanned() trusts. Rows existing is NOT proof of a
     * real scan: the one-time ignored-units migration pre-seeds ignore rows
     * for domains it has never scanned, so an earlier "rows exist" check
     * here would have let ensureScanned() skip scanning those domains for
     * real and silently hide every one of their non-ignored strings.
     */
    public function wasScanned(string $type, string $domain): bool
    {
        return in_array($this->groupKey($type, $domain), $this->scannedDomains(), true);
    }

    public function markScanned(string $type, string $domain): void
    {
        $key     = $this->groupKey($type, $domain);
        $scanned = $this->scannedDomains();
        if (in_array($key, $scanned, true)) {
            return;
        }
        $scanned[] = $key;
        update_option(self::OPTION_SCANNED, wp_json_encode($scanned));
    }

    /** @return string[] */
    private function scannedDomains(): array
    {
        $raw = json_decode((string) get_option(self::OPTION_SCANNED, '[]'), true);

        return is_array($raw) ? $raw : [];
    }

    /**
     * Every "type:domain" this board has scanned at least once. Lets the
     * header-search progressive scan (GettextBoard.jsx) work out which
     * installed components still need a first scan before their strings are
     * searchable.
     *
     * @return string[]
     */
    public function scannedGroupKeys(): array
    {
        return $this->scannedDomains();
    }

    private function groupKey(string $type, string $domain): string
    {
        return $type . ':' . $domain;
    }

    /**
     * How many strings this domain+locale has translated in its `.mo`, as
     * last recorded by a write here. Null = never recorded yet (the caller
     * counts it once from the file and calls setTranslatedInFile()).
     */
    public function translatedInFile(string $type, string $domain, string $locale): ?int
    {
        $key = $this->localeKey($type, $domain, $locale);
        $all = $this->translatedCounts();

        return array_key_exists($key, $all) ? (int) $all[$key] : null;
    }

    public function setTranslatedInFile(string $type, string $domain, string $locale, int $count): void
    {
        $all = $this->translatedCounts();
        $all[$this->localeKey($type, $domain, $locale)] = max(0, $count);
        update_option(self::OPTION_TRANSLATED, wp_json_encode($all));
    }

    /** @return array<string, int> */
    private function translatedCounts(): array
    {
        $raw = json_decode((string) get_option(self::OPTION_TRANSLATED, '{}'), true);

        return is_array($raw) ? $raw : [];
    }

    private function localeKey(string $type, string $domain, string $locale): string
    {
        return $type . ':' . $domain . ':' . $locale;
    }

    /**
     * Upsert one component's freshly-scanned strings. Never touches `status`
     * — an already-ignored row stays ignored across rescans, and a row
     * pre-seeded by the one-time ignored-units migration (before any real
     * scan ever ran for that domain) survives its first real scan intact.
     *
     * @param array<int, array{msgid: string, context: ?string}> $entries
     */
    public function bulkUpsert(string $type, string $domain, array $entries): void
    {
        if ($entries === []) {
            return;
        }

        $table = TranslationMemorySchema::gettextUnitTable();
        $now   = current_time('mysql', true);

        foreach (array_chunk($entries, self::INSERT_CHUNK) as $chunk) {
            $tuples = [];
            $args   = [];
            foreach ($chunk as $entry) {
                $context = $entry['context'];
                $tuples[] = '(%s, %s, UNHEX(%s), %s, ' . ($context === null ? 'NULL' : '%s') . ', %d, %s, %s, %s)';
                $args[]   = $type;
                $args[]   = $domain;
                $args[]   = bin2hex(self::hashOf($entry['msgid'], $context));
                $args[]   = $entry['msgid'];
                if ($context !== null) {
                    $args[] = $context;
                }
                $args[] = self::STATUS_ACTIVE;
                $args[] = $now; // last_seen_at
                $args[] = $now; // created_at
                $args[] = $now; // updated_at
            }

            $sql = "INSERT INTO {$table}
                        (type, domain, unit_hash, msgid, context, status, last_seen_at, created_at, updated_at)
                    VALUES " . implode(', ', $tuples) . '
                    ON DUPLICATE KEY UPDATE last_seen_at = VALUES(last_seen_at), updated_at = VALUES(updated_at)';

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            $this->wpdb->query($this->wpdb->prepare($sql, ...$args));
        }
    }

    /**
     * Active (non-ignored) source strings for one component, ordered by id ASC.
     * When $search is non-empty, only rows whose msgid contains it — a SQL
     * `LIKE` (not a PHP filter over the full result) so this folds case and
     * accents by the exact same collation as domainsMatching(), keeping
     * "listed as a match" and "shows when the row is expanded" in lockstep.
     *
     * @return array<int, array{msgid: string, context: ?string}>
     */
    public function activeUnits(string $type, string $domain, string $search = ''): array
    {
        $table = TranslationMemorySchema::gettextUnitTable();

        $where = 'type = %s AND domain = %s AND status = %d';
        $args  = [$type, $domain, self::STATUS_ACTIVE];
        if ($search !== '') {
            $where .= ' AND msgid LIKE %s';
            $args[] = '%' . $this->wpdb->esc_like($search) . '%';
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT msgid, context FROM {$table} WHERE {$where} ORDER BY id ASC",
            ...$args
        ), ARRAY_A);

        return array_map(
            static fn (array $row): array => [
                'msgid'   => (string) $row['msgid'],
                'context' => $row['context'] !== null && $row['context'] !== '' ? (string) $row['context'] : null,
            ],
            (array) $rows
        );
    }

    public function countActive(string $type, string $domain): int
    {
        $table = TranslationMemorySchema::gettextUnitTable();

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        return (int) $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE type = %s AND domain = %s AND status = %d",
            $type,
            $domain,
            self::STATUS_ACTIVE
        ));
    }

    /**
     * "type:domain" pairs with at least one active unit whose msgid contains
     * $search — lets the site-wide header search box match a component by
     * its translatable text, not just its name. Only covers components
     * scanned at least once (see ensureScanned()'s lazy-populate model in
     * GettextTranslationUnitsService) — a plugin never opened in this board
     * has no rows here yet, same cache-only reasoning already used by
     * GettextDomainRestController::list() for translated_percent (never
     * triggers a scan just to answer a list/search request).
     *
     * @return array<string, true> keyed by "type:domain", for O(1) lookup
     */
    public function domainsMatching(string $search): array
    {
        $table = TranslationMemorySchema::gettextUnitTable();
        $like  = '%' . $this->wpdb->esc_like($search) . '%';

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT DISTINCT type, domain FROM {$table} WHERE status = %d AND msgid LIKE %s",
            self::STATUS_ACTIVE,
            $like
        ), ARRAY_A);

        $out = [];
        foreach ((array) $rows as $row) {
            $out[$row['type'] . ':' . $row['domain']] = true;
        }

        return $out;
    }

    public function ignore(string $type, string $domain, string $msgid, ?string $context): void
    {
        $this->setStatus($type, $domain, $msgid, $context, self::STATUS_IGNORED);
    }

    private function setStatus(string $type, string $domain, string $msgid, ?string $context, int $status): void
    {
        $table = TranslationMemorySchema::gettextUnitTable();

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$table} SET status = %d, updated_at = %s
             WHERE type = %s AND domain = %s AND unit_hash = UNHEX(%s)",
            $status,
            current_time('mysql', true),
            $type,
            $domain,
            bin2hex(self::hashOf($msgid, $context))
        ));
    }

    /**
     * Deletes rows for this domain whose hash wasn't in the latest scan.
     * Hard delete, not flag-and-review: GettextTemplateGenerator::scan()
     * walks the component's entire source tree exhaustively every time (no
     * bounded crawl scope, unlike the HTML board's network crawl), so a
     * string genuinely gone from a complete scan really is gone — exact
     * parity with the pre-this-table behavior, which dropped it from the
     * list with zero trace too.
     *
     * @param string[] $seenHashes hex-encoded unit_hash values from the latest scan
     */
    public function deleteMissing(string $type, string $domain, array $seenHashes): int
    {
        $table = TranslationMemorySchema::gettextUnitTable();

        if ($seenHashes === []) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            $result = $this->wpdb->query($this->wpdb->prepare(
                "DELETE FROM {$table} WHERE type = %s AND domain = %s",
                $type,
                $domain
            ));

            return is_int($result) ? $result : 0;
        }

        $placeholders = implode(', ', array_fill(0, count($seenHashes), 'UNHEX(%s)'));

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $result = $this->wpdb->query($this->wpdb->prepare(
            "DELETE FROM {$table} WHERE type = %s AND domain = %s AND unit_hash NOT IN ({$placeholders})",
            $type,
            $domain,
            ...$seenHashes
        ));

        return is_int($result) ? $result : 0;
    }

    /**
     * Hex-encoded unit_hash for one entry — used by refresh() to build
     * $seenHashes for deleteMissing(). Static (like hashOf()) so
     * tests/gettext_hash_check.php can assert the formula with no DB.
     */
    public static function hashHex(string $msgid, ?string $context): string
    {
        return bin2hex(self::hashOf($msgid, $context));
    }

    /**
     * SHA-256 of the raw, byte-exact key — deliberately NOT
     * StringNormalizer::normalize()'d. Every other original_hash in this
     * plugin normalizes (case-folds, collapses whitespace) because that's the
     * Content board's own frozen contract; gettext msgid lookups everywhere
     * (.mo Translation_Entry::key(), the old GettextIgnoredUnitsStore) are
     * byte-exact and case-sensitive, so normalizing here would silently
     * collide distinct strings like "Save" and "save" into one row.
     *
     * Static: doesn't touch $this->wpdb, and staying static lets
     * tests/gettext_hash_check.php assert this formula directly, with no DB
     * and no instantiation.
     */
    private static function hashOf(string $msgid, ?string $context): string
    {
        $key = $context !== null && $context !== '' ? $context . "\x04" . $msgid : $msgid;

        return hash('sha256', $key, true);
    }
}
