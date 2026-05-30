<?php

namespace TransLangs\TranslationMemory\Infrastructure;

use TransLangs\TranslationMemory\Domain\TranslationMemoryRepository;
use TransLangs\TranslationMemory\Domain\TranslationSourceIdentity;
use TransLangs\TranslationMemory\Domain\TranslationStatus;

/**
 * Database access layer for the translation memory.
 *
 * Hard rule: always operate in bulk. A page with 100 strings must cost one
 * SELECT and at most one INSERT, never one query per string.
 *
 * Idempotent discovery: insertMissingStrings() relies on the table's source
 * identity UNIQUE KEY plus INSERT IGNORE, so repeated discovery does not create
 * duplicate translation-memory rows.
 */
class WpDbTranslationMemoryRepository implements TranslationMemoryRepository
{
    /** Status: discovered but not yet translated. */
    public const STATUS_DISCOVERED = TranslationStatus::DISCOVERED;
    /** Status: filled by a machine translation provider. */
    public const STATUS_MACHINE = TranslationStatus::MACHINE;
    /** Status: confirmed by a human. */
    public const STATUS_REVIEWED = TranslationStatus::REVIEWED;
    /** Status: deliberately left in the source language. */
    public const STATUS_IGNORED = TranslationStatus::IGNORED;
    /** Status: translated but flagged for a human to check. */
    public const STATUS_NEEDS_REVIEW = TranslationStatus::NEEDS_REVIEW;

    /**
     * @return array<int, string> status code => human label
     */
    public static function statusLabels(): array
    {
        return TranslationStatus::labels();
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
    public function findManyByHashes(
        array $hashes,
        string $sourceLang,
        string $targetLang,
        int $sourceId,
        string $sourceType,
        string $context
    ): array
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

            [$sourceWhere, $sourceParams] = $this->sourceIdentityWhere($sourceId, $sourceType, $context);

            // $table is sanitized in table(); placeholders are bound values.
            $sql = $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE source_lang = %s AND target_lang = %s
                 {$sourceWhere}
                 AND original_hash IN ({$placeholders})",
                array_merge([$sourceLang, $targetLang], $sourceParams, $chunk)
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
     * Each identity provides the structural source key and original text.
     * Duplicates are ignored at the DB level.
     *
     * @param TranslationSourceIdentity[] $identities
     * @return int Number of rows actually inserted (new discoveries).
     */
    public function insertMissingStrings(array $identities, string $sourceLang, string $targetLang): int
    {
        if ($identities === []) {
            return 0;
        }

        global $wpdb;
        $table = $this->table();
        $now   = current_time('mysql');
        $inserted = 0;

        foreach (array_chunk($identities, self::CHUNK) as $chunk) {
            $values       = [];
            $placeholders = [];

            foreach ($chunk as $identity) {
                if (!$identity instanceof TranslationSourceIdentity) {
                    continue;
                }

                $row = $identity->toDatabaseRow();
                $placeholders[] = '(%s,%s,%d,%s,%s,%s,%s,%s,%d,%s,%s,%s)';

                array_push(
                    $values,
                    $sourceLang,
                    $targetLang,
                    $row['source_id'],
                    $row['source_type'],
                    $row['original_hash'],
                    $row['original_text'],
                    $row['normalized_text'],
                    $row['context'],
                    self::STATUS_DISCOVERED,
                    $now, // last_seen_at
                    $now, // created_at
                    $now  // updated_at
                );
            }

            $cols = 'source_lang, target_lang, source_id, source_type, original_hash, original_text, '
                  . 'normalized_text, context, status, '
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
    public function touchLastSeen(
        array $hashes,
        string $sourceLang,
        string $targetLang,
        int $sourceId,
        string $sourceType,
        string $context
    ): void
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

            [$sourceWhere, $sourceParams] = $this->sourceIdentityWhere($sourceId, $sourceType, $context);

            $sql = $wpdb->prepare(
                "UPDATE {$table} SET last_seen_at = %s
                 WHERE source_lang = %s AND target_lang = %s
                 {$sourceWhere}
                 AND original_hash IN ({$placeholders})",
                array_merge([$now, $sourceLang, $targetLang], $sourceParams, $chunk)
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

    public function markDiscovered(int $id): void
    {
        global $wpdb;

        $wpdb->update(
            $this->table(),
            ['status' => self::STATUS_DISCOVERED, 'updated_at' => current_time('mysql')],
            ['id' => $id],
            ['%d', '%s'],
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

    public function markNeedsReview(int $id): void
    {
        global $wpdb;

        $wpdb->update(
            $this->table(),
            ['status' => self::STATUS_NEEDS_REVIEW, 'updated_at' => current_time('mysql')],
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
     *   source_lang?: string, target_lang?: string, status?: int|null, statuses?: int[],
     *   search_original?: string, search_translated?: string, source_id?: int|null,
     *   source_type?: string|null, context?: string|null
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

        if (!empty($filters['statuses']) && is_array($filters['statuses'])) {
            $statuses = array_values(array_unique(array_map('intval', $filters['statuses'])));

            if ($statuses !== []) {
                $clauses[] = 'status IN (' . implode(',', array_fill(0, count($statuses), '%d')) . ')';
                array_push($params, ...$statuses);
            }
        } elseif (isset($filters['status']) && $filters['status'] !== null && $filters['status'] !== '') {
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

        if (array_key_exists('source_id', $filters) && $filters['source_id'] !== '' && $filters['source_id'] !== null) {
            $clauses[] = 'source_id = %d';
            $params[]  = (int) $filters['source_id'];
        }

        if (array_key_exists('source_type', $filters) && $filters['source_type'] !== '' && $filters['source_type'] !== null) {
            $clauses[] = 'source_type = %s';
            $params[]  = (string) $filters['source_type'];
        }

        if (array_key_exists('context', $filters) && $filters['context'] !== '' && $filters['context'] !== null) {
            $clauses[] = 'context = %s';
            $params[]  = (string) $filters['context'];
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
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function sourceIdentityWhere(int $sourceId, string $sourceType, string $context): array
    {
        return [
            ' AND source_id = %d AND source_type = %s AND context = %s',
            [$sourceId, $sourceType, $context],
        ];
    }

    /**
     * Resolved, safe table name. TranslationMemorySchema::stringsTable() returns
     * $wpdb->prefix . 'translangs_strings'; the prefix is controlled by the
     * site, not user input, so it is safe to interpolate.
     */
    private function table(): string
    {
        return TranslationMemorySchema::stringsTable();
    }
}
