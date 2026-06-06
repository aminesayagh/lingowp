<?php

namespace TransLangs\TranslationMemory\Infrastructure\Repositories;

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
 */
final class AdminTranslationsQuery
{
    public function __construct(private \wpdb $wpdb) {}

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
     * Filtered to a single table when filters['route_table'] is set.
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

        if (!empty($filters['route_table'])) {
            $target = (string) $filters['route_table'];
            return array_values(array_filter($all, fn(array $t): bool => $t[0] === $target));
        }

        return $all;
    }

    /**
     * Builds a single SELECT sub-query with a consistent 12-column output
     * suitable for UNION ALL composition.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function buildSubQuery(string $routeTable, string $table, array $filters): array
    {
        $ctx1Expr = match ($routeTable) {
            'post', 'terms' => 'field',
            'postmeta'      => 'meta_key',
            'i18n'          => 'source',
            default         => 'NULL',
        };
        $ctx2Expr = match ($routeTable) {
            'postmeta' => 'context',
            'i18n'     => 'reference',
            default    => 'NULL',
        };
        // parent_ref: the entity FK used by the admin "View" link.
        // post/postmeta → post_id (permalink); terms → term_taxonomy_id; i18n → NULL.
        $parentRefExpr = match ($routeTable) {
            'post', 'postmeta' => 'post_id',
            'terms'            => 'term_taxonomy_id',
            default            => 'NULL',
        };

        [$where, $whereParams] = $this->buildWhere($filters);

        $sql = "SELECT CONCAT('{$routeTable}:', id) AS row_ref,
                       '{$routeTable}' AS route_table,
                       target_lang,
                       HEX(original_hash) AS original_hash,
                       original_text, translated_text,
                       status, provider, created_at, updated_at,
                       {$ctx1Expr} AS ctx1, {$ctx2Expr} AS ctx2,
                       {$parentRefExpr} AS parent_ref
                FROM {$table}
                {$where}";

        return [$sql, $whereParams];
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
            $params[]  = '%' . $this->wpdb->esc_like((string) $filters['search_original']) . '%';
        }

        if (!empty($filters['search_translated'])) {
            $clauses[] = 'translated_text LIKE %s';
            $params[]  = '%' . $this->wpdb->esc_like((string) $filters['search_translated']) . '%';
        }

        $where = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';

        return [$where, $params];
    }
}
