<?php

namespace TransLangs\TranslationMemory\Infrastructure\Repositories;

use TransLangs\TranslationMemory\Domain\RowRef;
use TransLangs\TranslationMemory\Domain\SourceRoute;
use TransLangs\TranslationMemory\Domain\TranslationStatus;

/**
 * Paginated UNION ALL query across all four translation tables.
 *
 * Each returned row includes `row_ref` (e.g. "post:123") for action dispatch,
 * `route_table` for client-side filtering, and two table-specific context
 * columns (ctx1, ctx2) whose meaning varies by table:
 *
 *   post/terms  — ctx1 = field,    ctx2 = NULL
 *   postmeta    — ctx1 = meta_key, ctx2 = context
 *   i18n        — ctx1 = source,   ctx2 = reference
 *
 * post/postmeta sub-queries join wp_posts to expose `post_type`, which the
 * source-type tabs (see SourceTypeCatalog) use to bucket rows.
 */
final class AdminTranslationsQuery
{
    public function __construct(private \wpdb $wpdb) {}

    /**
     * Count active (non-ignored) rows per source-type tab for the current
     * language/search filters. Drives which tabs appear and their badges.
     *
     * @param array{target_lang?: string, search_original?: string, search_translated?: string} $filters
     * @return array<string, int> source-type slug => row count
     */
    public function sourceTypeCounts(array $filters): array
    {
        // Status is handled here (exclude ignored); ignore any caller-supplied status.
        $filters = array_diff_key($filters, ['status' => true, 'statuses' => true]);

        $subQueries = [];
        $params     = [];

        foreach ($this->activeTables([]) as [$routeTable, $table]) {
            [$sub, $subParams] = $this->buildCountSubQuery($routeTable, $table, $filters);
            $subQueries[] = $sub;
            array_push($params, ...$subParams);
        }

        $unionSql = implode(' UNION ALL ', $subQueries);
        $sql      = "SELECT source_type, COUNT(*) AS total FROM ({$unionSql}) AS tl_counts GROUP BY source_type";

        $rows = (array) $this->wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $params
                ? $this->wpdb->prepare($sql, ...$params) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                : $sql,
            ARRAY_A
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['source_type']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Fetch the same source string across every target language (the rows that
     * share the row's identity but differ by target_lang). Used by the
     * multi-language drawer. Returns rows ordered by language code.
     *
     * @return list<array{row_ref: string, target_lang: string, original_text: string, translated_text: string|null, status: int}>
     */
    public function languageSiblings(RowRef $rowRef): array
    {
        $table = $rowRef->tableName();

        // Identity columns (minus target_lang) that define "the same string".
        $identityColumns = match ($rowRef->table) {
            SourceRoute::Post     => ['post_id', 'field', 'original_hash'],
            SourceRoute::Postmeta => ['post_id', 'meta_key_hash', 'context_hash', 'original_hash'],
            SourceRoute::Terms    => ['term_taxonomy_id', 'field', 'original_hash'],
            SourceRoute::I18n     => ['source', 'reference_hash', 'original_hash'],
        };

        $match = implode(
            ' AND ',
            array_map(static fn(string $col): string => "s.{$col} = base.{$col}", $identityColumns)
        );

        $routeValue = $rowRef->table->value;
        $sql = "SELECT CONCAT('{$routeValue}:', s.id) AS row_ref,
                       s.target_lang, s.original_text, s.translated_text, s.status
                FROM {$table} s
                JOIN {$table} base ON base.id = %d
                WHERE {$match}
                ORDER BY s.target_lang ASC";

        $rows = (array) $this->wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $this->wpdb->prepare($sql, $rowRef->id), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            ARRAY_A
        );

        return array_map(static fn(array $row): array => [
            'row_ref'         => (string) $row['row_ref'],
            'target_lang'     => (string) $row['target_lang'],
            'original_text'   => (string) $row['original_text'],
            'translated_text' => $row['translated_text'] !== null ? (string) $row['translated_text'] : null,
            'status'          => (int) $row['status'],
        ], $rows);
    }

    /**
     * Row refs of still-untranslated rows that share an original string in the
     * given target language, across every table. Used to propagate one
     * translation to the same text elsewhere.
     *
     * @return list<string>
     */
    public function sameOriginalUntranslated(string $originalHashHex, string $targetLang): array
    {
        if ($originalHashHex === '' || $targetLang === '') {
            return [];
        }

        $subQueries = [];
        $params     = [];

        foreach ($this->activeTables([]) as [$routeTable, $table]) {
            $subQueries[] = "SELECT CONCAT('{$routeTable}:', id) AS row_ref
                             FROM {$table}
                             WHERE original_hash = UNHEX(%s)
                               AND target_lang = %s
                               AND (translated_text IS NULL OR translated_text = '')";
            $params[] = $originalHashHex;
            $params[] = $targetLang;
        }

        $sql = implode(' UNION ALL ', $subQueries);

        $rows = (array) $this->wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $this->wpdb->prepare($sql, ...$params), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            ARRAY_A
        );

        return array_map(static fn(array $row): string => (string) $row['row_ref'], $rows);
    }

    /**
     * @param array{
     *   target_lang?: string, status?: int|null, statuses?: int[],
     *   search_original?: string, search_translated?: string,
     *   route_table?: string
     * } $filters
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function query(array $filters, int $page, int $perPage): array
    {
        $subQueries = [];
        $params     = [];

        foreach ($this->activeTables($filters) as [$routeTable, $table]) {
            [$sub, $subParams] = $this->buildSubQuery($routeTable, $table, $filters);
            $subQueries[] = $sub;
            array_push($params, ...$subParams);
        }

        if ($subQueries === []) {
            return ['rows' => [], 'total' => 0];
        }

        $unionSql = implode(' UNION ALL ', $subQueries);
        $countSql = "SELECT COUNT(*) FROM ({$unionSql}) AS tl_union_all";

        $total = (int) $this->wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $params
                ? $this->wpdb->prepare($countSql, ...$params) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                : $countSql
        );

        $offset  = max(0, ($page - 1) * $perPage);
        $dataSql = $unionSql . ' ORDER BY updated_at DESC LIMIT %d OFFSET %d';

        $rows = (array) $this->wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $this->wpdb->prepare($dataSql, ...[...$params, $perPage, $offset]), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            ARRAY_A
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Returns [[routeTableValue, dbTableName], ...] for the active route tables.
     * Narrowed by filters['source_type'] (preferred) or filters['route_table'].
     *
     * @return list<array{string, string}>
     */
    private function activeTables(array $filters): array
    {
        $all = [
            ['post',     $this->wpdb->prefix . 'tl_translations_post'],
            ['postmeta', $this->wpdb->prefix . 'tl_translations_postmeta'],
            ['terms',    $this->wpdb->prefix . 'tl_translations_terms'],
            ['i18n',     $this->wpdb->prefix . 'tl_translations_i18n'],
        ];

        if (!empty($filters['source_type'])) {
            $tables = SourceTypeCatalog::routeTablesFor((string) $filters['source_type']);
            return array_values(array_filter($all, fn(array $t): bool => in_array($t[0], $tables, true)));
        }

        if (!empty($filters['route_table'])) {
            $target = (string) $filters['route_table'];
            return array_values(array_filter($all, fn(array $t): bool => $t[0] === $target));
        }

        return $all;
    }

    /**
     * Builds a single SELECT sub-query with a consistent 13-column output
     * suitable for UNION ALL composition.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function buildSubQuery(string $routeTable, string $table, array $filters): array
    {
        $ctx1Expr = match ($routeTable) {
            'post', 'terms' => 't.field',
            'postmeta'      => 't.meta_key',
            'i18n'          => 't.source',
            default         => 'NULL',
        };
        $ctx2Expr = match ($routeTable) {
            'postmeta' => 't.context',
            'i18n'     => 't.reference',
            default    => 'NULL',
        };
        // post_id is used by the admin "View" link. Non-post sources have no direct post URL.
        $postIdExpr = match ($routeTable) {
            'post', 'postmeta' => 't.post_id',
            default            => 'NULL',
        };

        $joinsPosts   = in_array($routeTable, ['post', 'postmeta'], true);
        $join         = $joinsPosts ? " LEFT JOIN {$this->wpdb->posts} p ON p.ID = t.post_id" : '';
        $postTypeExpr = $joinsPosts ? 'p.post_type' : 'NULL';

        // i18n rows have no post; their "View text" page is the URL where the
        // string was last seen, stored 1:1 in the i18n meta table.
        $joinsMeta     = $routeTable === 'i18n';
        $metaJoin      = $joinsMeta ? " LEFT JOIN {$this->wpdb->prefix}tl_translations_i18n_meta m ON m.i18n_id = t.id" : '';
        $sourceUrlExpr = $joinsMeta ? 'm.source_url' : 'NULL';

        [$where, $whereParams] = $this->buildWhere($filters);
        [$where, $whereParams] = $this->applyPostTypeConstraint($where, $whereParams, $routeTable, $filters);

        $sql = "SELECT CONCAT('{$routeTable}:', t.id) AS row_ref,
                       '{$routeTable}' AS route_table,
                       t.target_lang,
                       HEX(t.original_hash) AS original_hash,
                       t.original_text, t.translated_text,
                       t.status, t.provider, t.created_at, t.updated_at,
                       {$ctx1Expr} AS ctx1, {$ctx2Expr} AS ctx2,
                       {$postIdExpr} AS post_id,
                       {$postTypeExpr} AS post_type,
                       {$sourceUrlExpr} AS source_url
                FROM {$table} t{$join}{$metaJoin}
                {$where}";

        return [$sql, $whereParams];
    }

    /**
     * Single-column sub-query emitting only the source-type slug, for counting.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function buildCountSubQuery(string $routeTable, string $table, array $filters): array
    {
        $joinsPosts = in_array($routeTable, ['post', 'postmeta'], true);
        $join       = $joinsPosts ? " LEFT JOIN {$this->wpdb->posts} p ON p.ID = t.post_id" : '';

        $sourceTypeExpr = match ($routeTable) {
            'i18n'  => "'" . SourceTypeCatalog::THEME_PLUGIN . "'",
            'terms' => "'" . SourceTypeCatalog::TAXONOMIES . "'",
            default => SourceTypeCatalog::postTypeCaseExpression('p.post_type'),
        };

        [$where, $whereParams] = $this->buildWhere($filters);

        // Tab visibility tracks the active view, so ignored rows never count.
        $where        = $where === '' ? 'WHERE t.status <> %d' : $where . ' AND t.status <> %d';
        $whereParams[] = TranslationStatus::IGNORED;

        $sql = "SELECT {$sourceTypeExpr} AS source_type FROM {$table} t{$join} {$where}";

        return [$sql, $whereParams];
    }

    /**
     * Append the active tab's post_type constraint to a post-backed sub-query.
     * No-op for terms/i18n tables or when no source_type filter is set.
     *
     * @param  list<mixed> $whereParams
     * @return array{0: string, 1: list<mixed>}
     */
    private function applyPostTypeConstraint(string $where, array $whereParams, string $routeTable, array $filters): array
    {
        if (!in_array($routeTable, ['post', 'postmeta'], true) || empty($filters['source_type'])) {
            return [$where, $whereParams];
        }

        [$fragment, $params] = SourceTypeCatalog::postTypeConstraint((string) $filters['source_type'], 'p.post_type');
        if ($fragment === null) {
            return [$where, $whereParams];
        }

        $where = $where === '' ? "WHERE {$fragment}" : "{$where} AND {$fragment}";
        array_push($whereParams, ...$params);

        return [$where, $whereParams];
    }

    /**
     * Builds a reusable WHERE clause from the filter set.
     * Filters source_lang, source_id, source_type are intentionally absent.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function buildWhere(array $filters): array
    {
        $clauses = [];
        $params  = [];

        if (!empty($filters['target_lang'])) {
            $clauses[] = 't.target_lang = %s';
            $params[]  = $filters['target_lang'];
        }

        if (!empty($filters['statuses']) && is_array($filters['statuses'])) {
            $statuses = array_values(array_unique(array_map('intval', $filters['statuses'])));
            if ($statuses !== []) {
                $clauses[] = 't.status IN (' . implode(',', array_fill(0, count($statuses), '%d')) . ')';
                array_push($params, ...$statuses);
            }
        } elseif (isset($filters['status']) && $filters['status'] !== null && $filters['status'] !== '') {
            $clauses[] = 't.status = %d';
            $params[]  = (int) $filters['status'];
        }

        if (!empty($filters['search_original'])) {
            $clauses[] = 't.original_text LIKE %s';
            $params[]  = '%' . $this->wpdb->esc_like((string) $filters['search_original']) . '%';
        }

        if (!empty($filters['search_translated'])) {
            $clauses[] = 't.translated_text LIKE %s';
            $params[]  = '%' . $this->wpdb->esc_like((string) $filters['search_translated']) . '%';
        }

        $where = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';

        return [$where, $params];
    }
}
