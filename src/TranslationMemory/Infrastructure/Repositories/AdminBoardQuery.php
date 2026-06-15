<?php

namespace TransLangs\TranslationMemory\Infrastructure\Repositories;

use TransLangs\TranslationMemory\Infrastructure\TranslationMemorySchema;

/**
 * Read queries for the admin board (API spec §0, §2, §3, §6). Everything keys off
 * the effective-state CASE — `to_translate` / `review_flagged` / `review_changed`
 * / `done` — derived from the LEFT-JOINed translation for the selected language
 * (by lang_code) and the staleness comparison (translated_against_hash vs
 * current_hash). Lists use keyset pagination, never OFFSET.
 */
final class AdminBoardQuery
{
    /** Content area → source type(s) + optional post_type filter. */
    private const AREAS = [
        'page'    => ['types' => [1], 'post_type' => 'page'],
        'post'    => ['types' => [1], 'post_type' => 'post'],
        'product' => ['types' => [1], 'post_type' => 'product'],
        'media'   => ['types' => [1, 2], 'post_type' => 'attachment'],
        'term'    => ['types' => [3], 'post_type' => null],
        'i18n'    => ['types' => [4], 'post_type' => null],
        'html'    => ['types' => [5], 'post_type' => null],
    ];

    public function __construct(private \wpdb $wpdb) {}

    /** @return array{types: int[], post_type: ?string}|null */
    public static function area(string $area): ?array
    {
        return self::AREAS[$area] ?? null;
    }

    /**
     * @param array{types:int[], post_type:?string, lang:string, tab:string,
     *              q_original:?string, q_translated:?string, origin:string,
     *              cursor:?array{owner:int,id:int}, limit:int} $c
     * @return array{rows: array<int,array<string,mixed>>, next_cursor: ?array{owner:int,id:int}}
     */
    public function items(array $c): array
    {
        $source    = TranslationMemorySchema::tlSourceTable();
        $trans     = TranslationMemorySchema::tlTranslationTable();
        $ownerExpr = $this->ownerExpr($c['types']);
        $limit     = max(1, min(100, $c['limit']));

        $args  = [];
        $join  = '';
        if ($c['post_type'] !== null) {
            $join   = "INNER JOIN {$this->wpdb->posts} p ON p.ID = s.post_id AND p.post_type = %s";
            $args[] = $c['post_type'];
        }
        $args[] = $c['lang'];

        $where    = [];
        $where[]  = 's.type IN (' . implode(',', array_fill(0, count($c['types']), '%d')) . ')';
        foreach ($c['types'] as $type) {
            $args[] = $type;
        }
        $where[] = 's.status = 0';
        $where[] = $this->tabClause($c['tab']);

        if (($c['q_original'] ?? '') !== '') {
            $where[] = 's.original_text LIKE %s';
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

        if ($c['cursor'] !== null) {
            $where[] = "({$ownerExpr}, s.id) > (%d, %d)";
            $args[]  = $c['cursor']['owner'];
            $args[]  = $c['cursor']['id'];
        }

        $args[] = $limit + 1;

        // Owner-less types (4 i18n / 5 html) have ownerExpr '0'; a bare integer in
        // ORDER BY is read as a column position by MySQL (0 = error), so order by id.
        $orderBy = $ownerExpr === '0' ? 's.id' : "{$ownerExpr}, s.id";

        $sql = "SELECT s.id, LOWER(HEX(s.source_key)) AS source_key, s.type,
                       s.post_id, s.term_taxonomy_id, s.ref1, s.ref2, s.original_text,
                       LOWER(HEX(s.current_hash)) AS current_hash,
                       t.translated_text, t.status AS tr_status, t.provider,
                       LOWER(HEX(t.translated_against_hash)) AS against_hash,
                       t.updated_at AS tr_updated,
                       {$this->stateCase()} AS effective_state
                FROM {$source} s
                {$join}
                LEFT JOIN {$trans} t ON t.source_key = s.source_key AND t.lang_code = %s
                WHERE " . implode(' AND ', $where) . "
                ORDER BY {$orderBy}
                LIMIT %d";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = (array) $this->wpdb->get_results($this->wpdb->prepare($sql, ...$args), ARRAY_A);

        $next = null;
        if (count($rows) > $limit) {
            $rows = array_slice($rows, 0, $limit);
            $last = $rows[$limit - 1];
            $next = ['owner' => $this->ownerOf($last, $ownerExpr), 'id' => (int) $last['id']];
        }

        return ['rows' => $rows, 'next_cursor' => $next];
    }

    /**
     * @param int[] $types
     * @return array{to_translate:int, to_review:int, done:int, total:int}
     */
    public function counts(array $types, ?string $postType, string $lang): array
    {
        $source = TranslationMemorySchema::tlSourceTable();
        $trans  = TranslationMemorySchema::tlTranslationTable();

        $args = [];
        $join = '';
        if ($postType !== null) {
            $join   = "INNER JOIN {$this->wpdb->posts} p ON p.ID = s.post_id AND p.post_type = %s";
            $args[] = $postType;
        }
        $args[] = $lang;
        foreach ($types as $type) {
            $args[] = $type;
        }
        $typeIn = implode(',', array_fill(0, count($types), '%d'));

        $sql = "SELECT
                  SUM(t.source_key IS NULL) AS to_translate,
                  SUM(t.status = 4 OR (t.source_key IS NOT NULL
                      AND t.translated_against_hash <> s.current_hash)) AS to_review,
                  SUM(t.source_key IS NOT NULL AND t.status IN (1,2)
                      AND t.translated_against_hash = s.current_hash) AS done,
                  COUNT(*) AS total
                FROM {$source} s
                {$join}
                LEFT JOIN {$trans} t ON t.source_key = s.source_key AND t.lang_code = %s
                WHERE s.type IN ({$typeIn}) AND s.status = 0";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $row = $this->wpdb->get_row($this->wpdb->prepare($sql, ...$args), ARRAY_A) ?: [];

        return [
            'to_translate' => (int) ($row['to_translate'] ?? 0),
            'to_review'    => (int) ($row['to_review'] ?? 0),
            'done'         => (int) ($row['done'] ?? 0),
            'total'        => (int) ($row['total'] ?? 0),
        ];
    }

    /** @return array<string,mixed>|null */
    public function loadString(string $rawKey, string $lang): ?array
    {
        $source = TranslationMemorySchema::tlSourceTable();
        $trans  = TranslationMemorySchema::tlTranslationTable();

        $sql = "SELECT s.id, LOWER(HEX(s.source_key)) AS source_key, s.type,
                       s.post_id, s.term_taxonomy_id, s.ref1, s.ref2,
                       s.original_text, LOWER(HEX(s.current_hash)) AS current_hash,
                       s.source_url, s.status AS src_status,
                       t.translated_text, t.status AS tr_status, t.provider,
                       LOWER(HEX(t.translated_against_hash)) AS against_hash,
                       t.updated_at AS tr_updated,
                       {$this->stateCase()} AS effective_state
                FROM {$source} s
                LEFT JOIN {$trans} t ON t.source_key = s.source_key AND t.lang_code = %s
                WHERE s.source_key = UNHEX(%s)";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $row = $this->wpdb->get_row($this->wpdb->prepare($sql, $lang, bin2hex($rawKey)), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /** All languages of one source (PK prefix scan). @return array<int,array<string,mixed>> */
    public function siblings(string $rawKey): array
    {
        $trans = TranslationMemorySchema::tlTranslationTable();

        $sql = "SELECT lang_code, translated_text, status,
                       LOWER(HEX(translated_against_hash)) AS against_hash, updated_at
                FROM {$trans} WHERE source_key = UNHEX(%s)";

        // phpcs:ignore WordPress.DB
        return (array) $this->wpdb->get_results($this->wpdb->prepare($sql, bin2hex($rawKey)), ARRAY_A);
    }

    /** Other sources with the same text + type (apply-everywhere). @return array<int,array<string,mixed>> */
    public function duplicates(string $rawKey, int $type, string $rawCurrentHash, string $lang): array
    {
        $source = TranslationMemorySchema::tlSourceTable();
        $trans  = TranslationMemorySchema::tlTranslationTable();

        $sql = "SELECT LOWER(HEX(s.source_key)) AS source_key, s.type, s.post_id,
                       s.term_taxonomy_id, s.ref1, s.original_text,
                       t.translated_text AS existing_translation, t.status AS tr_status
                FROM {$source} s
                LEFT JOIN {$trans} t ON t.source_key = s.source_key AND t.lang_code = %s
                WHERE s.type = %d AND s.current_hash = UNHEX(%s) AND s.status = 0
                  AND s.source_key <> UNHEX(%s)";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        return (array) $this->wpdb->get_results(
            $this->wpdb->prepare($sql, $lang, $type, bin2hex($rawCurrentHash), bin2hex($rawKey)),
            ARRAY_A
        );
    }

    /**
     * @return array{rows: array<int,array<string,mixed>>, next_cursor: ?int}
     */
    public function ignored(?int $cursorId, int $limit): array
    {
        $source = TranslationMemorySchema::tlSourceTable();
        $limit  = max(1, min(100, $limit));

        $sql = "SELECT s.id, LOWER(HEX(s.source_key)) AS source_key, s.type, s.ref1,
                       s.original_text, s.post_id, s.term_taxonomy_id
                FROM {$source} s
                WHERE s.status = 3 AND (%d = 0 OR s.id > %d)
                ORDER BY s.id LIMIT %d";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = (array) $this->wpdb->get_results(
            $this->wpdb->prepare($sql, $cursorId === null ? 0 : 1, (int) $cursorId, $limit + 1),
            ARRAY_A
        );

        $next = null;
        if (count($rows) > $limit) {
            $rows = array_slice($rows, 0, $limit);
            $next = (int) $rows[$limit - 1]['id'];
        }

        return ['rows' => $rows, 'next_cursor' => $next];
    }

    private function stateCase(): string
    {
        return "CASE
                  WHEN t.source_key IS NULL                        THEN 'to_translate'
                  WHEN t.status = 4                                THEN 'review_flagged'
                  WHEN t.translated_against_hash <> s.current_hash THEN 'review_changed'
                  ELSE 'done'
                END";
    }

    private function tabClause(string $tab): string
    {
        return match ($tab) {
            'to_translate' => 't.source_key IS NULL',
            'to_review'    => '(t.status = 4 OR (t.source_key IS NOT NULL AND t.translated_against_hash <> s.current_hash))',
            'done'         => '(t.source_key IS NOT NULL AND t.status IN (1,2) AND t.translated_against_hash = s.current_hash)',
            default        => '1=1',
        };
    }

    private function originClause(string $origin): string
    {
        return match ($origin) {
            'machine' => 't.provider IS NOT NULL',
            'human'   => 't.provider IS NULL AND t.source_key IS NOT NULL',
            default   => '',
        };
    }

    /** @param int[] $types */
    private function ownerExpr(array $types): string
    {
        if (in_array(3, $types, true)) {
            return 's.term_taxonomy_id';
        }
        if (array_intersect([1, 2], $types) !== []) {
            return 's.post_id';
        }

        return '0';
    }

    /** @param array<string,mixed> $row */
    private function ownerOf(array $row, string $ownerExpr): int
    {
        return match ($ownerExpr) {
            's.post_id'          => (int) ($row['post_id'] ?? 0),
            's.term_taxonomy_id' => (int) ($row['term_taxonomy_id'] ?? 0),
            default              => 0,
        };
    }
}
