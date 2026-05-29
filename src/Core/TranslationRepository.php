<?php

namespace TransLangs\Core;

use TransLangs\Setup\Schema;

/**
 * Database access layer for the translation memory.
 *
 * Hard rule: always operate in bulk. A page with 100 strings must cost one
 * SELECT and at most one INSERT, never one query per string.
 *
 * Idempotent discovery: insertMissingStrings() relies on the table's
 * UNIQUE KEY (source_lang, target_lang, original_hash) plus INSERT IGNORE, so
 * the first visit to /fr/about inserts the discovered strings and every later
 * visit inserts nothing. That is what makes the Step 15 deliverable hold.
 */
class TranslationRepository
{
    /** Status: discovered but not yet translated. */
    public const STATUS_DISCOVERED = 0;
    /** Status: filled by a machine translation provider. */
    public const STATUS_MACHINE = 1;
    /** Status: confirmed by a human. */
    public const STATUS_REVIEWED = 2;
    /** Status: deliberately left in the source language. */
    public const STATUS_IGNORED = 3;
    /** Status: translated but flagged for a human to check. */
    public const STATUS_NEEDS_REVIEW = 4;

    /**
     * @return array<int, string> status code => human label
     */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_DISCOVERED   => 'Discovered',
            self::STATUS_MACHINE      => 'Machine',
            self::STATUS_REVIEWED     => 'Reviewed',
            self::STATUS_IGNORED      => 'Ignored',
            self::STATUS_NEEDS_REVIEW => 'Needs review',
        ];
    }

    /**
     * Safety cap so a single huge page cannot build an unbounded SQL statement.
     * Lookups and inserts are chunked to this size.
     */
    private const CHUNK = 200;

    /**
     * Look up many translations at once by hash.
     *
     * @param string[] $hashes
     * @return array<string, array<string, mixed>> Keyed by original_hash.
     */
    public function findManyByHashes(array $hashes, string $sourceLang, string $targetLang): array
    {
        $hashes = array_values(array_unique(array_filter($hashes)));

        if ($hashes === []) {
            return [];
        }

        global $wpdb;
        $table  = $this->table();
        $result = [];

        foreach (array_chunk($hashes, self::CHUNK) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%s'));

            // $table is sanitized in table(); placeholders are bound values.
            $sql = $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE source_lang = %s AND target_lang = %s
                 AND original_hash IN ({$placeholders})",
                array_merge([$sourceLang, $targetLang], $chunk)
            );

            $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];

            foreach ($rows as $row) {
                $result[$row['original_hash']] = $row;
            }
        }

        return $result;
    }

    /**
     * Insert discovered strings that are not yet stored.
     *
     * Each row must provide: hash, original_text, normalized_text, context,
     * source_url. Duplicates (same lang pair + hash) are ignored at the DB
     * level, so callers may safely pass strings that already exist.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return int Number of rows actually inserted (new discoveries).
     */
    public function insertMissingStrings(array $rows, string $sourceLang, string $targetLang): int
    {
        if ($rows === []) {
            return 0;
        }

        global $wpdb;
        $table = $this->table();
        $now   = current_time('mysql');
        $inserted = 0;

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $values       = [];
            $placeholders = [];

            foreach ($chunk as $row) {
                $placeholders[] = '(%s,%s,%s,%s,%s,%s,%s,%d,%s,%s,%s)';

                array_push(
                    $values,
                    $sourceLang,
                    $targetLang,
                    (string) ($row['hash'] ?? ''),
                    (string) ($row['original_text'] ?? ''),
                    (string) ($row['normalized_text'] ?? ''),
                    (string) ($row['context'] ?? ''),
                    isset($row['source_url']) ? (string) $row['source_url'] : null,
                    self::STATUS_DISCOVERED,
                    $now, // last_seen_at
                    $now, // created_at
                    $now  // updated_at
                );
            }

            $cols = 'source_lang, target_lang, original_hash, original_text, '
                  . 'normalized_text, context, source_url, status, '
                  . 'last_seen_at, created_at, updated_at';

            // INSERT IGNORE skips rows that collide with the UNIQUE KEY, making
            // re-discovery a no-op. $table is sanitized; all values are bound.
            $sql = $wpdb->prepare(
                "INSERT IGNORE INTO {$table} ({$cols}) VALUES " . implode(',', $placeholders),
                $values
            );

            $wpdb->query($sql);
            $inserted += (int) $wpdb->rows_affected;
        }

        return $inserted;
    }

    /**
     * Touch last_seen_at for strings still appearing on pages, so stale ones
     * can be pruned later. Bulk, best-effort; failures are non-fatal.
     *
     * @param string[] $hashes
     */
    public function touchLastSeen(array $hashes, string $sourceLang, string $targetLang): void
    {
        $hashes = array_values(array_unique(array_filter($hashes)));

        if ($hashes === []) {
            return;
        }

        global $wpdb;
        $table = $this->table();
        $now   = current_time('mysql');

        foreach (array_chunk($hashes, self::CHUNK) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%s'));

            $sql = $wpdb->prepare(
                "UPDATE {$table} SET last_seen_at = %s
                 WHERE source_lang = %s AND target_lang = %s
                 AND original_hash IN ({$placeholders})",
                array_merge([$now, $sourceLang, $targetLang], $chunk)
            );

            $wpdb->query($sql);
        }
    }

    public function saveTranslation(int $id, string $translatedText, int $status): void
    {
        global $wpdb;

        $wpdb->update(
            $this->table(),
            [
                'translated_text' => $translatedText,
                'status'          => $status,
                'updated_at'      => current_time('mysql'),
            ],
            ['id' => $id],
            ['%s', '%d', '%s'],
            ['%d']
        );
    }

    public function markReviewed(int $id): void
    {
        global $wpdb;

        $wpdb->update(
            $this->table(),
            ['status' => self::STATUS_REVIEWED, 'updated_at' => current_time('mysql')],
            ['id' => $id],
            ['%d', '%s'],
            ['%d']
        );
    }

    public function ignoreString(int $id): void
    {
        global $wpdb;

        $wpdb->update(
            $this->table(),
            ['status' => self::STATUS_IGNORED, 'updated_at' => current_time('mysql')],
            ['id' => $id],
            ['%d', '%s'],
            ['%d']
        );
    }

    public function delete(int $id): void
    {
        global $wpdb;

        $wpdb->delete($this->table(), ['id' => $id], ['%d']);
    }

    /**
     * Fetch a single row by id.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        global $wpdb;
        $table = $this->table();

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Paginated, filtered listing for the admin table.
     *
     * @param array{
     *   source_lang?: string, target_lang?: string, status?: int|null,
     *   search_original?: string, search_translated?: string, source_url?: string
     * } $filters
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function query(array $filters, int $page, int $perPage): array
    {
        global $wpdb;
        $table = $this->table();

        [$where, $params] = $this->buildWhere($filters);

        $countSql = "SELECT COUNT(*) FROM {$table} {$where}";
        $total    = (int) $wpdb->get_var(
            $params ? $wpdb->prepare($countSql, $params) : $countSql
        );

        $offset   = max(0, ($page - 1) * $perPage);
        $dataSql  = "SELECT * FROM {$table} {$where} ORDER BY updated_at DESC LIMIT %d OFFSET %d";
        $dataArgs = array_merge($params, [$perPage, $offset]);

        $rows = $wpdb->get_results($wpdb->prepare($dataSql, $dataArgs), ARRAY_A) ?: [];

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Distinct language pairs present in the memory, for the filter dropdown.
     *
     * @return array<int, array{source_lang: string, target_lang: string}>
     */
    public function languagePairs(): array
    {
        global $wpdb;
        $table = $this->table();

        $rows = $wpdb->get_results(
            "SELECT DISTINCT source_lang, target_lang FROM {$table} ORDER BY source_lang, target_lang",
            ARRAY_A
        ) ?: [];

        return $rows;
    }

    /**
     * Build a WHERE clause and its bound parameters from the filter set.
     *
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function buildWhere(array $filters): array
    {
        $clauses = [];
        $params  = [];

        if (!empty($filters['source_lang'])) {
            $clauses[] = 'source_lang = %s';
            $params[]  = $filters['source_lang'];
        }

        if (!empty($filters['target_lang'])) {
            $clauses[] = 'target_lang = %s';
            $params[]  = $filters['target_lang'];
        }

        if (isset($filters['status']) && $filters['status'] !== null && $filters['status'] !== '') {
            $clauses[] = 'status = %d';
            $params[]  = (int) $filters['status'];
        }

        if (!empty($filters['search_original'])) {
            $clauses[] = 'original_text LIKE %s';
            $params[]  = '%' . $this->likeEscape($filters['search_original']) . '%';
        }

        if (!empty($filters['search_translated'])) {
            $clauses[] = 'translated_text LIKE %s';
            $params[]  = '%' . $this->likeEscape($filters['search_translated']) . '%';
        }

        if (!empty($filters['source_url'])) {
            $clauses[] = 'source_url LIKE %s';
            $params[]  = '%' . $this->likeEscape($filters['source_url']) . '%';
        }

        $where = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';

        return [$where, $params];
    }

    private function likeEscape(string $text): string
    {
        global $wpdb;

        return method_exists($wpdb, 'esc_like')
            ? $wpdb->esc_like($text)
            : addcslashes($text, '_%\\');
    }

    /**
     * Resolved, safe table name. Schema::stringsTable() returns
     * $wpdb->prefix . 'translangs_strings'; the prefix is controlled by the
     * site, not user input, so it is safe to interpolate.
     */
    private function table(): string
    {
        return Schema::stringsTable();
    }
}