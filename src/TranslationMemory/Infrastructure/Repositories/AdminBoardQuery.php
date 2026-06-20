<?php

namespace TransLangs\TranslationMemory\Infrastructure\Repositories;

use TransLangs\Source\Domain\SourceRef;
use TransLangs\Source\Infrastructure\TranslationMemorySchema;

/**
 * Read queries for the admin board, v5 — table-driven, hybrid child joins.
 *
 * Areas map to parent tables by requirement: page/post/product/media → tl_post +
 * tl_meta (filtered by post_type); term → tl_term; staticText → tl_staticText;
 * html → tl_html. A board row is a parent LEFT-JOINed to its child for the
 * selected language, joined by the child's key model:
 *   hash-link (post/term/html): ON child.original_hash = parent.original_hash
 *   parent_id (meta/staticText): ON child.parent_id = parent.id
 *
 * State is child-status only (no translated_against_hash): no child =
 * to_translate, status -1 = review_flagged, status > 0 = done. Lists use keyset
 * pagination over (owner, sort_id), with the table ordinal folded into sort_id.
 */
final class AdminBoardQuery
{
    private const AREAS = [
        'page'       => ['post_type' => 'page',       'tables' => ['tl_post', 'tl_meta']],
        'post'       => ['post_type' => 'post',       'tables' => ['tl_post', 'tl_meta']],
        'product'    => ['post_type' => 'product',    'tables' => ['tl_post', 'tl_meta']],
        'media'      => ['post_type' => 'attachment', 'tables' => ['tl_post', 'tl_meta']],
        'term'       => ['post_type' => null,         'tables' => ['tl_term']],
        'staticText' => ['post_type' => null,         'tables' => ['tl_staticText']],
        'html'       => ['post_type' => null,         'tables' => ['tl_html']],
        'menus'      => ['post_type' => 'nav_menu_item', 'tables' => ['tl_post']],
        'branding'   => ['post_type' => null,         'tables' => ['tl_option']],
    ];

    /** Hash-link parent tables (child joins on original_hash, not parent_id). */
    private const HASH_LINK = ['tl_post', 'tl_term', 'tl_html', 'tl_option'];

    /** Keyset-sort ordinal per table (tiebreaker only, never an API field). */
    private const ORDINAL = ['tl_post' => 1, 'tl_meta' => 2, 'tl_term' => 3, 'tl_staticText' => 4, 'tl_html' => 5, 'tl_option' => 6];

    public function __construct(private \wpdb $wpdb) {}

    /** @return array{post_type:?string, tables:string[]}|null */
    public static function area(string $area): ?array
    {
        return self::AREAS[$area] ?? null;
    }

    /** @return string[] all area keys, in nav order. */
    public static function areaKeys(): array
    {
        return array_keys(self::AREAS);
    }

    /**
     * @param array{post_type:?string, tables:string[], lang:string, tab:string,
     *              q_original:?string, q_translated:?string, origin:string,
     *              cursor:?array{owner:int,id:int}, limit:int} $c
     * @return array{rows: array<int,array<string,mixed>>, next_cursor: ?array{owner:int,id:int}}
     */
    public function items(array $c): array
    {
        $limit = max(1, min(100, $c['limit']));

        $subSqls = [];
        $args    = [];
        foreach ($c['tables'] as $tableKey) {
            [$sql, $subArgs] = $this->subquery($tableKey, $c);
            $subSqls[]       = $sql;
            array_push($args, ...$subArgs);
        }

        $union = count($subSqls) === 1 ? $subSqls[0] : '(' . implode(') UNION ALL (', $subSqls) . ')';

        // "recent" sorts by last-changed (owner = translation timestamp, see
        // subquery) descending; default sorts by owner ascending. Both keyset
        // over the same (owner, sort_id) tuple, only the direction flips.
        $recent = ($c['sort'] ?? 'default') === 'recent';
        $cmp    = $recent ? '<' : '>';
        $dir    = $recent ? 'DESC' : 'ASC';

        $where = '';
        if ($c['cursor'] !== null) {
            $where  = "WHERE (owner, sort_id) {$cmp} (%d, %d)";
            $args[] = $c['cursor']['owner'];
            $args[] = $c['cursor']['id'];
        }
        $args[] = $limit + 1;

        $sql = "SELECT * FROM ({$union}) board {$where} ORDER BY owner {$dir}, sort_id {$dir} LIMIT %d";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = (array) $this->wpdb->get_results($this->wpdb->prepare($sql, ...$args), ARRAY_A);

        $next = null;
        if (count($rows) > $limit) {
            $rows = array_slice($rows, 0, $limit);
            $last = $rows[$limit - 1];
            $next = ['owner' => (int) $last['owner'], 'id' => (int) $last['sort_id']];
        }

        return ['rows' => $rows, 'next_cursor' => $next];
    }

    /**
     * One area-table SELECT, normalized to the shared board column shape.
     *
     * @param array<string,mixed> $c
     * @return array{0:string,1:array<int,mixed>}
     */
    private function subquery(string $tableKey, array $c): array
    {
        $parent = $this->parent($tableKey);
        $child  = $parent . '_translation';
        $kind   = SourceRef::kindForTable($tableKey);

        $args = [];
        $from = $this->boardFrom($tableKey, $c['post_type'], $args);
        $args[] = $c['lang'];

        $where = ['p.status = 0', $this->tabClause($c['tab'])];
        if (($c['q_original'] ?? '') !== '') {
            $where[] = 'p.original_text LIKE %s';
            $args[]  = '%' . $this->wpdb->esc_like($c['q_original']) . '%';
        }
        if (($c['q_translated'] ?? '') !== '') {
            $where[] = 't.translated_text LIKE %s';
            $args[]  = '%' . $this->wpdb->esc_like($c['q_translated']) . '%';
        }
        $origin = $this->originClause($c['origin'] ?? 'all');
        if ($origin !== '') {
            $where[] = $origin;
        }

        $ownerExpr = ($c['sort'] ?? 'default') === 'recent'
            ? 'COALESCE(UNIX_TIMESTAMP(t.updated_at), 0)'
            : $this->ownerCol($tableKey);

        $sql = "SELECT p.id,
                       {$this->sortId($tableKey)} AS sort_id,
                       {$ownerExpr} AS owner,
                       CONCAT('{$kind}', ':', p.id) AS source_key,
                       '{$kind}' AS kind,
                       {$this->refColumns($tableKey)},
                       p.original_text,
                       {$this->pluralCol($tableKey)} AS plural_original,
                       {$this->sourceUrlCol($tableKey)} AS source_url,
                       LOWER(HEX(p.original_hash)) AS current_hash,
                       t.translated_text, t.status AS tr_status, t.provider,
                       t.updated_at AS tr_updated,
                       {$this->stateCase()} AS effective_state
                {$from}
                {$this->pluralJoin($tableKey)}
                LEFT JOIN {$child} t ON {$this->childOn($tableKey)} AND t.lang_code = %s
                WHERE " . implode(' AND ', $where);

        return [$sql, $args];
    }

    /**
     * @param array{post_type:?string, tables:string[]} $area
     * @return array{to_translate:int, to_review:int, done:int, total:int}
     */
    public function counts(array $area, string $lang): array
    {
        $totals = ['to_translate' => 0, 'to_review' => 0, 'done' => 0, 'total' => 0];

        foreach ($area['tables'] as $tableKey) {
            $parent = $this->parent($tableKey);
            $child  = $parent . '_translation';

            $args = [];
            $from = $this->boardFrom($tableKey, $area['post_type'], $args);
            $args[] = $lang;

            $sql = "SELECT
                      SUM(t.id IS NULL) AS to_translate,
                      SUM(t.status = -1) AS to_review,
                      SUM(t.status > 0) AS done,
                      COUNT(*) AS total
                    {$from}
                    LEFT JOIN {$child} t ON {$this->childOn($tableKey)} AND t.lang_code = %s
                    WHERE p.status = 0";

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            $row = $this->wpdb->get_row($this->wpdb->prepare($sql, ...$args), ARRAY_A) ?: [];

            $totals['to_translate'] += (int) ($row['to_translate'] ?? 0);
            $totals['to_review']    += (int) ($row['to_review'] ?? 0);
            $totals['done']         += (int) ($row['done'] ?? 0);
            $totals['total']        += (int) ($row['total'] ?? 0);
        }

        return $totals;
    }

    /** @return array<string,mixed>|null */
    public function loadString(SourceRef $ref, string $lang): ?array
    {
        $tableKey = $ref->parentTableKey();
        $parent   = $this->parent($tableKey);
        $child    = $parent . '_translation';
        $kind     = $ref->kind;

        $sql = "SELECT p.id,
                       CONCAT('{$kind}', ':', p.id) AS source_key,
                       '{$kind}' AS kind,
                       {$this->refColumns($tableKey)},
                       p.original_text,
                       {$this->pluralCol($tableKey)} AS plural_original,
                       {$this->sourceUrlCol($tableKey)} AS source_url,
                       LOWER(HEX(p.original_hash)) AS current_hash,
                       p.status AS src_status,
                       t.translated_text, t.status AS tr_status, t.provider,
                       t.updated_at AS tr_updated,
                       {$this->stateCase()} AS effective_state
                FROM {$parent} p
                {$this->pluralJoin($tableKey)}
                LEFT JOIN {$child} t ON {$this->childOn($tableKey)} AND t.lang_code = %s
                WHERE p.id = %d";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $row = $this->wpdb->get_row($this->wpdb->prepare($sql, $lang, $ref->id), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /** All languages of one source. @return array<int,array<string,mixed>> */
    public function siblings(SourceRef $ref): array
    {
        $tableKey = $ref->parentTableKey();
        $child    = $this->parent($tableKey) . '_translation';

        if ($this->isHashLink($tableKey)) {
            $parent = $this->parent($tableKey);
            $sql    = "SELECT lang_code, translated_text, status, updated_at
                       FROM {$child}
                       WHERE original_hash = (SELECT original_hash FROM {$parent} WHERE id = %d)";
        } else {
            // staticText children carry a form_index (plurals); list the base form
            // only, so each language appears once. Other parent_id tables have none.
            $formFilter = $tableKey === 'tl_staticText' ? ' AND form_index = 0' : '';
            $sql = "SELECT lang_code, translated_text, status, updated_at
                    FROM {$child} WHERE parent_id = %d{$formFilter}";
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        return (array) $this->wpdb->get_results($this->wpdb->prepare($sql, $ref->id), ARRAY_A);
    }

    /**
     * Other sources of the same kind with identical original text. Mostly relevant
     * for parent_id types (hash-link positions already share one translation).
     *
     * @return array<int,array<string,mixed>>
     */
    public function duplicates(SourceRef $ref, string $lang): array
    {
        $tableKey = $ref->parentTableKey();
        $parent   = $this->parent($tableKey);
        $child    = $parent . '_translation';
        $kind     = $ref->kind;

        $sql = "SELECT CONCAT('{$kind}', ':', p.id) AS source_key, '{$kind}' AS kind, p.id,
                       {$this->refColumns($tableKey)}, p.original_text,
                       t.translated_text AS existing_translation, t.status AS tr_status
                FROM {$parent} p
                LEFT JOIN {$child} t ON {$this->childOn($tableKey)} AND t.lang_code = %s
                WHERE p.status = 0 AND p.id <> %d
                  AND p.original_hash = (SELECT original_hash FROM {$parent} WHERE id = %d)";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        return (array) $this->wpdb->get_results(
            $this->wpdb->prepare($sql, $lang, $ref->id, $ref->id),
            ARRAY_A
        );
    }

    /**
     * Ignored (status -1) parents across all five tables, keyset-paginated.
     *
     * @return array{rows: array<int,array<string,mixed>>, next_cursor: ?int}
     */
    public function ignored(?int $cursorId, int $limit): array
    {
        $limit   = max(1, min(100, $limit));
        $subSqls = [];

        foreach (array_keys(self::ORDINAL) as $tableKey) {
            $parent    = $this->parent($tableKey);
            $kind      = SourceRef::kindForTable($tableKey);
            $subSqls[] = "SELECT p.id, {$this->sortId($tableKey)} AS sort_id,
                                 CONCAT('{$kind}', ':', p.id) AS source_key, '{$kind}' AS kind,
                                 {$this->refColumns($tableKey)}, p.original_text
                          FROM {$parent} p WHERE p.status = -1";
        }

        $union = '(' . implode(') UNION ALL (', $subSqls) . ')';
        $sql   = "SELECT * FROM ({$union}) ig WHERE (%d = 0 OR sort_id > %d) ORDER BY sort_id LIMIT %d";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = (array) $this->wpdb->get_results(
            $this->wpdb->prepare($sql, $cursorId === null ? 0 : 1, (int) $cursorId, $limit + 1),
            ARRAY_A
        );

        $next = null;
        if (count($rows) > $limit) {
            $rows = array_slice($rows, 0, $limit);
            $next = (int) $rows[$limit - 1]['sort_id'];
        }

        return ['rows' => $rows, 'next_cursor' => $next];
    }

    // ── SQL fragments ───────────────────────────────────────────────────────────

    /**
     * FROM (+ owner join) for one area table, pushing any positional args.
     *
     * Hash-link tables (post/term/html) collapse to ONE representative row per
     * original_hash (MIN id) — the translation is shared by text, so listing the
     * same string once per occurrence is redundant. The representative is picked
     * within the same scope shown (status 0, and post_type for tl_post), so an
     * occurrence is never dropped from an area that contains it. Parent_id tables
     * (meta/staticText) are context-owned and stay one row per parent.
     *
     * @param array<int,mixed> $args
     */
    private function boardFrom(string $tableKey, ?string $postType, array &$args): string
    {
        $parent = $this->parent($tableKey);

        if ($this->isHashLink($tableKey)) {
            if ($postType !== null && $this->hasPostOwner($tableKey)) {
                $args[] = $postType;
                $rep    = "SELECT MIN(p2.id) AS rep_id
                           FROM {$parent} p2
                           INNER JOIN {$this->wpdb->posts} wp2
                                   ON wp2.ID = p2.post_id AND wp2.post_type = %s
                           WHERE p2.status = 0
                           GROUP BY p2.original_hash";
            } else {
                $rep = "SELECT MIN(id) AS rep_id FROM {$parent} WHERE status = 0 GROUP BY original_hash";
            }

            return "FROM {$parent} p INNER JOIN ({$rep}) rep ON rep.rep_id = p.id";
        }

        $join = '';
        if ($postType !== null && $this->hasPostOwner($tableKey)) {
            $join   = "INNER JOIN {$this->wpdb->posts} wp ON wp.ID = p.post_id AND wp.post_type = %s";
            $args[] = $postType;
        }

        return "FROM {$parent} p {$join}";
    }

    /** Child join condition for this table's key model. */
    private function childOn(string $tableKey): string
    {
        if ($this->isHashLink($tableKey)) {
            return 't.original_hash = p.original_hash';
        }

        // staticText children carry form_index (gettext plural forms); the board
        // represents each message by its base form (0) so a plural lists once. Other
        // parent_id tables have no form_index column.
        return $tableKey === 'tl_staticText'
            ? 't.parent_id = p.id AND t.form_index = 0'
            : 't.parent_id = p.id';
    }

    /** Plural original (msgid_plural) column — staticText only, NULL elsewhere. */
    private function pluralCol(string $tableKey): string
    {
        return $tableKey === 'tl_staticText' ? 'pl.original_text' : 'NULL';
    }

    /** LEFT JOIN to the plural table for staticText; empty for other tables. */
    private function pluralJoin(string $tableKey): string
    {
        return $tableKey === 'tl_staticText'
            ? 'LEFT JOIN ' . TranslationMemorySchema::pluralTable() . ' pl ON pl.static_text_id = p.id'
            : '';
    }

    private function stateCase(): string
    {
        return "CASE
                  WHEN t.id IS NULL  THEN 'to_translate'
                  WHEN t.status = -1 THEN 'review_flagged'
                  ELSE 'done'
                END";
    }

    private function tabClause(string $tab): string
    {
        return match ($tab) {
            'to_translate' => 't.id IS NULL',
            'to_review'    => 't.status = -1',
            'done'         => 't.status > 0',
            default        => '1=1',
        };
    }

    private function originClause(string $origin): string
    {
        return match ($origin) {
            'machine' => 't.provider IS NOT NULL',
            'human'   => 't.provider IS NULL AND t.id IS NOT NULL',
            default   => '',
        };
    }

    /** ref1/ref2/post_id/term_taxonomy_id selection per table (uniform for UNION). */
    private function refColumns(string $tableKey): string
    {
        return match ($tableKey) {
            'tl_post'       => 'p.field AS ref1, NULL AS ref2, p.post_id, NULL AS term_taxonomy_id',
            'tl_meta'       => 'p.meta_key AS ref1, p.context AS ref2, p.post_id, NULL AS term_taxonomy_id',
            'tl_term'       => 'p.field AS ref1, NULL AS ref2, NULL AS post_id, p.term_taxonomy_id',
            'tl_staticText' => 'p.source AS ref1, p.reference AS ref2, NULL AS post_id, NULL AS term_taxonomy_id',
            'tl_html'       => 'p.selector AS ref1, NULL AS ref2, NULL AS post_id, NULL AS term_taxonomy_id',
            'tl_option'     => 'p.option_name AS ref1, p.option_path AS ref2, NULL AS post_id, NULL AS term_taxonomy_id',
            default         => 'NULL AS ref1, NULL AS ref2, NULL AS post_id, NULL AS term_taxonomy_id',
        };
    }

    private function sourceUrlCol(string $tableKey): string
    {
        return $tableKey === 'tl_html' ? 'p.source_url' : 'NULL';
    }

    private function ownerCol(string $tableKey): string
    {
        return match ($tableKey) {
            'tl_post', 'tl_meta' => 'p.post_id',
            'tl_term'            => 'p.term_taxonomy_id',
            default              => '0',
        };
    }

    private function hasPostOwner(string $tableKey): bool
    {
        return $tableKey === 'tl_post' || $tableKey === 'tl_meta';
    }

    private function isHashLink(string $tableKey): bool
    {
        return in_array($tableKey, self::HASH_LINK, true);
    }

    /** Monotonic, globally-unique sort key; table ordinal folded in for UNION order. */
    private function sortId(string $tableKey): string
    {
        return '(' . self::ORDINAL[$tableKey] . ' * 100000000000 + p.id)';
    }

    private function parent(string $tableKey): string
    {
        return TranslationMemorySchema::parentTables()[$tableKey];
    }
}
