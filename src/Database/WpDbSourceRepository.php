<?php

namespace TransLangs\Database;

use TransLangs\Shared\Source\HtmlSource;
use TransLangs\Shared\Text\IdentityKey;
use TransLangs\Shared\Source\SourceRecord;
use TransLangs\Shared\Source\SourceRef;
use TransLangs\Database\SourceRepository;
use TransLangs\Shared\Text\TL_Key;
use TransLangs\Shared\Text\TranslatableText;
use TransLangs\Shared\Source\TranslationStatus;
use TransLangs\Database\TranslationMemorySchema;

/**
 * $wpdb implementation of the per-type persistence contract.
 *
 * Two child-key models live behind one class but never share a code path:
 *   hash-link (tl_post/tl_term/tl_html): child keyed (original_hash, lang_code).
 *   parent_id (tl_meta):                 child keyed (parent_id, lang_code).
 *
 * Binary identity columns (every *_hash) travel as hex via UNHEX(%s). Parent
 * rows are addressed for writes by SourceRef ({kind}:{id}); for hash-link types
 * the parent's original_hash is resolved from that id, since the translation is
 * keyed by text, not position.
 */
final class WpDbSourceRepository implements SourceRepository
{
    private const INSERT_CHUNK = 100;
    private const SELECT_CHUNK = 200;

    /** Parent table keys whose children are hash-linked. */
    private const HASH_LINK = ['tl_post', 'tl_term', 'tl_html', 'tl_option'];

    public function __construct(private \wpdb $wpdb, private WpDbBoardQueries $board) {}

    // ── discovery write ────────────────────────────────────────────────────────

    public function upsertSources(array $records): int
    {
        $inserted = 0;
        foreach ($this->groupByTable($records) as $tableKey => $group) {
            $inserted += $this->insertParents($tableKey, $group);
            if ($tableKey === 'tl_html') {
                $this->refreshHtmlSourceUrls($group);
            }
        }

        return $inserted;
    }

    /**
     * INSERT IGNORE parent rows: a new identity inserts, an existing one
     * is left untouched (same identity = same text).
     *
     * @param SourceRecord[] $records all belonging to $tableKey
     */
    private function insertParents(string $tableKey, array $records): int
    {
        // Single chokepoint for every discovery path: drop anything that carries
        // no translatable content before it ever reaches a parent table.
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

    /**
     * Repair source_url on existing HTML identities after INSERT IGNORE leaves
     * their other columns untouched. This preserves upsertSources()'s inserted
     * row count while allowing a rescan to remove previously stored domains.
     *
     * @param SourceRecord[] $records
     */
    private function refreshHtmlSourceUrls(array $records): void
    {
        $records = array_values(array_filter(
            $records,
            static fn (SourceRecord $record): bool => $record instanceof HtmlSource && $record->sourceUrl() !== null
        ));
        if ($records === []) {
            return;
        }

        $table = $this->parent('tl_html');
        foreach (array_chunk($records, self::INSERT_CHUNK) as $chunk) {
            $selects = [];
            $args    = [];
            foreach ($chunk as $record) {
                /** @var HtmlSource $record */
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

    // ── render reads ───────────────────────────────────────────────────────────

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
                  FROM {$child} WHERE lang_code = %s";

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

            $sql = "SELECT {$select}, tr.translated_text AS tl_text
                    FROM {$parent} p
                    INNER JOIN {$child} tr ON tr.parent_id = p.id AND tr.lang_code = %s
                    WHERE p.status = 0 AND {$tupleList}";

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, ...$args), ARRAY_A);
            foreach ((array) $rows as $row) {
                $out[$this->rowIdentityKey($columns, $row)] = (string) $row['tl_text'];
            }
        }

        return $out;
    }

    // ── board reads (admin dashboard) — delegated to WpDbBoardQueries ───────────
    // Same interface contract, same tables; split out because this class had
    // grown to mix write persistence with board-shaped read/presentation logic.

    /** @inheritDoc */
    public function listPostCollections(string $langCode, string $search = '', array $filters = []): array
    {
        return $this->board->listPostCollections($langCode, $search, $filters);
    }

    /** @inheritDoc */
    public function listPostTexts(int $postId, string $langCode, string $search = '', array $filters = []): array
    {
        return $this->board->listPostTexts($postId, $langCode, $search, $filters);
    }

    /** @inheritDoc */
    public function optionCollectionSummary(string $langCode, string $search = '', array $filters = []): array
    {
        return $this->board->optionCollectionSummary($langCode, $search, $filters);
    }

    /** @inheritDoc */
    public function listOptionTexts(string $langCode, string $search = '', array $filters = []): array
    {
        return $this->board->listOptionTexts($langCode, $search, $filters);
    }

    /** @inheritDoc */
    public function listTermCollections(string $langCode, string $search = '', array $filters = []): array
    {
        return $this->board->listTermCollections($langCode, $search, $filters);
    }

    /** @inheritDoc */
    public function listTermTexts(int $termTaxonomyId, string $langCode): array
    {
        return $this->board->listTermTexts($termTaxonomyId, $langCode);
    }

    /** @inheritDoc */
    public function listTaxonomyTexts(string $taxonomy, string $langCode, string $search = '', array $filters = []): array
    {
        return $this->board->listTaxonomyTexts($taxonomy, $langCode, $search, $filters);
    }

    /** @inheritDoc */
    public function postMetaCounts(string $langCode, string $search = '', array $filters = []): array
    {
        return $this->board->postMetaCounts($langCode, $search, $filters);
    }

    /** @inheritDoc */
    public function listMetaTexts(int $postId, string $langCode, string $search = '', array $filters = []): array
    {
        return $this->board->listMetaTexts($postId, $langCode, $search, $filters);
    }

    /** @inheritDoc */
    public function listMediaPostTexts(string $langCode, string $search = '', array $filters = []): array
    {
        return $this->board->listMediaPostTexts($langCode, $search, $filters);
    }

    /** @inheritDoc */
    public function listMediaAltTexts(string $langCode, string $search = '', array $filters = []): array
    {
        return $this->board->listMediaAltTexts($langCode, $search, $filters);
    }

    /** @inheritDoc */
    public function exportTextsForLanguages(array $langCodes): array
    {
        return $this->board->exportTextsForLanguages($langCodes);
    }

    /** @inheritDoc */
    public function findContentRefsByHash(string $hashHex): array
    {
        return $this->board->findContentRefsByHash($hashHex);
    }

    /** @inheritDoc */
    public function htmlCollectionSummary(string $langCode, string $search = '', array $filters = []): array
    {
        return $this->board->htmlCollectionSummary($langCode, $search, $filters);
    }

    /** @inheritDoc */
    public function listHtmlTexts(string $langCode, string $search = '', array $filters = []): array
    {
        return $this->board->listHtmlTexts($langCode, $search, $filters);
    }

    /** @inheritDoc */
    public function listStaleHtmlSources(string $langCode, string $search = '', array $filters = []): array
    {
        return $this->board->listStaleHtmlSources($langCode, $search, $filters);
    }

    /** @inheritDoc */
    public function languageProgress(string $langCode): array
    {
        return $this->board->languageProgress($langCode);
    }

    /**
     * Refresh last_seen_at for every hash a discovery pass resolved that has a
     * tl_html row (see HtmlDiscovery::discover()) — both freshly inserted rows
     * and ones already owned by tl_html. A hash belonging to another parent
     * table simply matches no row here, so this is safe to call with a walk's
     * full candidate set without first working out which table owns what.
     *
     * @param string[] $rawHashes
     */
    public function touchHtmlSeen(array $rawHashes): void
    {
        if ($rawHashes === []) {
            return;
        }

        $parent       = $this->parent('tl_html');
        $hexes        = array_map('bin2hex', array_values(array_unique($rawHashes)));
        $placeholders = implode(', ', array_fill(0, count($hexes), 'UNHEX(%s)'));

        $sql = "UPDATE {$parent} SET last_seen_at = %s WHERE original_hash IN ({$placeholders})";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $this->wpdb->query($this->wpdb->prepare($sql, current_time('mysql'), ...$hexes));
    }

    /**
     * Flag tl_html rows nobody has re-encountered (marking mode or the crawl)
     * in over $graceDays as stale, for admin review — never an automatic
     * delete, since a bounded crawl scope or a single fetch failure can make a
     * still-valid row look absent without it actually being gone.
     *
     * @return int rows flagged
     */
    public function flagStaleHtmlSources(int $graceDays): int
    {
        $parent = $this->parent('tl_html');

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

    /**
     * Hard-delete confirmed-stale tl_html rows. Only ever acts on rows already
     * flagged SOURCE_STALE, never anything else. No child-translation cleanup
     * needed: tl_html_translation has no FK to its parent (hash-link, shared by
     * many parent rows) — OrphanTranslationSweep already reaps translation rows
     * whose hash no longer exists in any parent table, on its own daily sweep.
     *
     * @param int[] $ids
     */
    public function deleteStaleHtmlSources(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        $parent       = $this->parent('tl_html');
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

    /**
     * Source text plus the identity the write guard needs to pick a validation
     * grammar. A SourceRef is only {kind}:{id}, so `meta:45` cannot say whether
     * it holds a Yoast (%%var%%) or Rank Math (%var%) template — but the two are
     * validated by different patterns, so the key has to come back with the text.
     *
     * Manual saves only. The AI batch already carries both fields on its in-memory
     * units and must NOT call this per item (50 rows per batch).
     *
     * @return array{text: string, kind: string, meta_key: ?string}|null
     */
    public function loadSourceValidationContext(SourceRef $ref): ?array
    {
        $parent   = $this->parent($ref->parentTableKey());
        $isMeta   = $ref->kind === 'meta';
        $selected = $isMeta ? 'original_text, meta_key' : 'original_text';

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
        ];
    }

    /**
     * Source template + its translation for a post's SEO meta keys, in one read.
     *
     * fetchParentTranslations() cannot serve the output layer: it returns only
     * translated text keyed by lookup identity, so it can say "here is a
     * translation" but never "a source exists and is untranslated" — and those two
     * states need opposite handling (an untranslated explicit title is authored
     * text that must be left alone, not generated output to substitute into).
     *
     * A LEFT JOIN keeps rows whose child is missing, which is exactly the
     * distinction. Child status is deliberately NOT filtered, mirroring every
     * other render read — SEO must not acquire different rendering rules.
     *
     * Scoped to context_hash = hash('') because SEO fields are scalar; array/JSON
     * leaves of the same key are not templates.
     *
     * @param  string[] $metaKeys
     * @return array<int, array{meta_key: string, original_text: string, translated_text: ?string}>
     */
    public function fetchPostMetaTranslationStates(int $postId, array $metaKeys, string $langCode): array
    {
        $metaKeys = array_values(array_unique(array_filter($metaKeys, static fn ($k): bool => is_string($k) && $k !== '')));
        if ($postId <= 0 || $metaKeys === [] || $langCode === '') {
            return [];
        }

        $parent = $this->parent('tl_meta');
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



    /** @inheritDoc */
    public function hasAnyTranslation(int $postId, string $langCode): bool
    {
        return $this->existsTranslatedChild('tl_post', false, $postId, $langCode)
            || $this->existsTranslatedChild('tl_meta', true, $postId, $langCode);
    }

    /** Cheap EXISTS check: does $postId have at least one translated child row in $langCode for this table? */
    private function existsTranslatedChild(string $tableKey, bool $parentId, int $postId, string $langCode): bool
    {
        $parent = $this->parent($tableKey);
        $child  = $parent . '_translation';
        $join   = $parentId ? 't.parent_id = p.id' : 't.original_hash = p.original_hash';

        $sql = "SELECT EXISTS(
                    SELECT 1 FROM {$parent} p
                    JOIN {$child} t ON {$join} AND t.lang_code = %s
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


    // ── translation writes ─────────────────────────────────────────────────────

    public function saveTranslation(SourceRef $ref, string $langCode, string $text, int $status, ?string $provider): void
    {
        $tableKey = $ref->parentTableKey();
        $child    = $this->parent($tableKey) . '_translation';
        $now      = current_time('mysql');

        $update = 'ON DUPLICATE KEY UPDATE translated_text = VALUES(translated_text),
                       status = VALUES(status), provider = VALUES(provider), updated_at = VALUES(updated_at)';

        if ($this->isHashLink($tableKey)) {
            $hashHex = $this->parentHashHex($ref);
            if ($hashHex === null) {
                return;
            }
            $providerExpr = $provider === null ? 'NULL' : '%s';
            $args         = [$hashHex, $langCode, $text, $status];
            if ($provider !== null) {
                $args[] = $provider;
            }
            $args[] = $now;
            $args[] = $now;

            $sql = "INSERT INTO {$child}
                        (original_hash, lang_code, translated_text, status, provider, created_at, updated_at)
                    VALUES (UNHEX(%s), %s, %s, %d, {$providerExpr}, %s, %s) {$update}";

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            $this->wpdb->query($this->wpdb->prepare($sql, ...$args));
            $this->flushHtmlCache($ref, $langCode);
            return;
        }

        // parent_id: INSERT … SELECT guards against a missing parent.
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
                    "UPDATE {$child} SET status = %d, updated_at = %s WHERE original_hash = UNHEX(%s) AND lang_code = %s",
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
                "UPDATE {$child} SET status = %d, updated_at = %s WHERE parent_id = %d AND lang_code = %s",
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

    /**
     * Drop the per-language map PublicRenderPipeline caches under 'tl:html:{lang}'
     * (now covering both tl_html and tl_option), so an edited template or option
     * translation shows on the next render instead of waiting out the 300s TTL.
     * No-op for other kinds.
     */
    private function flushHtmlCache(SourceRef $ref, string $langCode): void
    {
        if (($ref->kind === 'html' || $ref->kind === 'option') && $langCode !== '' && function_exists('wp_cache_delete')) {
            wp_cache_delete('tl:html:' . $langCode, 'translangs');
        }
    }

    public function ignoreSource(SourceRef $ref): void
    {
        $tableKey = $ref->parentTableKey();
        $parent   = $this->parent($tableKey);
        $child    = $parent . '_translation';

        // Capture the hash before flipping status (status does not affect it).
        $hashHex = $this->isHashLink($tableKey) ? $this->parentHashHex($ref) : null;

        $this->wpdb->query( // phpcs:ignore WordPress.DB
            $this->wpdb->prepare(
                "UPDATE {$parent} SET status = %d, updated_at = %s WHERE id = %d",
                TranslationStatus::SOURCE_IGNORED,
                current_time('mysql'),
                $ref->id
            )
        );

        // Delete children so render's "no child ⇒ original" covers the ignore.
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

    // ── discovery reconciliation (delete-stale; carry-forward arrives in Phase 5) ─

    public function reconcilePost(int $postId, array $records): void
    {
        $byTable = $this->groupByTable($records);
        $this->reconcileScope('tl_post', 'post_id = %d', [$postId], $byTable['tl_post'] ?? []);
        $this->reconcileScope('tl_meta', 'post_id = %d', [$postId], $byTable['tl_meta'] ?? []);
    }

    public function reconcilePostMetaKey(int $postId, string $metaKey, array $records): void
    {
        $this->reconcileScope(
            'tl_meta',
            'post_id = %d AND meta_key_hash = UNHEX(%s)',
            [$postId, bin2hex(hash('sha256', $metaKey, true))],
            $records
        );
    }

    public function reconcileTerm(int $termTaxonomyId, array $records): void
    {
        $this->reconcileScope('tl_term', 'term_taxonomy_id = %d', [$termTaxonomyId], $records);
    }

    public function reconcileOption(int $optionId, array $records): void
    {
        $this->reconcileScope('tl_option', 'option_id = %d', [$optionId], $records);
    }

    /**
     * Upsert $records, then for each still-discovered parent in scope that is
     * absent from $records (a position whose text changed): carry its translation
     * forward to its replacement as needs_review, then delete the stale parent
     * Empty $records deletes all of the scope.
     *
     * Carry-forward fires when a LOCATION has exactly one removed hash and
     * exactly one NEWLY INSERTED one — an unambiguous edit. Asking instead
     * whether the location holds a single position (the pre-fix rule) was too
     * coarse for split post_content, where every piece shares
     * (post_id, 'post_content'): editing one paragraph of three left three
     * candidates, so the guard failed and the translation was silently dropped.
     *
     * Requiring the target to be newly inserted also stops a deletion from
     * grafting its translation onto an unrelated survivor: with pieces A and B,
     * removing B used to leave A as the sole "target" and copy B's translation
     * onto it.
     *
     * Two or more pieces edited in one save stays deliberately unpaired — there
     * is nothing to say which old text became which new one, and a translation
     * attached to the wrong paragraph is worse than none.
     *
     * hash-link orphan children are reclaimed by the sweep, parent_id children
     * cascade.
     *
     * @param array<int, string|int> $scopeArgs
     * @param SourceRecord[]         $records
     */
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

        // Snapshot what is already here BEFORE inserting, so a row can be
        // recognised as this pass's insertion rather than a pre-existing sibling.
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

    /**
     * Identity keys already present in a scope. Captured before insertParents()
     * so reconcileScope() can tell a row it just inserted from one that was
     * already there — the difference between "this is the edit's replacement"
     * and "this is an untouched sibling".
     *
     * @param array<int, string|int> $scopeArgs
     * @param string[]               $identityCols
     * @return array<string, true>
     */
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

    /**
     * Seed the target position's translations from the stale position's as
     * needs_review, never clobbering a translation the target already has
     * (INSERT IGNORE). hash-link copies by original_hash; parent_id by parent id.
     *
     * @param array{id:int, hash:string} $stale
     * @param array{id:int, hash:string} $target
     */
    private function carryForward(string $tableKey, bool $hashLink, array $stale, array $target): void
    {
        $child = $this->parent($tableKey) . '_translation';
        $now   = current_time('mysql');

        if ($hashLink) {
            $sql = "INSERT IGNORE INTO {$child}
                        (original_hash, lang_code, translated_text, status, provider, created_at, updated_at)
                    SELECT UNHEX(%s), lang_code, translated_text, %d, provider, created_at, %s
                    FROM {$child} WHERE original_hash = UNHEX(%s)";

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            $this->wpdb->query($this->wpdb->prepare($sql, $target['hash'], TranslationStatus::NEEDS_REVIEW, $now, $stale['hash']));
            return;
        }

        $sql = "INSERT IGNORE INTO {$child}
                    (parent_id, lang_code, translated_text, status, provider, created_at, updated_at)
                SELECT %d, lang_code, translated_text, %d, provider, created_at, %s
                FROM {$child} WHERE parent_id = %d";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $this->wpdb->query($this->wpdb->prepare($sql, $target['id'], TranslationStatus::NEEDS_REVIEW, $now, $stale['id']));
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private function isHashLink(string $tableKey): bool
    {
        return in_array($tableKey, self::HASH_LINK, true);
    }

    /** Lowercase hex of a parent's original_hash, or null if the parent is gone. */
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

    /**
     * @param SourceRecord[] $records
     * @return array<string, SourceRecord[]> tableKey => records
     */
    private function groupByTable(array $records): array
    {
        $groups = [];
        foreach ($records as $record) {
            $groups[$record->table()][] = $record;
        }

        return $groups;
    }

    /**
     * @param SourceRef[] $refs
     * @return array<string, int[]> tableKey => parent ids
     */
    private function refsByTable(array $refs): array
    {
        $groups = [];
        foreach ($refs as $ref) {
            $groups[$ref->parentTableKey()][] = $ref->id;
        }

        return $groups;
    }

    /** Append one cell's placeholder + arg; binary (*_hash) uses UNHEX, NULL is literal. */
    private function cell(string $column, mixed $value, array &$args): string
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

    /** SELECT clause returning each identity column (HEX for binary), p-qualified. */
    private function selectIdentity(array $columns): string
    {
        $parts = [];
        foreach ($columns as $column) {
            $parts[] = $this->isBinaryColumn($column) ? "HEX(p.{$column}) AS {$column}" : "p.{$column} AS {$column}";
        }

        return implode(', ', $parts);
    }

    /** Rebuild IdentityKey::of() for a returned row, decoding HEX binary columns. */
    private function rowIdentityKey(array $columns, array $row): string
    {
        return IdentityKey::of($this->decodeIdentity($columns, $row));
    }

    /** Decode a returned parent row into its identity map (HEX → raw binary). */
    private function decodeIdentity(array $columns, array $row): array
    {
        $identity = [];
        foreach ($columns as $column) {
            $value             = (string) $row[$column];
            $identity[$column] = $this->isBinaryColumn($column) ? (hex2bin(strtolower($value)) ?: '') : $value;
        }

        return $identity;
    }

    /** @param string[] $keys */
    private function only(array $assoc, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $assoc[$key] ?? null;
        }

        return $out;
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
