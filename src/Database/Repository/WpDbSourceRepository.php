<?php

namespace LingoWP\Database\Repository;

use LingoWP\Shared\Source\Type\HtmlSource;
use LingoWP\Shared\Text\IdentityKey;
use LingoWP\Shared\Source\SourceRecord;
use LingoWP\Shared\Source\SourceRef;
use LingoWP\Shared\Text\TranslationKey;
use LingoWP\Shared\Text\TranslatableText;
use LingoWP\Shared\Source\TranslationStatus;
use LingoWP\Shared\Parsing\TranslationSubmissionGuard;
use LingoWP\Database\TranslationMemorySchema;

final class WpDbSourceRepository
{
    private const INSERT_CHUNK = 100;
    private const SELECT_CHUNK = 200;

    private static ?self $primed = null;

    private \wpdb $wpdb;
    private WpDbBoardQueries $board;

    public function __construct(\wpdb $wpdb, WpDbBoardQueries $board)
    {
        $this->wpdb  = $wpdb;
        $this->board = $board;
    }

    public static function instance(): self
    {
        if (self::$primed instanceof self) {
            return self::$primed;
        }

        global $wpdb;

        return new self($wpdb, new WpDbBoardQueries($wpdb));
    }

    public static function prime(self $instance): void
    {
        self::$primed = $instance;
    }

    public function upsertSources(array $records): int
    {
        $inserted = 0;
        foreach ($this->groupByTable($records) as $tableKey => $group) {
            $inserted += $this->insertParents($tableKey, $group);
            if ($tableKey === 'html') {
                $this->refreshHtmlSourceUrls($group);
            }
        }

        return $inserted;
    }

    private function insertParents(string $tableKey, array $records): int
    {
        $records = array_values(array_filter(
            $records,
            static fn (SourceRecord $r): bool => TranslatableText::isTranslatable($r->originalText())
        ));

        if ($records === []) {
            return 0;
        }

        $table   = $this->parent($tableKey);
        $columns = array_keys($records[0]->row());
        $now     = current_time('mysql');
        $colList = implode(', ', array_merge($columns, ['status', 'created_at', 'updated_at']));
        $count   = 0;

        foreach (array_chunk($records, self::INSERT_CHUNK) as $chunk) {
            $tuples = [];
            $args   = [];
            foreach ($chunk as $record) {
                $row   = $record->row();
                $cells = [];
                foreach ($columns as $column) {
                    $cells[] = $this->cell($column, $row[$column], $args);
                }
                $cells[]  = (string) TranslationStatus::SOURCE_DISCOVERED;
                $cells[]  = '%s';
                $cells[]  = '%s';
                $args[]   = $now;
                $args[]   = $now;
                $tuples[] = '(' . implode(', ', $cells) . ')';
            }

            $sql = "INSERT IGNORE INTO {$table} ({$colList}) VALUES " . implode(', ', $tuples);

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            $result = $this->wpdb->query($this->wpdb->prepare($sql, ...$args));
            if (is_int($result) && $result > 0) {
                $count += $result;
            }
        }

        return $count;
    }

    private function refreshHtmlSourceUrls(array $records): void
    {
        $records = array_values(array_filter(
            $records,
            static fn (SourceRecord $record): bool => $record instanceof HtmlSource && $record->sourceUrl() !== null
        ));
        if ($records === []) {
            return;
        }

        $table = $this->parent('html');
        foreach (array_chunk($records, self::INSERT_CHUNK) as $chunk) {
            $selects = [];
            $args    = [];
            foreach ($chunk as $record) {
                $identity  = $record->identity();
                $selects[] = 'SELECT UNHEX(%s) AS original_hash, %s AS source_url';
                $args[]    = bin2hex($identity['original_hash']);
                $args[]    = $record->sourceUrl();
            }

            $incoming = implode(' UNION ALL ', $selects);
            $sql = "UPDATE {$table} p
                    INNER JOIN ({$incoming}) incoming
                       ON incoming.original_hash = p.original_hash
                    SET p.source_url = incoming.source_url
                    WHERE NOT (p.source_url <=> incoming.source_url)";

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            $this->wpdb->query($this->wpdb->prepare($sql, ...$args));
        }
    }

    public function fetchHashTranslations(string $parentTableKey, array $hashes, string $langCode): array
    {
        if ($hashes === [] || $langCode === '') {
            return [];
        }

        $child = $this->parent($parentTableKey) . '_translation';
        $out   = [];

        foreach (array_chunk(array_values($hashes), self::SELECT_CHUNK) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), 'UNHEX(%s)'));
            $args         = array_merge([$langCode], array_map('bin2hex', $chunk));

            $sql = "SELECT LOWER(HEX(original_hash)) AS h, translated_text
                    FROM {$child}
                    WHERE lang_code = %s
                      AND translated_text IS NOT NULL
                      AND original_hash IN ({$placeholders})";

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, ...$args), ARRAY_A);
            foreach ((array) $rows as $row) {
                $out[strtolower((string) $row['h'])] = (string) $row['translated_text'];
            }
        }

        return $out;
    }

    public function fetchAllHashTranslations(string $parentTableKey, string $langCode): array
    {
        if ($langCode === '') {
            return [];
        }

        $child = $this->parent($parentTableKey) . '_translation';
        $sql   = "SELECT LOWER(HEX(original_hash)) AS h, translated_text
                  FROM {$child} WHERE lang_code = %s AND translated_text IS NOT NULL";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, $langCode), ARRAY_A);

        $out = [];
        foreach ((array) $rows as $row) {
            $out[strtolower((string) $row['h'])] = (string) $row['translated_text'];
        }

        return $out;
    }

    public function fetchParentTranslations(string $parentTableKey, array $lookups, string $langCode): array
    {
        if ($lookups === [] || $langCode === '') {
            return [];
        }

        $parent  = $this->parent($parentTableKey);
        $child   = $parent . '_translation';
        $columns = array_keys($lookups[0]);
        $select  = $this->selectIdentity($columns);
        $out     = [];

        foreach (array_chunk($lookups, self::SELECT_CHUNK) as $chunk) {
            $tuples = [];
            $args   = [$langCode];
            foreach ($chunk as $lookup) {
                $cells = [];
                foreach ($columns as $column) {
                    $cells[] = $this->cell($column, $lookup[$column], $args);
                }
                $tuples[] = '(' . implode(', ', $cells) . ')';
            }
            $tupleList = '(' . implode(', ', $columns) . ') IN (' . implode(', ', $tuples) . ')';

            $sql = "SELECT {$select}, tr.translated_text AS test_text
                    FROM {$parent} p
                    INNER JOIN {$child} tr ON tr.parent_id = p.id AND tr.lang_code = %s
                        AND tr.translated_text IS NOT NULL
                    WHERE p.status = 0 AND {$tupleList}";

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, ...$args), ARRAY_A);
            foreach ((array) $rows as $row) {
                $out[$this->rowIdentityKey($columns, $row)] = (string) $row['test_text'];
            }
        }

        return $out;
    }

    public function fetchMetaLeafTranslations(string $parentTableKey, array $lookups, string $langCode): array
    {
        if ($lookups === [] || $langCode === '') {
            return [];
        }

        $parent  = $this->parent($parentTableKey);
        $child   = $parent . '_translation';
        $columns = array_keys($lookups[0]);
        $select  = $this->selectIdentity($columns);
        $out     = [];

        foreach (array_chunk($lookups, self::SELECT_CHUNK) as $chunk) {
            $tuples = [];
            $args   = [$langCode];
            foreach ($chunk as $lookup) {
                $cells = [];
                foreach ($columns as $column) {
                    $cells[] = $this->cell($column, $lookup[$column], $args);
                }
                $tuples[] = '(' . implode(', ', $cells) . ')';
            }
            $tupleList = '(' . implode(', ', $columns) . ') IN (' . implode(', ', $tuples) . ')';

            $sql = "SELECT {$select}, p.context AS context, tr.translated_text AS translated_text
                    FROM {$parent} p
                    INNER JOIN {$child} tr ON tr.parent_id = p.id AND tr.lang_code = %s
                        AND tr.translated_text IS NOT NULL
                    WHERE p.status = 0 AND {$tupleList}";

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, ...$args), ARRAY_A);
            foreach ((array) $rows as $row) {
                $identityKey = $this->rowIdentityKey($columns, $row);
                $context     = (string) ($row['context'] ?? '');
                $out[$identityKey][$context] = (string) $row['translated_text'];
            }
        }

        return $out;
    }

    public function rawPostMetaValue(int $postId, string $metaKey)
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $raw = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT meta_value FROM {$this->wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
            $postId,
            $metaKey
        ));

        return $raw === null ? null : maybe_unserialize($raw);
    }

    public function rawTermMetaValue(int $termId, string $metaKey)
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $raw = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT meta_value FROM {$this->wpdb->termmeta} WHERE term_id = %d AND meta_key = %s LIMIT 1",
            $termId,
            $metaKey
        ));

        return $raw === null ? null : maybe_unserialize($raw);
    }

    public function fetchTranslationsByParentIds(array $parentIds, string $langCode, string $tableKey = 'meta'): array
    {
        if ($parentIds === [] || $langCode === '') {
            return [];
        }

        $child = $this->parent($tableKey) . '_translation';
        $out   = [];

        foreach (array_chunk(array_values($parentIds), self::SELECT_CHUNK) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '%d'));
            $args         = array_merge([$langCode], $chunk);

            $sql = "SELECT parent_id, translated_text
                    FROM {$child}
                    WHERE lang_code = %s
                      AND translated_text IS NOT NULL
                      AND parent_id IN ({$placeholders})";

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, ...$args), ARRAY_A);
            foreach ((array) $rows as $row) {
                $out[(int) $row['parent_id']] = (string) $row['translated_text'];
            }
        }

        return $out;
    }

    public function listPostCollections(string $langCode, string $search = '', array $filters = []): array
    {
        return $this->board->listPostCollections($langCode, $search, $filters);
    }

    public function listPostTexts(int $postId, string $langCode, string $search = '', array $filters = []): array
    {
        return $this->board->listPostTexts($postId, $langCode, $search, $filters);
    }

    public function listAllPostTexts(string $langCode): array
    {
        return $this->board->listAllPostTexts($langCode);
    }

    public function optionCollectionSummary(string $langCode, string $search = '', array $filters = []): array
    {
        return $this->board->optionCollectionSummary($langCode, $search, $filters);
    }

    public function listOptionTexts(string $langCode, string $search = '', array $filters = []): array
    {
        return $this->board->listOptionTexts($langCode, $search, $filters);
    }

    public function userCollectionSummary(string $langCode, string $search = '', array $filters = []): array
    {
        return $this->board->userCollectionSummary($langCode, $search, $filters);
    }

    public function listUserTexts(string $langCode, string $search = '', array $filters = []): array
    {
        return $this->board->listUserTexts($langCode, $search, $filters);
    }

    public function listTermCollections(string $langCode, string $search = '', array $filters = []): array
    {
        return $this->board->listTermCollections($langCode, $search, $filters);
    }

    public function listTermTexts(int $termTaxonomyId, string $langCode): array
    {
        return $this->board->listTermTexts($termTaxonomyId, $langCode);
    }

    public function listTaxonomyTexts(string $taxonomy, string $langCode, string $search = '', array $filters = []): array
    {
        return $this->board->listTaxonomyTexts($taxonomy, $langCode, $search, $filters);
    }

    public function postMetaCounts(string $langCode, string $search = '', array $filters = []): array
    {
        return $this->board->postMetaCounts($langCode, $search, $filters);
    }

    public function listMetaTexts(int $postId, string $langCode, string $search = '', array $filters = []): array
    {
        return $this->board->listMetaTexts($postId, $langCode, $search, $filters);
    }

    public function listAllMetaTexts(string $langCode): array
    {
        return $this->board->listAllMetaTexts($langCode);
    }

    public function termMetaCounts(string $langCode, string $search = '', array $filters = []): array
    {
        return $this->board->termMetaCounts($langCode, $search, $filters);
    }

    public function listTermMetaTexts(string $taxonomy, string $langCode, string $search = '', array $filters = []): array
    {
        return $this->board->listTermMetaTexts($taxonomy, $langCode, $search, $filters);
    }

    public function listMediaPostTexts(string $langCode, string $search = '', array $filters = []): array
    {
        return $this->board->listMediaPostTexts($langCode, $search, $filters);
    }

    public function listMediaAltTexts(string $langCode, string $search = '', array $filters = []): array
    {
        return $this->board->listMediaAltTexts($langCode, $search, $filters);
    }

    public function htmlCollectionSummary(string $langCode, string $search = '', array $filters = []): array
    {
        return $this->board->htmlCollectionSummary($langCode, $search, $filters);
    }

    public function listHtmlTexts(string $langCode, string $search = '', array $filters = []): array
    {
        return $this->board->listHtmlTexts($langCode, $search, $filters);
    }

    public function listStaleHtmlSources(string $langCode, string $search = '', array $filters = []): array
    {
        return $this->board->listStaleHtmlSources($langCode, $search, $filters);
    }

    public function listIgnoredTexts(string $search = ''): array
    {
        return $this->board->listIgnoredTexts($search);
    }

    public function languageProgress(string $langCode): array
    {
        return $this->board->languageProgress($langCode);
    }

    public function languageProgressAll(array $langCodes): array
    {
        return $this->board->languageProgressAll($langCodes);
    }

    public function touchHtmlSeen(array $rawHashes): void
    {
        if ($rawHashes === []) {
            return;
        }

        $parent       = $this->parent('html');
        $hexes        = array_map('bin2hex', array_values(array_unique($rawHashes)));
        $placeholders = implode(', ', array_fill(0, count($hexes), 'UNHEX(%s)'));

        $sql = "UPDATE {$parent} SET last_seen_at = %s WHERE original_hash IN ({$placeholders})";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $this->wpdb->query($this->wpdb->prepare($sql, current_time('mysql'), ...$hexes));
    }

    public function flagStaleHtmlSources(int $graceDays): int
    {
        $parent = $this->parent('html');

        $sql = "UPDATE {$parent}
                    SET status = %d
                  WHERE status = %d
                    AND last_seen_at IS NOT NULL
                    AND last_seen_at < (NOW() - INTERVAL %d DAY)";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $result = $this->wpdb->query($this->wpdb->prepare(
            $sql,
            TranslationStatus::SOURCE_STALE,
            TranslationStatus::SOURCE_DISCOVERED,
            $graceDays
        ));

        return is_int($result) ? $result : 0;
    }

    public function deleteStaleHtmlSources(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        $parent       = $this->parent('html');
        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));

        $sql = "DELETE FROM {$parent} WHERE status = %d AND id IN ({$placeholders})";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $result = $this->wpdb->query($this->wpdb->prepare(
            $sql,
            TranslationStatus::SOURCE_STALE,
            ...$ids
        ));

        return is_int($result) ? $result : 0;
    }

    public function loadSourceText(SourceRef $ref): ?string
    {
        $parent = $this->parent($ref->parentTableKey());

        $value = $this->wpdb->get_var( // phpcs:ignore WordPress.DB
            $this->wpdb->prepare(
                "SELECT original_text FROM {$parent} WHERE id = %d AND status = %d",
                $ref->id,
                TranslationStatus::SOURCE_DISCOVERED
            )
        );

        return is_string($value) ? $value : null;
    }

    public function loadSourceValidationContext(SourceRef $ref): ?array
    {
        $parent = $this->parent($ref->parentTableKey());
        $isMeta = in_array($ref->parentTableKey(), TranslationMemorySchema::PARENT_ID_CHILD_FKS, true);
        $isPost = $ref->kind === 'post';
        $selected = $isMeta ? 'original_text, meta_key' : ($isPost ? 'original_text, field, post_id' : 'original_text');

        $row = $this->wpdb->get_row( // phpcs:ignore WordPress.DB
            $this->wpdb->prepare(
                "SELECT {$selected} FROM {$parent} WHERE id = %d AND status = %d",
                $ref->id,
                TranslationStatus::SOURCE_DISCOVERED
            ),
            ARRAY_A
        );

        if (!is_array($row) || !isset($row['original_text'])) {
            return null;
        }

        return [
            'text'     => (string) $row['original_text'],
            'kind'     => $ref->kind,
            'meta_key' => $isMeta ? (string) ($row['meta_key'] ?? '') : null,
            'field'    => $isPost ? (string) ($row['field'] ?? '') : null,
            'post_id'  => $isPost ? (int) ($row['post_id'] ?? 0) : null,
        ];
    }

    public function fetchPostMetaTranslationStates(int $postId, array $metaKeys, string $langCode): array
    {
        $metaKeys = array_values(array_unique(array_filter($metaKeys, static fn ($k): bool => is_string($k) && $k !== '')));
        if ($postId <= 0 || $metaKeys === [] || $langCode === '') {
            return [];
        }

        $parent = $this->parent('meta');
        $child  = $parent . '_translation';
        $keySet = implode(', ', array_fill(0, count($metaKeys), '%s'));

        $sql = "SELECT p.meta_key AS meta_key, p.original_text AS original_text,
                       t.translated_text AS translated_text
                FROM {$parent} p
                LEFT JOIN {$child} t ON t.parent_id = p.id AND t.lang_code = %s
                WHERE p.post_id = %d AND p.status = %d
                  AND p.context_hash = UNHEX(%s)
                  AND p.meta_key IN ({$keySet})";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            $sql,
            $langCode,
            $postId,
            TranslationStatus::SOURCE_DISCOVERED,
            bin2hex(hash('sha256', '', true)),
            ...$metaKeys
        ), ARRAY_A);

        $out = [];
        foreach ((array) $rows as $row) {
            $translated = $row['translated_text'] ?? null;
            $out[]      = [
                'meta_key'        => (string) $row['meta_key'],
                'original_text'   => (string) $row['original_text'],
                'translated_text' => $translated === null ? null : (string) $translated,
            ];
        }

        return $out;
    }

    public function hasAnyTranslation(int $postId, string $langCode): bool
    {
        return $this->existsTranslatedChild('post', false, $postId, $langCode)
            || $this->existsTranslatedChild('meta', true, $postId, $langCode);
    }

    private function existsTranslatedChild(string $tableKey, bool $parentId, int $postId, string $langCode): bool
    {
        $parent = $this->parent($tableKey);
        $child  = $parent . '_translation';
        $join   = $parentId ? 't.parent_id = p.id' : 't.original_hash = p.original_hash';

        $sql = "SELECT EXISTS(
                    SELECT 1 FROM {$parent} p
                    JOIN {$child} t ON {$join} AND t.lang_code = %s AND t.translated_text IS NOT NULL
                    WHERE p.post_id = %d AND p.status = %d
                )";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        return (bool) $this->wpdb->get_var($this->wpdb->prepare(
            $sql,
            $langCode,
            $postId,
            TranslationStatus::SOURCE_DISCOVERED
        ));
    }

    public function saveTranslation(SourceRef $ref, string $langCode, string $text, int $status, ?string $provider): void
    {
        $tableKey = $ref->parentTableKey();

        if ($this->isHashLink($tableKey)) {
            $hashHex = $this->parentHashHex($ref);
            if ($hashHex === null) {
                return;
            }
            $this->saveTranslationByHash($tableKey, $hashHex, $langCode, $text, $status, $provider);
            $this->flushHtmlCache($ref, $langCode);
            return;
        }

        $child = $this->parent($tableKey) . '_translation';
        $now   = current_time('mysql');

        $update = 'ON DUPLICATE KEY UPDATE translated_text = VALUES(translated_text),
                       status = VALUES(status), provider = VALUES(provider), updated_at = VALUES(updated_at),
                       ai_pending_since = NULL, ai_error = NULL';

        $providerExpr = $provider === null ? 'NULL' : '%s';
        $args         = [$langCode, $text, $status];
        if ($provider !== null) {
            $args[] = $provider;
        }
        $args[] = $now;
        $args[] = $now;
        $args[] = $ref->id;

        $sql = "INSERT INTO {$child}
                    (parent_id, lang_code, translated_text, status, provider, created_at, updated_at)
                SELECT p.id, %s, %s, %d, {$providerExpr}, %s, %s
                FROM {$this->parent($tableKey)} p WHERE p.id = %d {$update}";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $this->wpdb->query($this->wpdb->prepare($sql, ...$args));
    }

    private function saveTranslationByHash(string $tableKey, string $hashHex, string $langCode, string $text, int $status, ?string $provider): void
    {
        $child = $this->parent($tableKey) . '_translation';
        $now   = current_time('mysql');

        $providerExpr = $provider === null ? 'NULL' : '%s';
        $args         = [$hashHex, $langCode, $text, $status];
        if ($provider !== null) {
            $args[] = $provider;
        }
        $args[] = $now;
        $args[] = $now;

        $sql = "INSERT INTO {$child}
                    (original_hash, lang_code, translated_text, status, provider, created_at, updated_at)
                VALUES (UNHEX(%s), %s, %s, %d, {$providerExpr}, %s, %s)
                ON DUPLICATE KEY UPDATE translated_text = VALUES(translated_text),
                    status = VALUES(status), provider = VALUES(provider), updated_at = VALUES(updated_at),
                    ai_pending_since = NULL, ai_error = NULL";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $this->wpdb->query($this->wpdb->prepare($sql, ...$args));
    }

    public function hasAnyPending(): bool
    {
        foreach (TranslationMemorySchema::HASH_LINK_CHILDREN as $tableKey) {
            $child = $this->parent($tableKey) . '_translation';
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            if ($this->wpdb->get_var("SELECT EXISTS(SELECT 1 FROM {$child} WHERE ai_pending_since IS NOT NULL)")) { // nosemgrep: wpdb-interpolated-sql -- table name only, from TranslationMemorySchema::parentTables(); no values interpolated
                return true;
            }
        }

        foreach (array_values(TranslationMemorySchema::PARENT_ID_CHILD_FKS) as $tableKey) {
            $child = $this->parent($tableKey) . '_translation';
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            if ($this->wpdb->get_var("SELECT EXISTS(SELECT 1 FROM {$child} WHERE ai_pending_since IS NOT NULL)")) { // nosemgrep: wpdb-interpolated-sql -- table name only, from TranslationMemorySchema::parentTables(); no values interpolated
                return true;
            }
        }

        return false;
    }

    public function tryAcquireAiPullLock(): bool
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        return (int) $this->wpdb->get_var("SELECT GET_LOCK('lingowp_ai_pull', 0)") === 1;
    }

    public function releaseAiPullLock(): void
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $this->wpdb->query("SELECT RELEASE_LOCK('lingowp_ai_pull')");
    }

    public function markPendingBatch(array $refs, array $langs): void
    {
        if ($refs === [] || $langs === []) {
            return;
        }

        $now = current_time('mysql');
        foreach ($this->refsByTable($refs) as $tableKey => $ids) {
            $child = $this->parent($tableKey) . '_translation';

            foreach (array_chunk(array_unique($ids), self::SELECT_CHUNK) as $chunk) {
                $placeholders = implode(', ', array_fill(0, count($chunk), '%d'));

                foreach ($langs as $lang) {
                    if ($this->isHashLink($tableKey)) {
                        $sql = "INSERT INTO {$child} (original_hash, lang_code, ai_pending_since, created_at, updated_at)
                                SELECT original_hash, %s, %s, %s, %s
                                FROM {$this->parent($tableKey)} WHERE id IN ({$placeholders})
                                ON DUPLICATE KEY UPDATE ai_pending_since = VALUES(ai_pending_since),
                                    ai_error = NULL, updated_at = VALUES(updated_at)";
                    } else {
                        $sql = "INSERT INTO {$child} (parent_id, lang_code, ai_pending_since, created_at, updated_at)
                                SELECT id, %s, %s, %s, %s
                                FROM {$this->parent($tableKey)} WHERE id IN ({$placeholders})
                                ON DUPLICATE KEY UPDATE ai_pending_since = VALUES(ai_pending_since),
                                    ai_error = NULL, updated_at = VALUES(updated_at)";
                    }

                    $args = array_merge([$lang, $now, $now, $now], $chunk);
                    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
                    $this->wpdb->query($this->wpdb->prepare($sql, ...$args));
                }
            }
        }
    }

    public function applyQueueResult(string $hashHex, string $langCode, ?string $translatedText, ?string $errorMessage): void
    {
        foreach (TranslationMemorySchema::HASH_LINK_CHILDREN as $tableKey) {
            $parent = $this->parent($tableKey);
            $row    = $this->wpdb->get_row( // phpcs:ignore WordPress.DB
                $this->wpdb->prepare("SELECT original_text FROM {$parent} WHERE original_hash = UNHEX(%s) LIMIT 1", $hashHex),
                ARRAY_A
            );
            if ($row === null) {
                continue;
            }
            $this->applyResultToPosition($tableKey, $hashHex, null, $langCode, (string) $row['original_text'], null, $translatedText, $errorMessage);
        }

        foreach (array_values(TranslationMemorySchema::PARENT_ID_CHILD_FKS) as $tableKey) {
            $parent = $this->parent($tableKey);
            $rows   = $this->wpdb->get_results( // phpcs:ignore WordPress.DB
                $this->wpdb->prepare("SELECT id, meta_key, original_text FROM {$parent} WHERE original_hash = UNHEX(%s)", $hashHex),
                ARRAY_A
            );
            foreach ((array) $rows as $row) {
                $this->applyResultToPosition(
                    $tableKey, null, (int) $row['id'], $langCode,
                    (string) $row['original_text'], (string) $row['meta_key'],
                    $translatedText, $errorMessage
                );
            }
        }
    }

    private function applyResultToPosition(
        string $tableKey,
        ?string $hashHex,
        ?int $parentId,
        string $langCode,
        string $originalText,
        ?string $metaKey,
        ?string $translatedText,
        ?string $errorMessage
    ): void {
        if ($errorMessage !== null || $translatedText === null || $translatedText === '') {
            $this->markQueueFailure($tableKey, $hashHex, $parentId, $langCode, $errorMessage ?? 'ERR_BACKEND_EMPTY_RESULT');
            return;
        }

        $guardErrors = TranslationSubmissionGuard::validate($originalText, $translatedText, $metaKey);
        if ($guardErrors !== []) {
            $this->markQueueFailure($tableKey, $hashHex, $parentId, $langCode, 'ERR_PROTECTED_TOKEN_STRUCTURE');
            return;
        }

        if ($this->isHashLink($tableKey)) {
            $this->saveTranslationByHash($tableKey, (string) $hashHex, $langCode, $translatedText, TranslationStatus::NEEDS_REVIEW, 'ai');
            $this->flushHtmlCacheForLang($tableKey, $langCode);
            return;
        }

        $this->saveTranslation(SourceRef::make('meta', (int) $parentId), $langCode, $translatedText, TranslationStatus::NEEDS_REVIEW, 'ai');
    }

    private function markQueueFailure(string $tableKey, ?string $hashHex, ?int $parentId, string $langCode, string $errorMessage): void
    {
        $child = $this->parent($tableKey) . '_translation';
        $now   = current_time('mysql');

        if ($this->isHashLink($tableKey)) {
            $sql = "INSERT INTO {$child} (original_hash, lang_code, ai_error, created_at, updated_at)
                    VALUES (UNHEX(%s), %s, %s, %s, %s)
                    ON DUPLICATE KEY UPDATE ai_error = VALUES(ai_error), ai_pending_since = NULL, updated_at = VALUES(updated_at)";
            $args = [$hashHex, $langCode, $errorMessage, $now, $now];
        } else {
            $sql = "INSERT INTO {$child} (parent_id, lang_code, ai_error, created_at, updated_at)
                    SELECT id, %s, %s, %s, %s FROM {$this->parent($tableKey)} WHERE id = %d
                    ON DUPLICATE KEY UPDATE ai_error = VALUES(ai_error), ai_pending_since = NULL, updated_at = VALUES(updated_at)";
            $args = [$langCode, $errorMessage, $now, $now, $parentId];
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $this->wpdb->query($this->wpdb->prepare($sql, ...$args));
    }

    private function flushHtmlCacheForLang(string $tableKey, string $langCode): void
    {
        if (($tableKey === 'html' || $tableKey === 'option') && $langCode !== '' && function_exists('wp_cache_delete')) {
            wp_cache_delete('lingowp:html:' . $langCode, 'lingowp');
        }
    }

    public function deleteTranslation(SourceRef $ref, string $langCode): void
    {
        $tableKey = $ref->parentTableKey();
        $child    = $this->parent($tableKey) . '_translation';

        if ($this->isHashLink($tableKey)) {
            $hashHex = $this->parentHashHex($ref);
            if ($hashHex === null) {
                return;
            }
            $this->wpdb->query( // phpcs:ignore WordPress.DB
                $this->wpdb->prepare(
                    "DELETE FROM {$child} WHERE original_hash = UNHEX(%s) AND lang_code = %s",
                    $hashHex,
                    $langCode
                )
            );
            $this->flushHtmlCache($ref, $langCode);
            return;
        }

        $this->wpdb->query( // phpcs:ignore WordPress.DB
            $this->wpdb->prepare("DELETE FROM {$child} WHERE parent_id = %d AND lang_code = %s", $ref->id, $langCode)
        );
    }

    public function setTranslationStatus(SourceRef $ref, string $langCode, int $status): void
    {
        $tableKey = $ref->parentTableKey();
        $child    = $this->parent($tableKey) . '_translation';
        $now      = current_time('mysql');

        if ($this->isHashLink($tableKey)) {
            $hashHex = $this->parentHashHex($ref);
            if ($hashHex === null) {
                return;
            }
            $this->wpdb->query( // phpcs:ignore WordPress.DB
                $this->wpdb->prepare(
                    "UPDATE {$child} SET status = %d, updated_at = %s WHERE original_hash = UNHEX(%s) AND lang_code = %s AND translated_text IS NOT NULL",
                    $status,
                    $now,
                    $hashHex,
                    $langCode
                )
            );
            $this->flushHtmlCache($ref, $langCode);
            return;
        }

        $this->wpdb->query( // phpcs:ignore WordPress.DB
            $this->wpdb->prepare(
                "UPDATE {$child} SET status = %d, updated_at = %s WHERE parent_id = %d AND lang_code = %s AND translated_text IS NOT NULL",
                $status,
                $now,
                $ref->id,
                $langCode
            )
        );
    }

    public function applyTranslationToRefs(array $refs, string $langCode, string $text, int $status, bool $overwrite): int
    {
        $applied = 0;
        foreach ($refs as $ref) {
            if (!$overwrite && $this->translationUpdatedAt($ref, $langCode) !== null) {
                continue;
            }
            $this->saveTranslation($ref, $langCode, $text, $status, null);
            ++$applied;
        }

        return $applied;
    }

    public function copyFallbackTranslations(string $fromLang, string $toLang): int
    {
        if ($fromLang === '' || $toLang === '' || $fromLang === $toLang) {
            return 0;
        }

        $now   = current_time('mysql');
        $count = 0;

        foreach (TranslationMemorySchema::HASH_LINK_CHILDREN as $tableKey) {
            $child = $this->parent($tableKey) . '_translation';
            $sql   = "INSERT IGNORE INTO {$child}
                        (original_hash, lang_code, translated_text, status, provider, created_at, updated_at)
                    SELECT original_hash, %s, translated_text, %d, provider, %s, %s
                    FROM {$child} WHERE lang_code = %s AND translated_text IS NOT NULL";

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            $result = $this->wpdb->query($this->wpdb->prepare(
                $sql,
                $toLang,
                TranslationStatus::NEEDS_REVIEW,
                $now,
                $now,
                $fromLang
            ));
            $count += max(0, (int) $result);
        }

        foreach (array_values(TranslationMemorySchema::PARENT_ID_CHILD_FKS) as $tableKey) {
            $child = $this->parent($tableKey) . '_translation';
            $sql   = "INSERT IGNORE INTO {$child}
                        (parent_id, lang_code, translated_text, status, provider, created_at, updated_at)
                    SELECT parent_id, %s, translated_text, %d, provider, %s, %s
                    FROM {$child} WHERE lang_code = %s AND translated_text IS NOT NULL";

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            $result = $this->wpdb->query($this->wpdb->prepare(
                $sql,
                $toLang,
                TranslationStatus::NEEDS_REVIEW,
                $now,
                $now,
                $fromLang
            ));
            $count += max(0, (int) $result);
        }

        return $count;
    }

    public function hasFallbackTranslationsToCopy(string $fromLang, string $toLang): bool
    {
        if ($fromLang === '' || $toLang === '' || $fromLang === $toLang) {
            return false;
        }

        foreach (TranslationMemorySchema::HASH_LINK_CHILDREN as $tableKey) {
            $child = $this->parent($tableKey) . '_translation';
            $sql   = "SELECT 1 FROM {$child} t1
                    LEFT JOIN {$child} t2 ON t2.original_hash = t1.original_hash AND t2.lang_code = %s
                        AND t2.translated_text IS NOT NULL
                    WHERE t1.lang_code = %s AND t1.translated_text IS NOT NULL AND t2.id IS NULL
                    LIMIT 1";

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            if ($this->wpdb->get_var($this->wpdb->prepare($sql, $toLang, $fromLang)) !== null) {
                return true;
            }
        }

        foreach (array_values(TranslationMemorySchema::PARENT_ID_CHILD_FKS) as $tableKey) {
            $child = $this->parent($tableKey) . '_translation';
            $sql   = "SELECT 1 FROM {$child} t1
                    LEFT JOIN {$child} t2 ON t2.parent_id = t1.parent_id AND t2.lang_code = %s
                        AND t2.translated_text IS NOT NULL
                    WHERE t1.lang_code = %s AND t1.translated_text IS NOT NULL AND t2.id IS NULL
                    LIMIT 1";

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            if ($this->wpdb->get_var($this->wpdb->prepare($sql, $toLang, $fromLang)) !== null) {
                return true;
            }
        }

        return false;
    }

    private function flushHtmlCache(SourceRef $ref, string $langCode): void
    {
        $this->flushHtmlCacheForLang($ref->kind, $langCode);
    }

    public function ignoreSource(SourceRef $ref): void
    {
        $tableKey = $ref->parentTableKey();
        $parent   = $this->parent($tableKey);
        $child    = $parent . '_translation';

        $hashHex = $this->isHashLink($tableKey) ? $this->parentHashHex($ref) : null;

        $this->wpdb->query( // phpcs:ignore WordPress.DB
            $this->wpdb->prepare(
                "UPDATE {$parent} SET status = %d, updated_at = %s WHERE id = %d",
                TranslationStatus::SOURCE_IGNORED,
                current_time('mysql'),
                $ref->id
            )
        );

        if ($this->isHashLink($tableKey)) {
            if ($hashHex !== null) {
                $this->wpdb->query( // phpcs:ignore WordPress.DB
                    $this->wpdb->prepare("DELETE FROM {$child} WHERE original_hash = UNHEX(%s)", $hashHex)
                );
            }
            return;
        }

        $this->wpdb->query( // phpcs:ignore WordPress.DB
            $this->wpdb->prepare("DELETE FROM {$child} WHERE parent_id = %d", $ref->id)
        );
    }

    public function restoreSources(array $refs): void
    {
        $now = current_time('mysql');
        foreach ($this->refsByTable($refs) as $tableKey => $ids) {
            $parent       = $this->parent($tableKey);
            $placeholders = implode(', ', array_fill(0, count($ids), '%d'));

            $sql = "UPDATE {$parent} SET status = %d, updated_at = %s WHERE status = %d AND id IN ({$placeholders})";

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            $this->wpdb->query($this->wpdb->prepare(
                $sql,
                TranslationStatus::SOURCE_DISCOVERED,
                $now,
                TranslationStatus::SOURCE_IGNORED,
                ...$ids
            ));
        }
    }

    public function translationUpdatedAt(SourceRef $ref, string $langCode): ?string
    {
        $tableKey = $ref->parentTableKey();
        $child    = $this->parent($tableKey) . '_translation';

        if ($this->isHashLink($tableKey)) {
            $hashHex = $this->parentHashHex($ref);
            if ($hashHex === null) {
                return null;
            }
            $value = $this->wpdb->get_var( // phpcs:ignore WordPress.DB
                $this->wpdb->prepare(
                    "SELECT updated_at FROM {$child} WHERE original_hash = UNHEX(%s) AND lang_code = %s",
                    $hashHex,
                    $langCode
                )
            );
        } else {
            $value = $this->wpdb->get_var( // phpcs:ignore WordPress.DB
                $this->wpdb->prepare(
                    "SELECT updated_at FROM {$child} WHERE parent_id = %d AND lang_code = %s",
                    $ref->id,
                    $langCode
                )
            );
        }

        return is_string($value) ? $value : null;
    }

    public function reconcilePost(int $postId, array $records): void
    {
        $byTable = $this->groupByTable($records);
        $this->reconcileScope('post', 'post_id = %d', [$postId], $byTable['post'] ?? []);
        $this->reconcileScope('meta', 'post_id = %d', [$postId], $byTable['meta'] ?? []);
    }

    public function reconcilePostMetaKey(int $postId, string $metaKey, array $records): void
    {
        $this->reconcileScope(
            'meta',
            'post_id = %d AND meta_key_hash = UNHEX(%s)',
            [$postId, bin2hex(hash('sha256', $metaKey, true))],
            $records
        );
    }

    public function reconcileTerm(int $termTaxonomyId, array $records): void
    {
        $this->reconcileScope('term', 'term_taxonomy_id = %d', [$termTaxonomyId], $records);
    }

    public function reconcileTermMeta(int $termId, array $records): void
    {
        $this->reconcileScope('term_meta', 'term_id = %d', [$termId], $records);
    }

    public function reconcileTermMetaKey(int $termId, string $metaKey, array $records): void
    {
        $this->reconcileScope(
            'term_meta',
            'term_id = %d AND meta_key_hash = UNHEX(%s)',
            [$termId, bin2hex(hash('sha256', $metaKey, true))],
            $records
        );
    }

    public function reconcileOption(int $optionId, array $records): void
    {
        $this->reconcileScope('option', 'option_id = %d', [$optionId], $records);
    }

    public function reconcileUser(int $userId, array $records): void
    {
        $this->reconcileScope('user', 'user_id = %d', [$userId], $records);
    }

    private function reconcileScope(string $tableKey, string $scopeSql, array $scopeArgs, array $records): void
    {
        $parent = $this->parent($tableKey);

        if ($records === []) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            $this->wpdb->query($this->wpdb->prepare(
                "DELETE FROM {$parent} WHERE {$scopeSql} AND status = %d",
                ...array_merge($scopeArgs, [TranslationStatus::SOURCE_DISCOVERED])
            ));
            return;
        }

        $identityCols = array_keys($records[0]->identity());
        $locationCols = array_values(array_filter($identityCols, static fn(string $c): bool => $c !== 'original_hash'));

        $preExisting = $this->existingIdentityKeys($tableKey, $scopeSql, $scopeArgs, $identityCols);

        $this->insertParents($tableKey, $records);

        $current = [];
        foreach ($records as $record) {
            $current[IdentityKey::of($record->identity())] = true;
        }

        $select = ['id'];
        foreach ($identityCols as $column) {
            $select[] = $this->isBinaryColumn($column) ? "HEX({$column}) AS {$column}" : $column;
        }
        $sql = 'SELECT ' . implode(', ', $select) . " FROM {$parent} WHERE {$scopeSql} AND status = %d";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = (array) $this->wpdb->get_results(
            $this->wpdb->prepare($sql, ...array_merge($scopeArgs, [TranslationStatus::SOURCE_DISCOVERED])),
            ARRAY_A
        );

        $currentByLocation = [];
        $staleByLocation   = [];
        $staleIds          = [];

        foreach ($rows as $row) {
            $row      = (array) $row;
            $identity = $this->decodeIdentity($identityCols, $row);
            $idKey    = IdentityKey::of($identity);
            $locKey   = IdentityKey::of($this->only($identity, $locationCols));
            $entry    = ['id' => (int) $row['id'], 'hash' => strtolower((string) $row['original_hash'])];

            if (isset($current[$idKey])) {
                $entry['isNew']               = !isset($preExisting[$idKey]);
                $currentByLocation[$locKey][] = $entry;
            } else {
                $staleByLocation[$locKey][] = $entry;
                $staleIds[]                 = $entry['id'];
            }
        }

        $hashLink = $this->isHashLink($tableKey);

        foreach ($staleByLocation as $locKey => $staleAtLocation) {
            $newAtLocation = array_values(array_filter(
                $currentByLocation[$locKey] ?? [],
                static fn(array $t): bool => $t['isNew']
            ));

            if (count($staleAtLocation) === 1 && count($newAtLocation) === 1) {
                $this->carryForward($tableKey, $hashLink, $staleAtLocation[0], $newAtLocation[0]);
            }
        }

        if ($staleIds !== []) {
            $placeholders = implode(', ', array_fill(0, count($staleIds), '%d'));
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            $this->wpdb->query($this->wpdb->prepare("DELETE FROM {$parent} WHERE id IN ({$placeholders})", ...$staleIds));
        }
    }

    private function existingIdentityKeys(string $tableKey, string $scopeSql, array $scopeArgs, array $identityCols): array
    {
        $parent = $this->parent($tableKey);

        $select = [];
        foreach ($identityCols as $column) {
            $select[] = $this->isBinaryColumn($column) ? "HEX({$column}) AS {$column}" : $column;
        }
        $sql = 'SELECT ' . implode(', ', $select) . " FROM {$parent} WHERE {$scopeSql} AND status = %d";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = (array) $this->wpdb->get_results(
            $this->wpdb->prepare($sql, ...array_merge($scopeArgs, [TranslationStatus::SOURCE_DISCOVERED])),
            ARRAY_A
        );

        $keys = [];
        foreach ($rows as $row) {
            $keys[IdentityKey::of($this->decodeIdentity($identityCols, (array) $row))] = true;
        }

        return $keys;
    }

    private function carryForward(string $tableKey, bool $hashLink, array $stale, array $target): void
    {
        $child = $this->parent($tableKey) . '_translation';
        $now   = current_time('mysql');

        if ($hashLink) {
            $sql = "INSERT IGNORE INTO {$child}
                        (original_hash, lang_code, translated_text, status, provider, created_at, updated_at)
                    SELECT UNHEX(%s), lang_code, translated_text, %d, provider, created_at, %s
                    FROM {$child} WHERE original_hash = UNHEX(%s) AND translated_text IS NOT NULL";

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            $this->wpdb->query($this->wpdb->prepare($sql, $target['hash'], TranslationStatus::NEEDS_REVIEW, $now, $stale['hash']));
            return;
        }

        $sql = "INSERT IGNORE INTO {$child}
                    (parent_id, lang_code, translated_text, status, provider, created_at, updated_at)
                SELECT %d, lang_code, translated_text, %d, provider, created_at, %s
                FROM {$child} WHERE parent_id = %d AND translated_text IS NOT NULL";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $this->wpdb->query($this->wpdb->prepare($sql, $target['id'], TranslationStatus::NEEDS_REVIEW, $now, $stale['id']));
    }

    private function isHashLink(string $tableKey): bool
    {
        return in_array($tableKey, TranslationMemorySchema::HASH_LINK_CHILDREN, true);
    }

    private function parentHashHex(SourceRef $ref): ?string
    {
        $value = $this->wpdb->get_var( // phpcs:ignore WordPress.DB
            $this->wpdb->prepare(
                'SELECT LOWER(HEX(original_hash)) FROM ' . $this->parent($ref->parentTableKey()) . ' WHERE id = %d',
                $ref->id
            )
        );

        return is_string($value) ? $value : null;
    }

    private function groupByTable(array $records): array
    {
        $groups = [];
        foreach ($records as $record) {
            $groups[$record->table()][] = $record;
        }

        return $groups;
    }

    private function refsByTable(array $refs): array
    {
        $groups = [];
        foreach ($refs as $ref) {
            $groups[$ref->parentTableKey()][] = $ref->id;
        }

        return $groups;
    }

    private function cell(string $column, $value, array &$args): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if ($this->isBinaryColumn($column)) {
            $args[] = bin2hex((string) $value);
            return 'UNHEX(%s)';
        }
        $args[] = $value;
        return '%s';
    }

    private function selectIdentity(array $columns): string
    {
        $parts = [];
        foreach ($columns as $column) {
            $parts[] = $this->isBinaryColumn($column) ? "HEX(p.{$column}) AS {$column}" : "p.{$column} AS {$column}";
        }

        return implode(', ', $parts);
    }

    private function rowIdentityKey(array $columns, array $row): string
    {
        return IdentityKey::of($this->decodeIdentity($columns, $row));
    }

    private function decodeIdentity(array $columns, array $row): array
    {
        $identity = [];
        foreach ($columns as $column) {
            $value             = (string) $row[$column];
            $identity[$column] = $this->isBinaryColumn($column) ? (hex2bin(strtolower($value)) ?: '') : $value;
        }

        return $identity;
    }

    private function only(array $assoc, array $keys): array
    {
        return array_intersect_key($assoc, array_flip($keys));
    }

    private function isBinaryColumn(string $column): bool
    {
        return str_ends_with($column, '_hash');
    }

    private function parent(string $tableKey): string
    {
        return TranslationMemorySchema::parentTables()[$tableKey];
    }
}
