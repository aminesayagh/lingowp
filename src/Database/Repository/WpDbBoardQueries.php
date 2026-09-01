<?php

namespace LingoWP\Database\Repository;

use LingoWP\Shared\Source\SourceRef;
use LingoWP\Shared\Source\TranslationStatus;
use LingoWP\Shared\Source\ScannablePostStatuses;
use LingoWP\Database\TranslationMemorySchema;

/**
 * Read-only board/collection queries: the Admin dashboard's listing, grouping,
 * search, and export/import lookups. Split out of WpDbSourceRepository, which
 * keeps every write and every render-time read — this class only ever SELECTs,
 * shaped for the board's presentation needs (Problem 2 of the reorg plan).
 */
final class WpDbBoardQueries
{
    private \wpdb $wpdb;

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
                ORDER BY p.field, p.id";

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
                ORDER BY tm.name, p.field, p.id";

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
                ORDER BY wp.post_title, p.field, p.id";

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
     * "done" here counts anything other than untouched (child status default
     * 0 -- includes a still-pending AI job, which never writes status) or no
     * row at all: machine (1), reviewed (2), AND needs_review (-1) all count.
     * The sidebar's per-language percentage is meant to read "how much of
     * this language has *some* translation", not "how much is fully signed
     * off" -- needs_review already has translated text, it's just flagged
     * for a human look, so `status > 0` alone was undercounting it as if it
     * were untranslated.
     */
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

            $sql = "SELECT COUNT(*) AS total, SUM(t.status <> 0) AS done
                    FROM {$parent} p
                    JOIN {$posts} wp ON wp.ID = p.post_id
                    LEFT JOIN {$child} t ON {$join} AND t.lang_code = %s
                    WHERE p.status = %d AND wp.post_status IN ({$statusIn})";
            $args[] = TranslationStatus::SOURCE_DISCOVERED;
            array_push($args, ...$statusArgs);
        } else {
            $sql = "SELECT COUNT(*) AS total, SUM(t.status <> 0) AS done
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
