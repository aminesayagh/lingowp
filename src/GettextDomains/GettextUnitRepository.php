<?php

namespace LingoWP\GettextDomains;

use LingoWP\Database\TranslationMemorySchema;

final class GettextUnitRepository
{
    private const STATUS_ACTIVE  = 0;
    private const STATUS_IGNORED = -1;
    private const INSERT_CHUNK   = 100;
    private const OPTION_SCANNED = 'lingowp_gettext_scanned_domains';
    private const OPTION_TRANSLATED = 'lingowp_gettext_translated_counts';

    private \wpdb $wpdb;

    public function __construct(\wpdb $wpdb)
    {
        $this->wpdb = $wpdb;
    }

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

    private function scannedDomains(): array
    {
        $raw = json_decode((string) get_option(self::OPTION_SCANNED, '[]'), true);

        return is_array($raw) ? $raw : [];
    }

    public function scannedGroupKeys(): array
    {
        return $this->scannedDomains();
    }

    private function groupKey(string $type, string $domain): string
    {
        return $type . ':' . $domain;
    }

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

    private function translatedCounts(): array
    {
        $raw = json_decode((string) get_option(self::OPTION_TRANSLATED, '{}'), true);

        return is_array($raw) ? $raw : [];
    }

    private function localeKey(string $type, string $domain, string $locale): string
    {
        return $type . ':' . $domain . ':' . $locale;
    }

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
                $args[] = $now;
                $args[] = $now;
                $args[] = $now;
            }

            $sql = "INSERT INTO {$table}
                        (type, domain, unit_hash, msgid, context, status, last_seen_at, created_at, updated_at)
                    VALUES " . implode(', ', $tuples) . '
                    ON DUPLICATE KEY UPDATE last_seen_at = VALUES(last_seen_at), updated_at = VALUES(updated_at)';

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            $this->wpdb->query($this->wpdb->prepare($sql, ...$args));
        }
    }

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

    public static function hashHex(string $msgid, ?string $context): string
    {
        return bin2hex(self::hashOf($msgid, $context));
    }

    private static function hashOf(string $msgid, ?string $context): string
    {
        $key = $context !== null && $context !== '' ? $context . "\x04" . $msgid : $msgid;

        return hash('sha256', $key, true);
    }
}
