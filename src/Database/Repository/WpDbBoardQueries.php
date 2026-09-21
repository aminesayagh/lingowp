<?php

namespace LingoWP\Database\Repository;

use LingoWP\Shared\Source\SourceRef;
use LingoWP\Shared\Source\TranslationStatus;
use LingoWP\Shared\Source\ScannablePostStatuses;
use LingoWP\Database\TranslationMemorySchema;
use LingoWP\AiTranslation\AiQueueConfig;

final class WpDbBoardQueries
{
    private \wpdb $wpdb;

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

    public function listAllPostTexts(string $langCode): array
    {
        $parent = $this->parent('post');
        $child  = $parent . '_translation';
        $posts  = $this->wpdb->posts;

        [$statusIn, $statusArgs] = $this->postStatusClause();

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

    public function termMetaCounts(string $langCode, string $search = '', array $filters = []): array
    {
        $parent = $this->parent('term_meta');
        $child  = $parent . '_translation';
        $tt     = $this->wpdb->term_taxonomy;

        [$match, $matchArgs] = $this->textFilterPredicate($search, $filters);

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

    public function listStaleHtmlSources(string $langCode, string $search = '', array $filters = []): array
    {
        return $this->htmlTextsByStatus(TranslationStatus::SOURCE_STALE, $langCode, $search, $filters);
    }

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

    public function listIgnoredTexts(string $search = ''): array
    {
        $p = TranslationMemorySchema::parentTables();
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

    private function countSources(string $tableKey): int
    {
        $parent = $this->parent($tableKey);
        $args   = [TranslationStatus::SOURCE_DISCOVERED];

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

    private function countTranslatedByLang(string $tableKey, bool $parentId): array
    {
        $parent = $this->parent($tableKey);
        $child  = $parent . '_translation';
        $join   = $parentId ? 't.parent_id = p.id' : 't.original_hash = p.original_hash';
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

    private function postStatusClause(): array
    {
        $statuses = ScannablePostStatuses::ALL;

        return [implode(', ', array_fill(0, count($statuses), '%s')), $statuses];
    }
    private function searchPredicate(string $search, array $extraClauses = []): array
    {
        if ($search === '') {
            return ['', []];
        }

        $like    = '%' . $this->wpdb->esc_like($search) . '%';
        $clauses = array_merge(['p.original_text LIKE %s', 't.translated_text LIKE %s'], $extraClauses);

        return [' AND (' . implode(' OR ', $clauses) . ')', array_fill(0, count($clauses), $like)];
    }

    private function postNameClauses(): array
    {
        return [
            "(wp.post_type <> 'attachment' AND wp.post_title LIKE %s)",
            "(wp.post_type <> 'attachment' AND wp.post_name LIKE %s)",
        ];
    }

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
            'original_hash'   => isset($r['original_hash']) ? (string) $r['original_hash'] : null,
        ];
    }

    private function parent(string $tableKey): string
    {
        return TranslationMemorySchema::parentTables()[$tableKey];
    }

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
