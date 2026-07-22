<?php

namespace TransLangs\Core\Repository;

use TransLangs\Core\Source\Type\StaticTextSource;
use TransLangs\Core\Text\IdentityKey;
use TransLangs\Core\Source\Base\SourceRecord;
use TransLangs\Core\Source\Value\SourceRef;
use TransLangs\Core\Repository\SourceTranslationRepository;
use TransLangs\Core\Text\TL_Key;
use TransLangs\Core\Text\TranslatableText;
use TransLangs\Core\Source\Value\TranslationStatus;
use TransLangs\Database\TranslationMemorySchema;

/**
 * $wpdb implementation of the v5 per-type persistence contract.
 *
 * Two child-key models live behind one class but never share a code path:
 *   hash-link (tl_post/tl_term/tl_html): child keyed (original_hash, lang_code).
 *   parent_id (tl_meta/tl_staticText):   child keyed (parent_id, lang_code).
 *
 * Binary identity columns (every *_hash) travel as hex via UNHEX(%s). Parent
 * rows are addressed for writes by SourceRef ({kind}:{id}); for hash-link types
 * the parent's original_hash is resolved from that id, since the translation is
 * keyed by text, not position.
 */
final class WpDbSourceTranslationRepository implements SourceTranslationRepository
{
    private const INSERT_CHUNK = 100;
    private const SELECT_CHUNK = 200;

    /** Parent table keys whose children are hash-linked. */
    private const HASH_LINK = ['tl_post', 'tl_term', 'tl_html', 'tl_option'];

    public function __construct(private \wpdb $wpdb) {}

    // ── discovery write ────────────────────────────────────────────────────────

    public function upsertSources(array $records): int
    {
        $inserted = 0;
        foreach ($this->groupByTable($records) as $tableKey => $group) {
            $inserted += $this->insertParents($tableKey, $group);
        }

        return $inserted;
    }

    /**
     * Discover gettext plural messages: ensure each singular anchor exists in
     * tl_staticText (the existing TranslatableText gate applies), then INSERT IGNORE
     * the plural original into tl_staticPluralText, linked by static_text_id. The
     * translated forms are written later by savePluralForm() on the anchor.
     *
     * @param array<int, array{domain:string, context:?string, single:string, plural:string}> $candidates
     * @return int plural rows inserted
     */
    public function upsertPluralSources(array $candidates): int
    {
        if ($candidates === []) {
            return 0;
        }

        // 1. Anchors first — same path (and gate) as any singular gettext string.
        $singulars = [];
        foreach ($candidates as $c) {
            $single = (string) ($c['single'] ?? '');
            if ($single !== '') {
                $singulars[] = new StaticTextSource((string) ($c['domain'] ?? ''), $c['context'] ?? null, $single);
            }
        }
        if ($singulars !== []) {
            $this->upsertSources($singulars);
        }

        // 2. Link each plural original to its now-present anchor.
        $plural   = TranslationMemorySchema::pluralTable();
        $now      = current_time('mysql');
        $inserted = 0;

        foreach ($candidates as $c) {
            $single     = (string) ($c['single'] ?? '');
            $pluralText = (string) ($c['plural'] ?? '');
            if ($single === '' || !TranslatableText::isTranslatable($pluralText)) {
                continue;
            }

            $anchor   = new StaticTextSource((string) ($c['domain'] ?? ''), $c['context'] ?? null, $single);
            $anchorId = $this->staticTextIdByIdentity($anchor->identity());
            if ($anchorId === null) {
                continue; // singular was rejected by the gate
            }

            $result = $this->wpdb->query($this->wpdb->prepare( // phpcs:ignore WordPress.DB
                "INSERT IGNORE INTO {$plural}
                    (static_text_id, original_hash, original_text, status, created_at, updated_at)
                 VALUES (%d, UNHEX(%s), %s, %d, %s, %s)",
                $anchorId,
                bin2hex(TL_Key::currentHash($pluralText)),
                $pluralText,
                TranslationStatus::SOURCE_DISCOVERED,
                $now,
                $now
            ));
            if (is_int($result) && $result > 0) {
                $inserted += $result;
            }
        }

        return $inserted;
    }

    /**
     * INSERT IGNORE parent rows (v5 §3): a new identity inserts, an existing one
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

        // staticText children carry a form_index (gettext plurals). Singular
        // resolution must read the base form (0); without this a msgid used as both
        // __() and _n() could return an arbitrary plural form.
        $formFilter = $parentTableKey === 'tl_staticText' ? ' AND tr.form_index = 0' : '';

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
                    INNER JOIN {$child} tr ON tr.parent_id = p.id AND tr.lang_code = %s{$formFilter}
                    WHERE p.status = 0 AND {$tupleList}";

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, ...$args), ARRAY_A);
            foreach ((array) $rows as $row) {
                $out[$this->rowIdentityKey($columns, $row)] = (string) $row['tl_text'];
            }
        }

        return $out;
    }

    /**
     * Render read for gettext plurals: every translated form of each message in one
     * language, keyed IdentityKey(lookup) => [form_index => translated_text]. The
     * lookups are the singular staticText identities (the anchor); the caller picks
     * the form with PluralRules::indexFor($lang, $number).
     *
     * @param array<int, array<string,scalar>> $lookups singular staticText identities
     * @return array<string, array<int, string>>
     */
    public function fetchPluralForms(array $lookups, string $langCode): array
    {
        if ($lookups === [] || $langCode === '') {
            return [];
        }

        $parent  = $this->parent('tl_staticText');
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

            $sql = "SELECT {$select}, tr.form_index AS form_index, tr.translated_text AS tl_text
                    FROM {$parent} p
                    INNER JOIN {$child} tr ON tr.parent_id = p.id AND tr.lang_code = %s
                    WHERE p.status = 0 AND {$tupleList}";

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, ...$args), ARRAY_A);
            foreach ((array) $rows as $row) {
                $key                              = $this->rowIdentityKey($columns, $row);
                $out[$key][(int) $row['form_index']] = (string) $row['tl_text'];
            }
        }

        return $out;
    }

    // ── board reads (admin dashboard) ────────────────────────────────────────────

    public function listPostCollections(string $langCode): array
    {
        $parent = $this->parent('tl_post');
        $child  = $parent . '_translation';
        $posts  = $this->wpdb->posts;

        // hash-link child is unique per (original_hash, lang_code), so the LEFT JOIN
        // yields at most one translation per source position — no COUNT fan-out.
        $sql = "SELECT p.post_id AS post_id, wp.post_type AS post_type, wp.post_title AS post_title,
                       COUNT(*)              AS total,
                       SUM(t.status > 0)     AS translated,
                       SUM(t.status = %d)    AS needs_review,
                       SUM(t.id IS NULL)     AS missing
                FROM {$parent} p
                JOIN {$posts} wp ON wp.ID = p.post_id
                LEFT JOIN {$child} t ON t.original_hash = p.original_hash AND t.lang_code = %s
                WHERE p.status = %d
                GROUP BY p.post_id, wp.post_type, wp.post_title
                ORDER BY wp.post_type, wp.post_title";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            $sql,
            TranslationStatus::NEEDS_REVIEW,
            $langCode,
            TranslationStatus::SOURCE_DISCOVERED
        ), ARRAY_A);

        return array_map(static fn (array $r): array => [
            'post_id'      => (int) $r['post_id'],
            'post_type'    => (string) $r['post_type'],
            'post_title'   => (string) $r['post_title'],
            'total'        => (int) $r['total'],
            'translated'   => (int) $r['translated'],
            'needs_review' => (int) $r['needs_review'],
            'missing'      => (int) $r['missing'],
        ], (array) $rows);
    }

    public function listPostTexts(int $postId, string $langCode): array
    {
        $parent = $this->parent('tl_post');
        $child  = $parent . '_translation';

        $sql = "SELECT p.id AS id, p.field AS field, p.original_text AS original_text,
                       t.translated_text AS translated_text, t.status AS status, t.provider AS provider
                FROM {$parent} p
                LEFT JOIN {$child} t ON t.original_hash = p.original_hash AND t.lang_code = %s
                WHERE p.post_id = %d AND p.status = %d
                ORDER BY p.field, p.id";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            $sql,
            $langCode,
            $postId,
            TranslationStatus::SOURCE_DISCOVERED
        ), ARRAY_A);

        return array_map([$this, 'textUnitRow'], (array) $rows);
    }

    public function optionCollectionSummary(string $langCode): array
    {
        $parent = $this->parent('tl_option');
        $child  = $parent . '_translation';

        $sql = "SELECT COUNT(*)           AS total,
                       SUM(t.status > 0)  AS translated,
                       SUM(t.status = %d) AS needs_review,
                       SUM(t.id IS NULL)  AS missing
                FROM {$parent} p
                LEFT JOIN {$child} t ON t.original_hash = p.original_hash AND t.lang_code = %s
                WHERE p.status = %d";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            $sql,
            TranslationStatus::NEEDS_REVIEW,
            $langCode,
            TranslationStatus::SOURCE_DISCOVERED
        ), ARRAY_A) ?: [];

        return [
            'total'        => (int) ($row['total'] ?? 0),
            'translated'   => (int) ($row['translated'] ?? 0),
            'needs_review' => (int) ($row['needs_review'] ?? 0),
            'missing'      => (int) ($row['missing'] ?? 0),
        ];
    }

    public function listOptionTexts(string $langCode): array
    {
        $parent = $this->parent('tl_option');
        $child  = $parent . '_translation';

        $sql = "SELECT p.id AS id, p.option_name AS option_name, p.original_text AS original_text,
                       t.translated_text AS translated_text, t.status AS status, t.provider AS provider
                FROM {$parent} p
                LEFT JOIN {$child} t ON t.original_hash = p.original_hash AND t.lang_code = %s
                WHERE p.status = %d
                ORDER BY p.option_name, p.id";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            $sql,
            $langCode,
            TranslationStatus::SOURCE_DISCOVERED
        ), ARRAY_A);

        return array_map(function (array $r): array {
            $unit               = $this->textUnitRow($r);
            $unit['option_name'] = (string) $r['option_name'];
            return $unit;
        }, (array) $rows);
    }


    public function listTermCollections(string $langCode): array
    {
        $parent = $this->parent('tl_term');
        $child  = $parent . '_translation';
        $tt     = $this->wpdb->term_taxonomy;
        $terms  = $this->wpdb->terms;

        $sql = "SELECT p.term_taxonomy_id AS term_taxonomy_id, tx.taxonomy AS taxonomy, tm.name AS name,
                       COUNT(*)              AS total,
                       SUM(t.status > 0)     AS translated,
                       SUM(t.status = %d)    AS needs_review,
                       SUM(t.id IS NULL)     AS missing
                FROM {$parent} p
                JOIN {$tt} tx ON tx.term_taxonomy_id = p.term_taxonomy_id
                JOIN {$terms} tm ON tm.term_id = tx.term_id
                LEFT JOIN {$child} t ON t.original_hash = p.original_hash AND t.lang_code = %s
                WHERE p.status = %d
                GROUP BY p.term_taxonomy_id, tx.taxonomy, tm.name
                ORDER BY tx.taxonomy, tm.name";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            $sql,
            TranslationStatus::NEEDS_REVIEW,
            $langCode,
            TranslationStatus::SOURCE_DISCOVERED
        ), ARRAY_A);

        return array_map(static fn (array $r): array => [
            'term_taxonomy_id' => (int) $r['term_taxonomy_id'],
            'taxonomy'         => (string) $r['taxonomy'],
            'name'             => (string) $r['name'],
            'total'            => (int) $r['total'],
            'translated'       => (int) $r['translated'],
            'needs_review'     => (int) $r['needs_review'],
            'missing'          => (int) $r['missing'],
        ], (array) $rows);
    }

    public function listTermTexts(int $termTaxonomyId, string $langCode): array
    {
        $parent = $this->parent('tl_term');
        $child  = $parent . '_translation';

        $sql = "SELECT p.id AS id, p.field AS field, p.original_text AS original_text,
                       t.translated_text AS translated_text, t.status AS status, t.provider AS provider
                FROM {$parent} p
                LEFT JOIN {$child} t ON t.original_hash = p.original_hash AND t.lang_code = %s
                WHERE p.term_taxonomy_id = %d AND p.status = %d
                ORDER BY p.field, p.id";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            $sql,
            $langCode,
            $termTaxonomyId,
            TranslationStatus::SOURCE_DISCOVERED
        ), ARRAY_A);

        return array_map([$this, 'textUnitRow'], (array) $rows);
    }

    public function listTaxonomyTexts(string $taxonomy, string $langCode): array
    {
        $parent = $this->parent('tl_term');
        $child  = $parent . '_translation';
        $tt     = $this->wpdb->term_taxonomy;
        $terms  = $this->wpdb->terms;

        $sql = "SELECT p.id AS id, p.field AS field, p.original_text AS original_text,
                       t.translated_text AS translated_text, t.status AS status, t.provider AS provider
                FROM {$parent} p
                JOIN {$tt} tx ON tx.term_taxonomy_id = p.term_taxonomy_id
                JOIN {$terms} tm ON tm.term_id = tx.term_id
                LEFT JOIN {$child} t ON t.original_hash = p.original_hash AND t.lang_code = %s
                WHERE tx.taxonomy = %s AND p.status = %d
                ORDER BY tm.name, p.field, p.id";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            $sql,
            $langCode,
            $taxonomy,
            TranslationStatus::SOURCE_DISCOVERED
        ), ARRAY_A);

        return array_map([$this, 'textUnitRow'], (array) $rows);
    }

    public function postMetaCounts(string $langCode): array
    {
        $parent = $this->parent('tl_meta');
        $child  = $parent . '_translation';
        $posts  = $this->wpdb->posts;

        // meta is a parent_id child: join on parent_id, not original_hash.
        $sql = "SELECT p.post_id AS post_id, wp.post_type AS post_type, wp.post_title AS post_title,
                       COUNT(*)              AS total,
                       SUM(t.status > 0)     AS translated,
                       SUM(t.status = %d)    AS needs_review,
                       SUM(t.id IS NULL)     AS missing
                FROM {$parent} p
                JOIN {$posts} wp ON wp.ID = p.post_id
                LEFT JOIN {$child} t ON t.parent_id = p.id AND t.lang_code = %s
                WHERE p.status = %d
                GROUP BY p.post_id, wp.post_type, wp.post_title
                ORDER BY wp.post_type, wp.post_title";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            $sql,
            TranslationStatus::NEEDS_REVIEW,
            $langCode,
            TranslationStatus::SOURCE_DISCOVERED
        ), ARRAY_A);

        return array_map(static fn (array $r): array => [
            'post_id'      => (int) $r['post_id'],
            'post_type'    => (string) $r['post_type'],
            'post_title'   => (string) $r['post_title'],
            'total'        => (int) $r['total'],
            'translated'   => (int) $r['translated'],
            'needs_review' => (int) $r['needs_review'],
            'missing'      => (int) $r['missing'],
        ], (array) $rows);
    }

    public function listMetaTexts(int $postId, string $langCode): array
    {
        $parent = $this->parent('tl_meta');
        $child  = $parent . '_translation';

        $sql = "SELECT p.id AS id, p.meta_key AS meta_key, p.original_text AS original_text,
                       t.translated_text AS translated_text, t.status AS status, t.provider AS provider
                FROM {$parent} p
                LEFT JOIN {$child} t ON t.parent_id = p.id AND t.lang_code = %s
                WHERE p.post_id = %d AND p.status = %d
                ORDER BY p.meta_key, p.id";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            $sql,
            $langCode,
            $postId,
            TranslationStatus::SOURCE_DISCOVERED
        ), ARRAY_A);

        return array_map(function (array $r): array {
            $unit          = $this->textUnitRow($r);
            $unit['field'] = (string) $r['meta_key'];
            return $unit;
        }, (array) $rows);
    }


    public function htmlCollectionSummary(string $langCode): array
    {
        $parent = $this->parent('tl_html');
        $child  = $parent . '_translation';

        $sql = "SELECT COUNT(*)           AS total,
                       SUM(t.status > 0)  AS translated,
                       SUM(t.status = %d) AS needs_review,
                       SUM(t.id IS NULL)  AS missing
                FROM {$parent} p
                LEFT JOIN {$child} t ON t.original_hash = p.original_hash AND t.lang_code = %s
                WHERE p.status = %d";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            $sql,
            TranslationStatus::NEEDS_REVIEW,
            $langCode,
            TranslationStatus::SOURCE_DISCOVERED
        ), ARRAY_A) ?: [];

        return [
            'total'        => (int) ($row['total'] ?? 0),
            'translated'   => (int) ($row['translated'] ?? 0),
            'needs_review' => (int) ($row['needs_review'] ?? 0),
            'missing'      => (int) ($row['missing'] ?? 0),
        ];
    }

    public function listHtmlTexts(string $langCode): array
    {
        $parent = $this->parent('tl_html');
        $child  = $parent . '_translation';

        $sql = "SELECT p.id AS id, p.selector AS selector, p.original_text AS original_text,
                       t.translated_text AS translated_text, t.status AS status, t.provider AS provider
                FROM {$parent} p
                LEFT JOIN {$child} t ON t.original_hash = p.original_hash AND t.lang_code = %s
                WHERE p.status = %d
                ORDER BY p.selector, p.id";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            $sql,
            $langCode,
            TranslationStatus::SOURCE_DISCOVERED
        ), ARRAY_A);

        return array_map(function (array $r): array {
            $unit          = $this->textUnitRow($r);
            $unit['field'] = (string) $r['selector'];
            return $unit;
        }, (array) $rows);
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


    public function languageProgress(string $langCode): array
    {
        $total = 0;
        $done  = 0;

        // hash-link tables join on original_hash; meta joins on parent_id.
        foreach (['tl_post', 'tl_term', 'tl_html', 'tl_option'] as $key) {
            [$t, $d] = $this->countTable($key, false, $langCode);
            $total  += $t;
            $done   += $d;
        }
        [$t, $d] = $this->countTable('tl_meta', true, $langCode);
        $total  += $t;
        $done   += $d;

        return ['total' => $total, 'translated' => $done];
    }

    /** @return array{0:int, 1:int} [total sources, done translations] for one table + language. */
    private function countTable(string $tableKey, bool $parentId, string $langCode): array
    {
        $parent = $this->parent($tableKey);
        $child  = $parent . '_translation';
        $join   = $parentId ? 't.parent_id = p.id' : 't.original_hash = p.original_hash';

        $sql = "SELECT COUNT(*) AS total, SUM(t.status > 0) AS done
                FROM {$parent} p
                LEFT JOIN {$child} t ON {$join} AND t.lang_code = %s
                WHERE p.status = %d";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            $sql,
            $langCode,
            TranslationStatus::SOURCE_DISCOVERED
        ), ARRAY_A) ?: [];

        return [(int) ($row['total'] ?? 0), (int) ($row['done'] ?? 0)];
    }

    /** Normalize a board text-unit row: null translation when untranslated. */
    private function textUnitRow(array $r): array
    {
        $translated = $r['translated_text'];

        return [
            'id'              => (int) $r['id'],
            'field'           => (string) ($r['field'] ?? ''),
            'original_text'   => (string) $r['original_text'],
            'translated_text' => $translated === null ? null : (string) $translated,
            'status'          => $r['status'] === null ? null : (int) $r['status'],
            'provider'        => $r['provider'] === null ? null : (string) $r['provider'],
        ];
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

    /**
     * Create or update one gettext plural FORM for the message anchored by $ref
     * (a staticText ref), keyed (parent_id, lang_code, form_index). Singular saves
     * keep using saveTranslation() (form_index defaults to 0).
     */
    public function savePluralForm(SourceRef $ref, string $langCode, int $formIndex, string $text, int $status, ?string $provider): void
    {
        $parentTable = $this->parent($ref->parentTableKey());
        $child       = $parentTable . '_translation';
        $now         = current_time('mysql');

        $update = 'ON DUPLICATE KEY UPDATE translated_text = VALUES(translated_text),
                       status = VALUES(status), provider = VALUES(provider), updated_at = VALUES(updated_at)';

        $providerExpr = $provider === null ? 'NULL' : '%s';
        $args         = [$formIndex, $langCode, $text, $status];
        if ($provider !== null) {
            $args[] = $provider;
        }
        $args[] = $now;
        $args[] = $now;
        $args[] = $ref->id;

        $sql = "INSERT INTO {$child}
                    (parent_id, form_index, lang_code, translated_text, status, provider, created_at, updated_at)
                SELECT p.id, %d, %s, %s, %d, {$providerExpr}, %s, %s
                FROM {$parentTable} p WHERE p.id = %d {$update}";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $this->wpdb->query($this->wpdb->prepare($sql, ...$args));
    }

    /** Remove a single gettext plural FORM for $ref + language. */
    public function deletePluralForm(SourceRef $ref, string $langCode, int $formIndex): void
    {
        $child = $this->parent($ref->parentTableKey()) . '_translation';

        $this->wpdb->query( // phpcs:ignore WordPress.DB
            $this->wpdb->prepare(
                "DELETE FROM {$child} WHERE parent_id = %d AND lang_code = %s AND form_index = %d",
                $ref->id,
                $langCode,
                $formIndex
            )
        );
    }

    /**
     * The translated plural forms of $ref (a staticText message) for one language,
     * keyed form_index => translated_text. For the admin plural editor.
     *
     * @return array<int, string>
     */
    public function loadPluralForms(SourceRef $ref, string $langCode): array
    {
        $child = $this->parent($ref->parentTableKey()) . '_translation';

        $rows = $this->wpdb->get_results( // phpcs:ignore WordPress.DB
            $this->wpdb->prepare(
                "SELECT form_index, translated_text FROM {$child} WHERE parent_id = %d AND lang_code = %s",
                $ref->id,
                $langCode
            ),
            ARRAY_A
        );

        $out = [];
        foreach ((array) $rows as $row) {
            $out[(int) $row['form_index']] = (string) $row['translated_text'];
        }

        return $out;
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

    /**
     * Upsert $records, then for each still-discovered parent in scope that is
     * absent from $records (a position whose text changed): carry its translation
     * forward to the matching current position as needs_review, then delete the
     * stale parent (v5 §3). Carry-forward only fires when the location maps to
     * exactly one current position; hash-link orphan children are reclaimed by the
     * sweep, parent_id children cascade. Empty $records deletes all of the scope.
     *
     * @param array<int, string|int> $scopeArgs
     * @param SourceRecord[]         $records
     */
    private function reconcileScope(string $tableKey, string $scopeSql, array $scopeArgs, array $records): void
    {
        $parent = $this->parent($tableKey);
        $this->insertParents($tableKey, $records);

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
        $stale             = [];
        foreach ($rows as $row) {
            $row      = (array) $row;
            $identity = $this->decodeIdentity($identityCols, $row);
            $locKey   = IdentityKey::of($this->only($identity, $locationCols));
            $entry    = ['id' => (int) $row['id'], 'hash' => strtolower((string) $row['original_hash'])];

            if (isset($current[IdentityKey::of($identity)])) {
                $currentByLocation[$locKey][] = $entry;
            } else {
                $entry['loc'] = $locKey;
                $stale[]      = $entry;
            }
        }

        $hashLink = $this->isHashLink($tableKey);
        $staleIds = [];
        foreach ($stale as $s) {
            $targets = $currentByLocation[$s['loc']] ?? [];
            if (count($targets) === 1) {
                $this->carryForward($tableKey, $hashLink, $s, $targets[0]);
            }
            $staleIds[] = $s['id'];
        }

        if ($staleIds !== []) {
            $placeholders = implode(', ', array_fill(0, count($staleIds), '%d'));
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            $this->wpdb->query($this->wpdb->prepare("DELETE FROM {$parent} WHERE id IN ({$placeholders})", ...$staleIds));
        }
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

    /** Resolve a tl_staticText parent id from its identity columns, or null. */
    private function staticTextIdByIdentity(array $identity): ?int
    {
        $value = $this->wpdb->get_var( // phpcs:ignore WordPress.DB
            $this->wpdb->prepare(
                'SELECT id FROM ' . $this->parent('tl_staticText') . '
                 WHERE source = %s AND reference_hash = UNHEX(%s) AND original_hash = UNHEX(%s)',
                (string) $identity['source'],
                bin2hex((string) $identity['reference_hash']),
                bin2hex((string) $identity['original_hash'])
            )
        );

        return ($value !== null && (int) $value > 0) ? (int) $value : null;
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
