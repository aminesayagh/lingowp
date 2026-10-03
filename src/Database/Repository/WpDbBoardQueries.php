<?php

namespace LingoWP\Database\Repository;

use LingoWP\Shared\Source\SourceRef;
use LingoWP\Shared\Source\TranslationStatus;
use LingoWP\Shared\Source\ScannablePostStatuses;
use LingoWP\Database\TranslationMemorySchema;
use LingoWP\AiTranslation\AiQueueConfig;

/**
 * Read-only board/collection queries: the Admin dashboard's listing, grouping,
 * search, and export/import lookups. Split out of WpDbSourceRepository, which
 * keeps every write and every render-time read — this class only ever SELECTs,
 * shaped for the board's presentation needs (Problem 2 of the reorg plan).
 */
final class WpDbBoardQueries
{
    private \wpdb $wpdb;

    /**
     * ORDER BY fragment that puts a post/term's title row first, then body, then
     * excerpt, then every other field. Plain `ORDER BY p.field` sorts the literal
     * column name alphabetically — post_content, post_excerpt, post_title and
     * term description, name — which buries the title at the bottom of the group.
     * Compile-time constant, spliced into SQL, never bound — no request input.
     */
    private const FIELD_SORT = "CASE p.field
                WHEN 'post_title' THEN 1
                WHEN 'name' THEN 1
                WHEN 'post_content' THEN 2
                WHEN 'description' THEN 2
                WHEN 'post_excerpt' THEN 3
                ELSE 4 END";

    public function __construct(\wpdb $wpdb)
    {
        $this->wpdb = $wpdb;
    }

    public function listPostCollections(string $langCode, string $search = '', array $filters = []): array
    {
        $parent = $this->parent('post');
        $child  = $parent . '_translation';
        $posts  = $this->wpdb->posts;

        [$match, $matchArgs] = $this->textFilterPredicate($search, $filters, $this->postNameClauses());
        [$statusIn, $statusArgs] = $this->postStatusClause();

        // hash-link child is unique per (original_hash, lang_code), so the LEFT JOIN
        // yields at most one translation per source position — no COUNT fan-out.
        $sql = "SELECT p.post_id AS post_id, wp.post_type AS post_type, wp.post_title AS post_title,
                       wp.post_mime_type AS post_mime_type,
                       COUNT(*)              AS total,
                       SUM(t.translated_text IS NOT NULL AND t.status = -1)  AS needs_review,
                       SUM(t.translated_text IS NOT NULL AND t.status != -1) AS translated,
                       SUM(t.id IS NOT NULL AND t.translated_text IS NULL AND t.ai_pending_since IS NOT NULL) AS pending,
                       SUM(t.id IS NULL OR (t.translated_text IS NULL AND t.ai_pending_since IS NULL))        AS missing
                FROM {$parent} p
                JOIN {$posts} wp ON wp.ID = p.post_id
                LEFT JOIN {$child} t ON t.original_hash = p.original_hash AND t.lang_code = %s
                WHERE p.status = %d AND wp.post_status IN ({$statusIn}){$match}
                GROUP BY p.post_id, wp.post_type, wp.post_title, wp.post_mime_type
                ORDER BY wp.post_type, wp.post_title";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            $sql,
            $langCode,
            TranslationStatus::SOURCE_DISCOVERED,
            ...$statusArgs,
            ...$matchArgs
        ), ARRAY_A);

        return array_map([$this, 'collectionRow'], (array) $rows);
    }

    public function listPostTexts(int $postId, string $langCode, string $search = '', array $filters = []): array
    {
        $parent = $this->parent('post');
        $child  = $parent . '_translation';
        $posts  = $this->wpdb->posts;

        [$match, $matchArgs] = $this->textFilterPredicate($search, $filters, $this->postNameClauses());
        [$statusIn, $statusArgs] = $this->postStatusClause();

        // LEFT (not INNER): a leftover row for a since-deleted post must still show up via
        // ordinary content matching — wp.* is just NULL then, and NULL LIKE %s is falsy.
        // Same reasoning for the post-status guard below: "wp.ID IS NULL OR" keeps that
        // orphan row visible instead of silently dropping it (NULL IN (...) is falsy).
        $sql = "SELECT p.id AS id, p.field AS field, p.original_text AS original_text,
                       LOWER(HEX(p.original_hash)) AS original_hash,
                       t.translated_text AS translated_text, t.status AS status, t.provider AS provider,
                       t.ai_pending_since AS ai_pending_since, t.ai_error AS ai_error
                FROM {$parent} p
                LEFT JOIN {$posts} wp ON wp.ID = p.post_id
                LEFT JOIN {$child} t ON t.original_hash = p.original_hash AND t.lang_code = %s
                WHERE p.post_id = %d AND p.status = %d
                      AND (wp.ID IS NULL OR wp.post_status IN ({$statusIn})){$match}
                ORDER BY " . self::FIELD_SORT . ", p.field, p.id";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            $sql,
            $langCode,
            $postId,
            TranslationStatus::SOURCE_DISCOVERED,
            ...$statusArgs,
            ...$matchArgs
        ), ARRAY_A);

        return array_map([$this, 'textUnitRow'], (array) $rows);
    }

    /**
     * Every discovered post text unit for one language in a single query, each
     * carrying its post_id. listPostTexts() without the per-post WHERE -- the
     * AI "translate this whole language" scope needs them all, and asking
     * post-by-post is an N+1 over the whole site. No $search/$filters: nothing
     * bulk-translates a filtered view.
     *
     * @return array<int, array{id:int, post_id:int, field:string, original_text:string,
     *   translated_text:?string, status:?int, provider:?string}>
     */
    public function listAllPostTexts(string $langCode): array
    {
        $parent = $this->parent('post');
        $child  = $parent . '_translation';
        $posts  = $this->wpdb->posts;

        [$statusIn, $statusArgs] = $this->postStatusClause();

        // Same LEFT JOIN + "wp.ID IS NULL OR" guard as listPostTexts(): keeps an
        // orphan row (post since deleted) visible, keeps trashed/draft posts out.
        $sql = "SELECT p.id AS id, p.post_id AS post_id, p.field AS field, p.original_text AS original_text,
                       LOWER(HEX(p.original_hash)) AS original_hash,
                       t.translated_text AS translated_text, t.status AS status, t.provider AS provider,
                       t.ai_pending_since AS ai_pending_since, t.ai_error AS ai_error
                FROM {$parent} p
                LEFT JOIN {$posts} wp ON wp.ID = p.post_id
                LEFT JOIN {$child} t ON t.original_hash = p.original_hash AND t.lang_code = %s
                WHERE p.status = %d
                      AND (wp.ID IS NULL OR wp.post_status IN ({$statusIn}))
                ORDER BY p.post_id, " . self::FIELD_SORT . ", p.field, p.id";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            $sql,
            $langCode,
            TranslationStatus::SOURCE_DISCOVERED,
            ...$statusArgs
        ), ARRAY_A);

        return array_map(function (array $r): array {
            $unit            = $this->textUnitRow($r);
            $unit['post_id'] = (int) $r['post_id'];
            return $unit;
        }, (array) $rows);
    }

    public function optionCollectionSummary(string $langCode, string $search = '', array $filters = []): array
    {
        $parent = $this->parent('option');
        $child  = $parent . '_translation';

        [$match, $matchArgs] = $this->textFilterPredicate($search, $filters);

        $sql = "SELECT COUNT(*)           AS total,
                       SUM(t.translated_text IS NOT NULL AND t.status = -1)  AS needs_review,
                       SUM(t.translated_text IS NOT NULL AND t.status != -1) AS translated,
                       SUM(t.id IS NOT NULL AND t.translated_text IS NULL AND t.ai_pending_since IS NOT NULL) AS pending,
                       SUM(t.id IS NULL OR (t.translated_text IS NULL AND t.ai_pending_since IS NULL))        AS missing
                FROM {$parent} p
                LEFT JOIN {$child} t ON t.original_hash = p.original_hash AND t.lang_code = %s
                WHERE p.status = %d{$match}";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            $sql,
            $langCode,
            TranslationStatus::SOURCE_DISCOVERED,
            ...$matchArgs
        ), ARRAY_A) ?: [];

        return $this->summaryRow($row);
    }

    public function listOptionTexts(string $langCode, string $search = '', array $filters = []): array
    {
        $parent = $this->parent('option');
        $child  = $parent . '_translation';

        [$match, $matchArgs] = $this->textFilterPredicate($search, $filters);

        $sql = "SELECT p.id AS id, p.option_name AS option_name, p.original_text AS original_text,
                       LOWER(HEX(p.original_hash)) AS original_hash,
                       t.translated_text AS translated_text, t.status AS status, t.provider AS provider,
                       t.ai_pending_since AS ai_pending_since, t.ai_error AS ai_error
                FROM {$parent} p
                LEFT JOIN {$child} t ON t.original_hash = p.original_hash AND t.lang_code = %s
                WHERE p.status = %d{$match}
                ORDER BY p.option_name, p.id";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            $sql,
            $langCode,
            TranslationStatus::SOURCE_DISCOVERED,
            ...$matchArgs
        ), ARRAY_A);

        return array_map(function (array $r): array {
            $unit               = $this->textUnitRow($r);
            $unit['option_name'] = (string) $r['option_name'];
            return $unit;
        }, (array) $rows);
    }

    /** The single Author-bios collection (all discovered user sources) with per-status counts. */
    public function userCollectionSummary(string $langCode, string $search = '', array $filters = []): array
    {
        $parent = $this->parent('user');
        $child  = $parent . '_translation';

        [$match, $matchArgs] = $this->textFilterPredicate($search, $filters);

        $sql = "SELECT COUNT(*)           AS total,
                       SUM(t.translated_text IS NOT NULL AND t.status = -1)  AS needs_review,
                       SUM(t.translated_text IS NOT NULL AND t.status != -1) AS translated,
                       SUM(t.id IS NOT NULL AND t.translated_text IS NULL AND t.ai_pending_since IS NOT NULL) AS pending,
                       SUM(t.id IS NULL OR (t.translated_text IS NULL AND t.ai_pending_since IS NULL))        AS missing
                FROM {$parent} p
                LEFT JOIN {$child} t ON t.original_hash = p.original_hash AND t.lang_code = %s
                WHERE p.status = %d{$match}";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            $sql,
            $langCode,
            TranslationStatus::SOURCE_DISCOVERED,
            ...$matchArgs
        ), ARRAY_A) ?: [];

        return $this->summaryRow($row);
    }

    /** The discovered author-bio text units for one language. For expanding the Author-bios group. */
    public function listUserTexts(string $langCode, string $search = '', array $filters = []): array
    {
        $parent = $this->parent('user');
        $child  = $parent . '_translation';
        $users  = $this->wpdb->users;

        [$match, $matchArgs] = $this->textFilterPredicate($search, $filters);

        $sql = "SELECT p.id AS id, p.user_id AS user_id, u.display_name AS display_name,
                       p.original_text AS original_text,
                       LOWER(HEX(p.original_hash)) AS original_hash,
                       t.translated_text AS translated_text, t.status AS status, t.provider AS provider,
                       t.ai_pending_since AS ai_pending_since, t.ai_error AS ai_error
                FROM {$parent} p
                JOIN {$users} u ON u.ID = p.user_id
                LEFT JOIN {$child} t ON t.original_hash = p.original_hash AND t.lang_code = %s
                WHERE p.status = %d{$match}
                ORDER BY u.display_name, p.id";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            $sql,
            $langCode,
            TranslationStatus::SOURCE_DISCOVERED,
            ...$matchArgs
        ), ARRAY_A);

        return array_map(function (array $r): array {
            $unit                 = $this->textUnitRow($r);
            $unit['user_id']      = (int) $r['user_id'];
            $unit['display_name'] = (string) $r['display_name'];
            return $unit;
        }, (array) $rows);
    }


    public function listTermCollections(string $langCode, string $search = '', array $filters = []): array
    {
        $parent = $this->parent('term');
        $child  = $parent . '_translation';
        $tt     = $this->wpdb->term_taxonomy;
        $terms  = $this->wpdb->terms;

        [$match, $matchArgs] = $this->textFilterPredicate($search, $filters);

        $sql = "SELECT p.term_taxonomy_id AS term_taxonomy_id, tx.taxonomy AS taxonomy, tm.name AS name,
                       COUNT(*)              AS total,
                       SUM(t.translated_text IS NOT NULL AND t.status = -1)  AS needs_review,
                       SUM(t.translated_text IS NOT NULL AND t.status != -1) AS translated,
                       SUM(t.id IS NOT NULL AND t.translated_text IS NULL AND t.ai_pending_since IS NOT NULL) AS pending,
                       SUM(t.id IS NULL OR (t.translated_text IS NULL AND t.ai_pending_since IS NULL))        AS missing
                FROM {$parent} p
                JOIN {$tt} tx ON tx.term_taxonomy_id = p.term_taxonomy_id
                JOIN {$terms} tm ON tm.term_id = tx.term_id
                LEFT JOIN {$child} t ON t.original_hash = p.original_hash AND t.lang_code = %s
                WHERE p.status = %d{$match}
                GROUP BY p.term_taxonomy_id, tx.taxonomy, tm.name
                ORDER BY tx.taxonomy, tm.name";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            $sql,
            $langCode,
            TranslationStatus::SOURCE_DISCOVERED,
            ...$matchArgs
        ), ARRAY_A);

        return array_map(static fn (array $r): array => [
            'term_taxonomy_id' => (int) $r['term_taxonomy_id'],
            'taxonomy'         => (string) $r['taxonomy'],
            'name'             => (string) $r['name'],
            'total'            => (int) $r['total'],
            'translated'       => (int) $r['translated'],
            'needs_review'     => (int) $r['needs_review'],
            'pending'          => (int) $r['pending'],
            'missing'          => (int) $r['missing'],
        ], (array) $rows);
    }

    public function listTermTexts(int $termTaxonomyId, string $langCode): array
    {
        $parent = $this->parent('term');
        $child  = $parent . '_translation';

        $sql = "SELECT p.id AS id, p.field AS field, p.original_text AS original_text,
                       t.translated_text AS translated_text, t.status AS status, t.provider AS provider,
                       t.ai_pending_since AS ai_pending_since, t.ai_error AS ai_error
                FROM {$parent} p
                LEFT JOIN {$child} t ON t.original_hash = p.original_hash AND t.lang_code = %s
                WHERE p.term_taxonomy_id = %d AND p.status = %d
                ORDER BY " . self::FIELD_SORT . ", p.field, p.id";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            $sql,
            $langCode,
            $termTaxonomyId,
            TranslationStatus::SOURCE_DISCOVERED
        ), ARRAY_A);

        return array_map([$this, 'textUnitRow'], (array) $rows);
    }

    public function listTaxonomyTexts(string $taxonomy, string $langCode, string $search = '', array $filters = []): array
    {
        $parent = $this->parent('term');
        $child  = $parent . '_translation';
        $tt     = $this->wpdb->term_taxonomy;
        $terms  = $this->wpdb->terms;

        [$match, $matchArgs] = $this->textFilterPredicate($search, $filters);

        $sql = "SELECT p.id AS id, p.field AS field, tm.term_id AS term_id, p.original_text AS original_text,
                       LOWER(HEX(p.original_hash)) AS original_hash,
                       t.translated_text AS translated_text, t.status AS status, t.provider AS provider,
                       t.ai_pending_since AS ai_pending_since, t.ai_error AS ai_error
                FROM {$parent} p
                JOIN {$tt} tx ON tx.term_taxonomy_id = p.term_taxonomy_id
                JOIN {$terms} tm ON tm.term_id = tx.term_id
                LEFT JOIN {$child} t ON t.original_hash = p.original_hash AND t.lang_code = %s
                WHERE tx.taxonomy = %s AND p.status = %d{$match}
                ORDER BY tm.name, " . self::FIELD_SORT . ", p.field, p.id";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            $sql,
            $langCode,
            $taxonomy,
            TranslationStatus::SOURCE_DISCOVERED,
            ...$matchArgs
        ), ARRAY_A);

        return array_map(function (array $r): array {
            $unit            = $this->textUnitRow($r);
            $unit['term_id'] = (int) $r['term_id'];
            return $unit;
        }, (array) $rows);
    }

    public function postMetaCounts(string $langCode, string $search = '', array $filters = []): array
    {
        $parent = $this->parent('meta');
        $child  = $parent . '_translation';
        $posts  = $this->wpdb->posts;

        [$match, $matchArgs] = $this->textFilterPredicate($search, $filters, $this->postNameClauses());
        [$statusIn, $statusArgs] = $this->postStatusClause();

        // meta is a parent_id child: join on parent_id, not original_hash.
        $sql = "SELECT p.post_id AS post_id, wp.post_type AS post_type, wp.post_title AS post_title,
                       wp.post_mime_type AS post_mime_type,
                       COUNT(*)              AS total,
                       SUM(t.translated_text IS NOT NULL AND t.status = -1)  AS needs_review,
                       SUM(t.translated_text IS NOT NULL AND t.status != -1) AS translated,
                       SUM(t.id IS NOT NULL AND t.translated_text IS NULL AND t.ai_pending_since IS NOT NULL) AS pending,
                       SUM(t.id IS NULL OR (t.translated_text IS NULL AND t.ai_pending_since IS NULL))        AS missing
                FROM {$parent} p
                JOIN {$posts} wp ON wp.ID = p.post_id
                LEFT JOIN {$child} t ON t.parent_id = p.id AND t.lang_code = %s
                WHERE p.status = %d AND wp.post_status IN ({$statusIn}){$match}
                GROUP BY p.post_id, wp.post_type, wp.post_title, wp.post_mime_type
                ORDER BY wp.post_type, wp.post_title";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            $sql,
            $langCode,
            TranslationStatus::SOURCE_DISCOVERED,
            ...$statusArgs,
            ...$matchArgs
        ), ARRAY_A);

        return array_map([$this, 'collectionRow'], (array) $rows);
    }

    public function listMetaTexts(int $postId, string $langCode, string $search = '', array $filters = []): array
    {
        $parent = $this->parent('meta');
        $child  = $parent . '_translation';
        $posts  = $this->wpdb->posts;

        [$match, $matchArgs] = $this->textFilterPredicate($search, $filters, $this->postNameClauses());
        [$statusIn, $statusArgs] = $this->postStatusClause();

        // LEFT (not INNER): see listPostTexts() — a leftover row for a since-deleted post
        // must still show up via ordinary content matching, same "wp.ID IS NULL OR" guard.
        $sql = "SELECT p.id AS id, p.meta_key AS meta_key, p.original_text AS original_text,
                       t.translated_text AS translated_text, t.status AS status, t.provider AS provider,
                       t.ai_pending_since AS ai_pending_since, t.ai_error AS ai_error
                FROM {$parent} p
                LEFT JOIN {$posts} wp ON wp.ID = p.post_id
                LEFT JOIN {$child} t ON t.parent_id = p.id AND t.lang_code = %s
                WHERE p.post_id = %d AND p.status = %d
                      AND (wp.ID IS NULL OR wp.post_status IN ({$statusIn})){$match}
                ORDER BY p.meta_key, p.id";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            $sql,
            $langCode,
            $postId,
            TranslationStatus::SOURCE_DISCOVERED,
            ...$statusArgs,
            ...$matchArgs
        ), ARRAY_A);

        return array_map(function (array $r): array {
            $unit          = $this->textUnitRow($r);
            $unit['field'] = (string) $r['meta_key'];
            return $unit;
        }, (array) $rows);
    }

    /**
     * Every discovered post-meta text unit for one language in a single query,
     * each carrying its post_id. The listMetaTexts() counterpart to
     * listAllPostTexts() -- see that method for why.
     *
     * @return array<int, array{id:int, post_id:int, field:string, original_text:string,
     *   translated_text:?string, status:?int, provider:?string}>
     */
    public function listAllMetaTexts(string $langCode): array
    {
        $parent = $this->parent('meta');
        $child  = $parent . '_translation';
        $posts  = $this->wpdb->posts;

        [$statusIn, $statusArgs] = $this->postStatusClause();

        $sql = "SELECT p.id AS id, p.post_id AS post_id, p.meta_key AS meta_key, p.original_text AS original_text,
                       t.translated_text AS translated_text, t.status AS status, t.provider AS provider,
                       t.ai_pending_since AS ai_pending_since, t.ai_error AS ai_error
                FROM {$parent} p
                LEFT JOIN {$posts} wp ON wp.ID = p.post_id
                LEFT JOIN {$child} t ON t.parent_id = p.id AND t.lang_code = %s
                WHERE p.status = %d
                      AND (wp.ID IS NULL OR wp.post_status IN ({$statusIn}))
                ORDER BY p.post_id, p.meta_key, p.id";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            $sql,
            $langCode,
            TranslationStatus::SOURCE_DISCOVERED,
            ...$statusArgs
        ), ARRAY_A);

        return array_map(function (array $r): array {
            $unit            = $this->textUnitRow($r);
            $unit['field']   = (string) $r['meta_key'];
            $unit['post_id'] = (int) $r['post_id'];
            return $unit;
        }, (array) $rows);
    }

    /**
     * Per-taxonomy term-meta counts (an ACF field on a category, etc.) — merges
     * straight into the taxonomy card's totals in CollectionBoard::buildCollections(),
     * the same way postMetaCounts() merges into a post's card. A term shared across
     * taxonomies (legacy pre-4.2 content) would count under each — not worth
     * guarding against on a fresh site.
     */
    public function termMetaCounts(string $langCode, string $search = '', array $filters = []): array
    {
        $parent = $this->parent('term_meta');
        $child  = $parent . '_translation';
        $tt     = $this->wpdb->term_taxonomy;

        [$match, $matchArgs] = $this->textFilterPredicate($search, $filters);

        // term_meta is a parent_id child: join on parent_id, not original_hash.
        $sql = "SELECT tx.taxonomy AS taxonomy,
                       COUNT(*)              AS total,
                       SUM(t.translated_text IS NOT NULL AND t.status = -1)  AS needs_review,
                       SUM(t.translated_text IS NOT NULL AND t.status != -1) AS translated,
                       SUM(t.id IS NOT NULL AND t.translated_text IS NULL AND t.ai_pending_since IS NOT NULL) AS pending,
                       SUM(t.id IS NULL OR (t.translated_text IS NULL AND t.ai_pending_since IS NULL))        AS missing
                FROM {$parent} p
                JOIN {$tt} tx ON tx.term_id = p.term_id
                LEFT JOIN {$child} t ON t.parent_id = p.id AND t.lang_code = %s
                WHERE p.status = %d{$match}
                GROUP BY tx.taxonomy";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            $sql,
            $langCode,
            TranslationStatus::SOURCE_DISCOVERED,
            ...$matchArgs
        ), ARRAY_A);

        return array_map(static fn (array $r): array => [
            'taxonomy'     => (string) $r['taxonomy'],
            'total'        => (int) $r['total'],
            'translated'   => (int) $r['translated'],
            'needs_review' => (int) $r['needs_review'],
            'pending'      => (int) $r['pending'],
            'missing'      => (int) $r['missing'],
        ], (array) $rows);
    }

    /** The discovered term-meta text units for one taxonomy. For expanding the taxonomy group. */
    public function listTermMetaTexts(string $taxonomy, string $langCode, string $search = '', array $filters = []): array
    {
        $parent = $this->parent('term_meta');
        $child  = $parent . '_translation';
        $tt     = $this->wpdb->term_taxonomy;

        [$match, $matchArgs] = $this->textFilterPredicate($search, $filters);

        $sql = "SELECT p.id AS id, p.term_id AS term_id, p.meta_key AS meta_key, p.original_text AS original_text,
                       t.translated_text AS translated_text, t.status AS status, t.provider AS provider,
                       t.ai_pending_since AS ai_pending_since, t.ai_error AS ai_error
                FROM {$parent} p
                JOIN {$tt} tx ON tx.term_id = p.term_id
                LEFT JOIN {$child} t ON t.parent_id = p.id AND t.lang_code = %s
                WHERE tx.taxonomy = %s AND p.status = %d{$match}
                ORDER BY p.meta_key, p.id";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            $sql,
            $langCode,
            $taxonomy,
            TranslationStatus::SOURCE_DISCOVERED,
            ...$matchArgs
        ), ARRAY_A);

        return array_map(function (array $r): array {
            $unit            = $this->textUnitRow($r);
            $unit['field']   = (string) $r['meta_key'];
            $unit['term_id'] = (int) $r['term_id'];
            return $unit;
        }, (array) $rows);
    }

    /** @inheritDoc */
    public function listMediaPostTexts(string $langCode, string $search = '', array $filters = []): array
    {
        $parent = $this->parent('post');
        $child  = $parent . '_translation';
        $posts  = $this->wpdb->posts;

        [$match, $matchArgs] = $this->textFilterPredicate($search, $filters);
        [$statusIn, $statusArgs] = $this->postStatusClause();

        $sql = "SELECT p.id AS id, p.field AS field, p.post_id AS post_id, p.original_text AS original_text,
                       t.translated_text AS translated_text, t.status AS status, t.provider AS provider,
                       t.ai_pending_since AS ai_pending_since, t.ai_error AS ai_error
                FROM {$parent} p
                JOIN {$posts} wp ON wp.ID = p.post_id
                LEFT JOIN {$child} t ON t.original_hash = p.original_hash AND t.lang_code = %s
                WHERE wp.post_type = 'attachment' AND wp.post_mime_type LIKE 'image/%%'
                      AND wp.post_status IN ({$statusIn})
                      AND p.status = %d{$match}
                ORDER BY wp.post_title, " . self::FIELD_SORT . ", p.field, p.id";

        $args = array_merge([$langCode], $statusArgs, [TranslationStatus::SOURCE_DISCOVERED], $matchArgs);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, ...$args), ARRAY_A);

        return array_map(function (array $r): array {
            $unit             = $this->textUnitRow($r);
            $unit['post_id']  = (int) $r['post_id'];
            return $unit;
        }, (array) $rows);
    }

    /** @inheritDoc */
    public function listMediaAltTexts(string $langCode, string $search = '', array $filters = []): array
    {
        $parent = $this->parent('meta');
        $child  = $parent . '_translation';
        $posts  = $this->wpdb->posts;

        [$match, $matchArgs] = $this->textFilterPredicate($search, $filters);
        [$statusIn, $statusArgs] = $this->postStatusClause();

        $sql = "SELECT p.id AS id, p.meta_key AS meta_key, p.post_id AS post_id, p.original_text AS original_text,
                       t.translated_text AS translated_text, t.status AS status, t.provider AS provider,
                       t.ai_pending_since AS ai_pending_since, t.ai_error AS ai_error
                FROM {$parent} p
                JOIN {$posts} wp ON wp.ID = p.post_id
                LEFT JOIN {$child} t ON t.parent_id = p.id AND t.lang_code = %s
                WHERE wp.post_type = 'attachment' AND wp.post_mime_type LIKE 'image/%%'
                      AND wp.post_status IN ({$statusIn})
                      AND p.status = %d{$match}
                ORDER BY wp.post_title, p.id";

        $args = array_merge([$langCode], $statusArgs, [TranslationStatus::SOURCE_DISCOVERED], $matchArgs);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, ...$args), ARRAY_A);

        return array_map(function (array $r): array {
            $unit            = $this->textUnitRow($r);
            $unit['field']   = (string) $r['meta_key'];
            $unit['post_id'] = (int) $r['post_id'];
            return $unit;
        }, (array) $rows);
    }



    public function htmlCollectionSummary(string $langCode, string $search = '', array $filters = []): array
    {
        $parent = $this->parent('html');
        $child  = $parent . '_translation';

        [$match, $matchArgs] = $this->textFilterPredicate($search, $filters);

        $sql = "SELECT COUNT(*)           AS total,
                       SUM(t.translated_text IS NOT NULL AND t.status = -1)  AS needs_review,
                       SUM(t.translated_text IS NOT NULL AND t.status != -1) AS translated,
                       SUM(t.id IS NOT NULL AND t.translated_text IS NULL AND t.ai_pending_since IS NOT NULL) AS pending,
                       SUM(t.id IS NULL OR (t.translated_text IS NULL AND t.ai_pending_since IS NULL))        AS missing
                FROM {$parent} p
                LEFT JOIN {$child} t ON t.original_hash = p.original_hash AND t.lang_code = %s
                WHERE p.status = %d{$match}";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            $sql,
            $langCode,
            TranslationStatus::SOURCE_DISCOVERED,
            ...$matchArgs
        ), ARRAY_A) ?: [];

        return $this->summaryRow($row);
    }

    public function listHtmlTexts(string $langCode, string $search = '', array $filters = []): array
    {
        return $this->htmlTextsByStatus(TranslationStatus::SOURCE_DISCOVERED, $langCode, $search, $filters);
    }

    /** Same shape as listHtmlTexts(), scoped to rows flagged stale for admin review. */
    public function listStaleHtmlSources(string $langCode, string $search = '', array $filters = []): array
    {
        return $this->htmlTextsByStatus(TranslationStatus::SOURCE_STALE, $langCode, $search, $filters);
    }

    /** Shared query behind listHtmlTexts()/listStaleHtmlSources(), scoped by parent status. */
    private function htmlTextsByStatus(int $status, string $langCode, string $search, array $filters): array
    {
        $parent = $this->parent('html');
        $child  = $parent . '_translation';

        [$match, $matchArgs] = $this->textFilterPredicate($search, $filters);

        $sql = "SELECT p.id AS id, p.selector AS selector, p.source_url AS source_url, p.original_text AS original_text,
                       LOWER(HEX(p.original_hash)) AS original_hash,
                       t.translated_text AS translated_text, t.status AS status, t.provider AS provider,
                       t.ai_pending_since AS ai_pending_since, t.ai_error AS ai_error
                FROM {$parent} p
                LEFT JOIN {$child} t ON t.original_hash = p.original_hash AND t.lang_code = %s
                WHERE p.status = %d{$match}
                ORDER BY p.selector, p.id";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            $sql,
            $langCode,
            $status,
            ...$matchArgs
        ), ARRAY_A);

        return array_map(function (array $r): array {
            $unit               = $this->textUnitRow($r);
            $unit['field']      = (string) $r['selector'];
            $unit['source_url'] = $r['source_url'] === null ? null : (string) $r['source_url'];
            return $unit;
        }, (array) $rows);
    }

    /**
     * Every ignored source (parent status -1) across all seven parent tables,
     * flattened into one board-row list for the synthetic "ignored:all"
     * collection. Language-global on purpose: ignoreSource() deletes the child
     * translations, so an ignored source is untranslated in every language and
     * the list is identical whichever language the board is on — no child join,
     * no fallbacks. Each row carries its `kind` so CollectionBoard can build the
     * right SourceRef for the row-level Restore action.
     *
     * The per-table `kind` literal and column names are compile-time constants
     * from this method, never request input — spliced, not bound, like FIELD_SORT.
     */
    public function listIgnoredTexts(string $search = ''): array
    {
        $p = TranslationMemorySchema::parentTables();
        // kind => [table, display-field column, original_hash expression]
        $sources = [
            'post'      => [$p['post'],      'field',       'LOWER(HEX(original_hash))'],
            'meta'      => [$p['meta'],      'meta_key',    'NULL'],
            'term'      => [$p['term'],      'field',       'LOWER(HEX(original_hash))'],
            'term_meta' => [$p['term_meta'], 'meta_key',    'NULL'],
            'option'    => [$p['option'],    'option_name', 'LOWER(HEX(original_hash))'],
            'user'      => [$p['user'],      'field',       'LOWER(HEX(original_hash))'],
            'html'      => [$p['html'],      'selector',    'LOWER(HEX(original_hash))'],
        ];

        $like    = $search === '' ? null : '%' . $this->wpdb->esc_like($search) . '%';
        $selects = [];
        $args    = [];
        foreach ($sources as $kind => [$table, $fieldCol, $hashExpr]) {
            $where  = 'status = %d';
            $args[] = TranslationStatus::SOURCE_IGNORED;
            if ($like !== null) {
                $where .= ' AND original_text LIKE %s';
                $args[] = $like;
            }
            $selects[] = "SELECT '{$kind}' AS kind, id AS id, original_text AS original_text,
                                 {$hashExpr} AS original_hash, {$fieldCol} AS field,
                                 NULL AS translated_text, NULL AS status, NULL AS provider,
                                 NULL AS ai_pending_since, NULL AS ai_error
                          FROM {$table} WHERE {$where}";
        }
        $sql = implode("\nUNION ALL\n", $selects) . "\nORDER BY kind, field, id";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, ...$args), ARRAY_A);

        return array_map(function (array $r): array {
            $unit         = $this->textUnitRow($r);
            $unit['kind'] = (string) $r['kind'];
            return $unit;
        }, (array) $rows);
    }

    public function languageProgress(string $langCode): array
    {
        $total = 0;
        $done  = 0;

        // hash-link tables join on original_hash; parent_id tables (meta, term_meta) join on parent_id.
        foreach (TranslationMemorySchema::HASH_LINK_CHILDREN as $key) {
            [$t, $d] = $this->countTable($key, false, $langCode);
            $total  += $t;
            $done   += $d;
        }
        foreach (array_values(TranslationMemorySchema::PARENT_ID_CHILD_FKS) as $key) {
            [$t, $d] = $this->countTable($key, true, $langCode);
            $total  += $t;
            $done   += $d;
        }

        return ['total' => $total, 'translated' => $done];
    }

    /**
     * @return array{0:int, 1:int} [total sources, done translations] for one table + language.
     *
     * "done" = the child row actually holds translated text
     * (`translated_text IS NOT NULL`), which is the same set the board's own
     * per-collection totals count: machine (1), reviewed (2) AND needs_review
     * (-1) all count, because the sidebar percentage reads "how much of this
     * language has *some* translation", not "how much is signed off". It is NOT
     * `status <> 0`: a still-pending AI job leaves status 0 (correctly excluded),
     * but a row flagged needs_review with its text since cleared, or otherwise
     * left with a non-zero status and no text, must not count — checking the
     * text directly keeps this honest whatever the status says.
     */
    /**
     * languageProgress() for every language at once: one total + one grouped
     * "done" query per source table, instead of 7 aggregates per language.
     * The rail's percentages are the only caller, and it always wants them all
     * -- asking language-by-language made "refresh %" cost 7N table scans.
     *
     * @param string[] $langCodes
     * @return array<string, array{total:int, translated:int, pending:int}> keyed by language code
     */
    public function languageProgressAll(array $langCodes): array
    {
        $out = [];
        foreach ($langCodes as $code) {
            $out[$code] = ['total' => 0, 'translated' => 0, 'pending' => 0];
        }
        if ($out === []) {
            return [];
        }

        $tables = array_merge(
            array_map(static fn (string $k): array => [$k, false], TranslationMemorySchema::HASH_LINK_CHILDREN),
            array_map(static fn (string $k): array => [$k, true], array_values(TranslationMemorySchema::PARENT_ID_CHILD_FKS))
        );

        foreach ($tables as [$tableKey, $parentId]) {
            $total = $this->countSources($tableKey);
            foreach ($out as $code => $_) {
                $out[$code]['total'] += $total;
            }
            foreach ($this->countTranslatedByLang($tableKey, $parentId) as $code => $counts) {
                if (isset($out[$code])) {
                    $out[$code]['translated'] += $counts['translated'];
                    $out[$code]['pending']    += $counts['pending'];
                }
            }
        }

        return $out;
    }

    /** Discovered source rows in one parent table -- language-independent, so counted once. */
    private function countSources(string $tableKey): int
    {
        $parent = $this->parent($tableKey);
        $args   = [TranslationStatus::SOURCE_DISCOVERED];

        // Same post-liveness rule as countTable(): post/meta rows only count
        // while their WP post is live.
        if ($tableKey === 'post' || $tableKey === 'meta') {
            $posts = $this->wpdb->posts;
            [$statusIn, $statusArgs] = $this->postStatusClause();
            $sql = "SELECT COUNT(*) FROM {$parent} p
                    JOIN {$posts} wp ON wp.ID = p.post_id
                    WHERE p.status = %d AND wp.post_status IN ({$statusIn})";
            array_push($args, ...$statusArgs);
        } else {
            $sql = "SELECT COUNT(*) FROM {$parent} p WHERE p.status = %d";
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        return (int) $this->wpdb->get_var($this->wpdb->prepare($sql, ...$args));
    }

    /**
     * Translated + still-queued source rows in one parent table, grouped by
     * language. The child is unique per (link, lang_code), so each parent row
     * contributes at most one row per language -- the same count countTable()
     * produces one language at a time.
     *
     * `pending` is what lets the rail poll itself while an AI queue drains,
     * without the SPA having to guess from whichever board happens to be open.
     *
     * @return array<string, array{translated:int, pending:int}>
     */
    private function countTranslatedByLang(string $tableKey, bool $parentId): array
    {
        $parent = $this->parent($tableKey);
        $child  = $parent . '_translation';
        $join   = $parentId ? 't.parent_id = p.id' : 't.original_hash = p.original_hash';
        // Same TTL the board applies in presentText() via
        // AiQueueConfig::isFreshPending() -- an abandoned stamp must stop
        // counting as queued, or the rail would poll itself forever.
        $fresh  = gmdate('Y-m-d H:i:s', (int) current_time('timestamp') - (AiQueueConfig::PENDING_TTL_HOURS * 3600));
        $args   = [$fresh, TranslationStatus::SOURCE_DISCOVERED];

        if ($tableKey === 'post' || $tableKey === 'meta') {
            $posts = $this->wpdb->posts;
            [$statusIn, $statusArgs] = $this->postStatusClause();
            $sql = "SELECT t.lang_code AS lang_code,
                           SUM(t.translated_text IS NOT NULL) AS done,
                           SUM(t.translated_text IS NULL AND t.ai_pending_since > %s) AS queued
                    FROM {$parent} p
                    JOIN {$posts} wp ON wp.ID = p.post_id
                    JOIN {$child} t ON {$join}
                    WHERE p.status = %d AND wp.post_status IN ({$statusIn})
                    GROUP BY t.lang_code";
            array_push($args, ...$statusArgs);
        } else {
            $sql = "SELECT t.lang_code AS lang_code,
                           SUM(t.translated_text IS NOT NULL) AS done,
                           SUM(t.translated_text IS NULL AND t.ai_pending_since > %s) AS queued
                    FROM {$parent} p
                    JOIN {$child} t ON {$join}
                    WHERE p.status = %d
                    GROUP BY t.lang_code";
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, ...$args), ARRAY_A);

        $out = [];
        foreach ((array) $rows as $r) {
            $out[(string) $r['lang_code']] = [
                'translated' => (int) $r['done'],
                'pending'    => (int) $r['queued'],
            ];
        }

        return $out;
    }

    private function countTable(string $tableKey, bool $parentId, string $langCode): array
    {
        $parent = $this->parent($tableKey);
        $child  = $parent . '_translation';
        $join   = $parentId ? 't.parent_id = p.id' : 't.original_hash = p.original_hash';

        $args = [$langCode];

        // post/meta are the only two tables tied to a WP post's own liveness — term,
        // html and option have no such per-post status to check, so they keep the
        // plain query. INNER (not LEFT): a hard-deleted post's row is already gone
        // via FK cascade, so there is no orphan case here to preserve, unlike the
        // per-row board listings' search-by-content behaviour.
        if ($tableKey === 'post' || $tableKey === 'meta') {
            $posts = $this->wpdb->posts;
            [$statusIn, $statusArgs] = $this->postStatusClause();

            $sql = "SELECT COUNT(*) AS total, SUM(t.translated_text IS NOT NULL) AS done
                    FROM {$parent} p
                    JOIN {$posts} wp ON wp.ID = p.post_id
                    LEFT JOIN {$child} t ON {$join} AND t.lang_code = %s
                    WHERE p.status = %d AND wp.post_status IN ({$statusIn})";
            $args[] = TranslationStatus::SOURCE_DISCOVERED;
            array_push($args, ...$statusArgs);
        } else {
            $sql = "SELECT COUNT(*) AS total, SUM(t.translated_text IS NOT NULL) AS done
                    FROM {$parent} p
                    LEFT JOIN {$child} t ON {$join} AND t.lang_code = %s
                    WHERE p.status = %d";
            $args[] = TranslationStatus::SOURCE_DISCOVERED;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $row = $this->wpdb->get_row($this->wpdb->prepare($sql, ...$args), ARRAY_A) ?: [];

        return [(int) ($row['total'] ?? 0), (int) ($row['done'] ?? 0)];
    }

    /** wp.post_status IN (...) placeholder fragment + its bound values, for a joined wp_posts row. */
    private function postStatusClause(): array
    {
        $statuses = ScannablePostStatuses::ALL;

        return [implode(', ', array_fill(0, count($statuses), '%s')), $statuses];
    }
    /**
     * The board search predicate + its bound args, or ['', []] for no search — so an
     * unsearched board runs byte-for-byte the query it has always run.
     *
     * The predicate lives in WHERE, not in the LEFT JOIN's ON: for an untranslated row
     * `t.translated_text LIKE …` is NULL, and NULL OR TRUE is TRUE, so a row whose
     * ORIGINAL matches survives with t.* still NULL — SUM(t.id IS NULL) keeps counting it
     * as missing. Only genuinely non-matching rows drop out. In ON, the predicate would
     * instead null out translated non-matches and inflate `missing`.
     *
     * The child is already restricted to the selected language by the ON clause, so the
     * translation half of the match can never leak another language.
     *
     * ponytail: plain LIKE, no FULLTEXT index. Known limit — composed units store codec
     * markers ({1}…{/1}), so a phrase that CROSSES a marker boundary does not match;
     * phrases inside one segment do. Fixing it needs REGEXP_REPLACE (MySQL 8+) or a
     * marker-stripped shadow column (DDL + DB_VERSION bump). Revisit only if users hit it.
     *
     * $extraClauses are additional full `"... LIKE %s"` boolean expressions (each with
     * exactly one placeholder) OR'd in alongside the two above — e.g. postNameClauses()
     * for matching a post's own title/slug. Every entry must be a compile-time-constant
     * string from this class, never request-derived input: it's spliced into the query,
     * not bound.
     *
     * @param string[] $extraClauses
     * @return array{0:string, 1:array<int,string>}
     */
    private function searchPredicate(string $search, array $extraClauses = []): array
    {
        if ($search === '') {
            return ['', []];
        }

        // esc_like neutralizes % _ \ in the TERM; prepare() quotes the whole value.
        $like    = '%' . $this->wpdb->esc_like($search) . '%';
        $clauses = array_merge(['p.original_text LIKE %s', 't.translated_text LIKE %s'], $extraClauses);

        return [' AND (' . implode(' OR ', $clauses) . ')', array_fill(0, count($clauses), $like)];
    }

    /**
     * A post's own title/slug, matched alongside its text content — so searching a page's
     * name finds it even when no individual row's own text contains the term. Since
     * wp.post_title/wp.post_name don't vary per row, once they match every row belonging to
     * that post passes the WHERE, not just one — the whole post's texts show up, which is the
     * point (found the page by name, now show me everything on it). Excludes attachments:
     * they're folded into the separate Media board by CollectionBoard, which this predicate
     * runs ahead of, so a filename match here would otherwise inflate Media's totals despite
     * Media being out of scope for this feature.
     *
     * Callers must already JOIN {$this->wpdb->posts} AS wp ON wp.ID = p.post_id.
     *
     * @return string[]
     */
    private function postNameClauses(): array
    {
        return [
            "(wp.post_type <> 'attachment' AND wp.post_title LIKE %s)",
            "(wp.post_type <> 'attachment' AND wp.post_name LIKE %s)",
        ];
    }

    /**
     * Board search plus row-level Origin and Text Length filters.
     *
     * Values inside one filter are ORed; Origin and Text Length are ANDed with
     * each other and with search. Callers validate values before they reach here.
     *
     * @param array{origin?:string[], text_length?:string[]} $filters
     * @param string[] $extraClauses see searchPredicate()
     * @return array{0:string, 1:array<int,string>}
     */
    private function textFilterPredicate(string $search, array $filters, array $extraClauses = []): array
    {
        [$predicate, $args] = $this->searchPredicate($search, $extraClauses);

        $originClauses = [];
        foreach ($filters['origin'] ?? [] as $origin) {
            switch ($origin) {
                case 'manual':
                    $originClauses[] = '(t.id IS NOT NULL AND t.provider IS NULL)';
                    break;
                case 'ai':
                    $originClauses[] = '(t.id IS NOT NULL AND t.provider IS NOT NULL)';
                    break;
                case 'not_translated':
                    $originClauses[] = 't.id IS NULL';
                    break;
                default:
                    throw new \RuntimeException('Unhandled match case: ' . var_export($origin, true));
            }
        }
        if ($originClauses !== []) {
            $predicate .= ' AND (' . implode(' OR ', $originClauses) . ')';
        }

        $lengthClauses = [];
        foreach ($filters['text_length'] ?? [] as $length) {
            switch ($length) {
                case 'short':
                    $lengthClauses[] = 'CHAR_LENGTH(p.original_text) <= 80';
                    break;
                case 'medium':
                    $lengthClauses[] = '(CHAR_LENGTH(p.original_text) BETWEEN 81 AND 300)';
                    break;
                case 'long':
                    $lengthClauses[] = 'CHAR_LENGTH(p.original_text) > 300';
                    break;
                // Mirrors admin-ui/screens/lengthWarning.mjs: 30% relative threshold
                // with a 15-char floor, same rule the UI warns on after a translation.
                case 'length_mismatch':
                    $lengthClauses[] = '(t.translated_text IS NOT NULL
                    AND CHAR_LENGTH(p.original_text) > 0
                    AND CHAR_LENGTH(t.translated_text) > 0
                    AND ABS(CHAR_LENGTH(t.translated_text) - CHAR_LENGTH(p.original_text))
                        > GREATEST(CHAR_LENGTH(p.original_text) * 0.3, 15))';
                    break;
                default:
                    throw new \RuntimeException('Unhandled match case: ' . var_export($length, true));
            }
        }
        if ($lengthClauses !== []) {
            $predicate .= ' AND (' . implode(' OR ', $lengthClauses) . ')';
        }

        return [$predicate, $args];
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
            'ai_pending_since' => $r['ai_pending_since'] ?? null,
            'ai_error'         => $r['ai_error'] ?? null,
            // Hash-link kinds (post/term/html/option) share one translation row
            // per (original_hash, lang) across every position with the same
            // text; absent for parent_id kinds (meta), which never share.
            'original_hash'   => isset($r['original_hash']) ? (string) $r['original_hash'] : null,
        ];
    }

    private function parent(string $tableKey): string
    {
        return TranslationMemorySchema::parentTables()[$tableKey];
    }

    /** Group-by-post collection row shared by listPostCollections()/postMetaCounts(). */
    private function collectionRow(array $r): array
    {
        return [
            'post_id'        => (int) $r['post_id'],
            'post_type'      => (string) $r['post_type'],
            'post_title'     => (string) $r['post_title'],
            'post_mime_type' => (string) $r['post_mime_type'],
            'total'          => (int) $r['total'],
            'translated'     => (int) $r['translated'],
            'needs_review'   => (int) $r['needs_review'],
            'pending'        => (int) $r['pending'],
            'missing'        => (int) $r['missing'],
        ];
    }

    /** Status-count summary shared by optionCollectionSummary()/htmlCollectionSummary(). */
    private function summaryRow(?array $row): array
    {
        return [
            'total'        => (int) ($row['total'] ?? 0),
            'translated'   => (int) ($row['translated'] ?? 0),
            'needs_review' => (int) ($row['needs_review'] ?? 0),
            'pending'      => (int) ($row['pending'] ?? 0),
            'missing'      => (int) ($row['missing'] ?? 0),
        ];
    }
}
