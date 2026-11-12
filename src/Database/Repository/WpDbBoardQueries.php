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

    // Text rows sort title first, then body, then excerpt, then every other
    // field — the `CASE p.field …` in the ORDER BYs below. Plain
    // `ORDER BY p.field` sorts the column names alphabetically and buries the
    // title at the bottom of its group.

    public function __construct(\wpdb $wpdb)
    {
        $this->wpdb = $wpdb;
    }

    public function listPostCollections(string $langCode, string $search = '', array $filters = []): array
    {
        $parent = $this->parent('post');
        $child  = $parent . '_translation';
        $posts  = $this->wpdb->posts;

        $filterArgs = $this->filterArgs($search, $filters, true);

        // hash-link child is unique per (original_hash, lang_code), so the LEFT JOIN
        // yields at most one translation per source position — no COUNT fan-out.
        $sql = "SELECT p.post_id AS post_id, wp.post_type AS post_type, wp.post_title AS post_title,
                       wp.post_mime_type AS post_mime_type,
                       COUNT(*)              AS total,
                       SUM(t.translated_text IS NOT NULL AND t.status = -1)  AS needs_review,
                       SUM(t.translated_text IS NOT NULL AND t.status != -1) AS translated,
                       SUM(t.id IS NOT NULL AND t.translated_text IS NULL AND t.ai_pending_since IS NOT NULL) AS pending,
                       SUM(t.id IS NULL OR (t.translated_text IS NULL AND t.ai_pending_since IS NULL))        AS missing
                FROM %i p
                JOIN %i wp ON wp.ID = p.post_id
                LEFT JOIN %i t ON t.original_hash = p.original_hash AND t.lang_code = %s
                WHERE p.status = %d AND FIND_IN_SET(wp.post_status, %s)
                AND (%s = '' OR p.original_text LIKE %s OR t.translated_text LIKE %s
                     OR (wp.post_type <> 'attachment' AND (wp.post_title LIKE %s OR wp.post_name LIKE %s)))
                AND (%s = '' OR FIND_IN_SET(IF(t.id IS NULL, 'not_translated', IF(t.provider IS NULL, 'manual', 'ai')), %s))
                AND (%s = '' OR FIND_IN_SET(IF(CHAR_LENGTH(p.original_text) <= 80, 'short', IF(CHAR_LENGTH(p.original_text) <= 300, 'medium', 'long')), %s)
                     OR (FIND_IN_SET('length_mismatch', %s) AND t.translated_text IS NOT NULL
                         AND CHAR_LENGTH(p.original_text) > 0 AND CHAR_LENGTH(t.translated_text) > 0
                         AND ABS(CHAR_LENGTH(t.translated_text) - CHAR_LENGTH(p.original_text)) > GREATEST(CHAR_LENGTH(p.original_text) * 0.3, 15)))
                GROUP BY p.post_id, wp.post_type, wp.post_title, wp.post_mime_type
                ORDER BY wp.post_type, wp.post_title";

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB, WordPress.DB.PreparedSQL -- table names come from $wpdb and the plugin's schema map; every value goes through a placeholder.
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            $sql,
            $parent,
            $posts,
            $child,
            $langCode,
            TranslationStatus::SOURCE_DISCOVERED,
            ScannablePostStatuses::csv(),
            ...$filterArgs
        ), ARRAY_A);
        // phpcs:enable

        return array_map([$this, 'collectionRow'], (array) $rows);
    }

    public function listPostTexts(int $postId, string $langCode, string $search = '', array $filters = []): array
    {
        $parent = $this->parent('post');
        $child  = $parent . '_translation';
        $posts  = $this->wpdb->posts;

        $filterArgs = $this->filterArgs($search, $filters, true);

        // LEFT (not INNER): a leftover row for a since-deleted post must still show up via
        // ordinary content matching — wp.* is just NULL then, and NULL LIKE %s is falsy.
        // Same reasoning for the post-status guard below: "wp.ID IS NULL OR" keeps that
        // orphan row visible instead of silently dropping it (NULL IN (...) is falsy).
        $sql = "SELECT p.id AS id, p.field AS field, p.original_text AS original_text,
                       LOWER(HEX(p.original_hash)) AS original_hash,
                       t.translated_text AS translated_text, t.status AS status, t.provider AS provider,
                       t.ai_pending_since AS ai_pending_since, t.ai_error AS ai_error
                FROM %i p
                LEFT JOIN %i wp ON wp.ID = p.post_id
                LEFT JOIN %i t ON t.original_hash = p.original_hash AND t.lang_code = %s
                WHERE p.post_id = %d AND p.status = %d
                      AND (wp.ID IS NULL OR FIND_IN_SET(wp.post_status, %s))
                      AND (%s = '' OR p.original_text LIKE %s OR t.translated_text LIKE %s
                           OR (wp.post_type <> 'attachment' AND (wp.post_title LIKE %s OR wp.post_name LIKE %s)))
                      AND (%s = '' OR FIND_IN_SET(IF(t.id IS NULL, 'not_translated', IF(t.provider IS NULL, 'manual', 'ai')), %s))
                      AND (%s = '' OR FIND_IN_SET(IF(CHAR_LENGTH(p.original_text) <= 80, 'short', IF(CHAR_LENGTH(p.original_text) <= 300, 'medium', 'long')), %s)
                           OR (FIND_IN_SET('length_mismatch', %s) AND t.translated_text IS NOT NULL
                               AND CHAR_LENGTH(p.original_text) > 0 AND CHAR_LENGTH(t.translated_text) > 0
                               AND ABS(CHAR_LENGTH(t.translated_text) - CHAR_LENGTH(p.original_text)) > GREATEST(CHAR_LENGTH(p.original_text) * 0.3, 15)))
                ORDER BY CASE p.field WHEN 'post_title' THEN 1 WHEN 'name' THEN 1 WHEN 'post_content' THEN 2 WHEN 'description' THEN 2 WHEN 'post_excerpt' THEN 3 ELSE 4 END, p.field, p.id";

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB, WordPress.DB.PreparedSQL -- table names come from $wpdb and the plugin's schema map; every value goes through a placeholder.
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            $sql,
            $parent,
            $posts,
            $child,
            $langCode,
            $postId,
            TranslationStatus::SOURCE_DISCOVERED,
            ScannablePostStatuses::csv(),
            ...$filterArgs
        ), ARRAY_A);
        // phpcs:enable

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


        // Same LEFT JOIN + "wp.ID IS NULL OR" guard as listPostTexts(): keeps an
        // orphan row (post since deleted) visible, keeps trashed/draft posts out.
        $sql = "SELECT p.id AS id, p.post_id AS post_id, p.field AS field, p.original_text AS original_text,
                       LOWER(HEX(p.original_hash)) AS original_hash,
                       t.translated_text AS translated_text, t.status AS status, t.provider AS provider,
                       t.ai_pending_since AS ai_pending_since, t.ai_error AS ai_error
                FROM %i p
                LEFT JOIN %i wp ON wp.ID = p.post_id
                LEFT JOIN %i t ON t.original_hash = p.original_hash AND t.lang_code = %s
                WHERE p.status = %d
                      AND (wp.ID IS NULL OR FIND_IN_SET(wp.post_status, %s))
                ORDER BY p.post_id, CASE p.field WHEN 'post_title' THEN 1 WHEN 'name' THEN 1 WHEN 'post_content' THEN 2 WHEN 'description' THEN 2 WHEN 'post_excerpt' THEN 3 ELSE 4 END, p.field, p.id";

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB, WordPress.DB.PreparedSQL -- table names come from $wpdb and the plugin's schema map; every value goes through a placeholder.
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            $sql,
            $parent,
            $posts,
            $child,
            $langCode,
            TranslationStatus::SOURCE_DISCOVERED,
            ScannablePostStatuses::csv()
        ), ARRAY_A);
        // phpcs:enable

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

        $filterArgs = $this->filterArgs($search, $filters, false);

        $sql = "SELECT COUNT(*)           AS total,
                       SUM(t.translated_text IS NOT NULL AND t.status = -1)  AS needs_review,
                       SUM(t.translated_text IS NOT NULL AND t.status != -1) AS translated,
                       SUM(t.id IS NOT NULL AND t.translated_text IS NULL AND t.ai_pending_since IS NOT NULL) AS pending,
                       SUM(t.id IS NULL OR (t.translated_text IS NULL AND t.ai_pending_since IS NULL))        AS missing
                FROM %i p
                LEFT JOIN %i t ON t.original_hash = p.original_hash AND t.lang_code = %s
                WHERE p.status = %d
                AND (%s = '' OR p.original_text LIKE %s OR t.translated_text LIKE %s)
                AND (%s = '' OR FIND_IN_SET(IF(t.id IS NULL, 'not_translated', IF(t.provider IS NULL, 'manual', 'ai')), %s))
                AND (%s = '' OR FIND_IN_SET(IF(CHAR_LENGTH(p.original_text) <= 80, 'short', IF(CHAR_LENGTH(p.original_text) <= 300, 'medium', 'long')), %s)
                     OR (FIND_IN_SET('length_mismatch', %s) AND t.translated_text IS NOT NULL
                         AND CHAR_LENGTH(p.original_text) > 0 AND CHAR_LENGTH(t.translated_text) > 0
                         AND ABS(CHAR_LENGTH(t.translated_text) - CHAR_LENGTH(p.original_text)) > GREATEST(CHAR_LENGTH(p.original_text) * 0.3, 15)))";

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB, WordPress.DB.PreparedSQL -- table names come from $wpdb and the plugin's schema map; every value goes through a placeholder.
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            $sql,
            $parent,
            $child,
            $langCode,
            TranslationStatus::SOURCE_DISCOVERED,
            ...$filterArgs
        ), ARRAY_A) ?: [];
        // phpcs:enable

        return $this->summaryRow($row);
    }

    public function listOptionTexts(string $langCode, string $search = '', array $filters = []): array
    {
        $parent = $this->parent('option');
        $child  = $parent . '_translation';

        $filterArgs = $this->filterArgs($search, $filters, false);

        $sql = "SELECT p.id AS id, p.option_name AS option_name, p.original_text AS original_text,
                       LOWER(HEX(p.original_hash)) AS original_hash,
                       t.translated_text AS translated_text, t.status AS status, t.provider AS provider,
                       t.ai_pending_since AS ai_pending_since, t.ai_error AS ai_error
                FROM %i p
                LEFT JOIN %i t ON t.original_hash = p.original_hash AND t.lang_code = %s
                WHERE p.status = %d
                AND (%s = '' OR p.original_text LIKE %s OR t.translated_text LIKE %s)
                AND (%s = '' OR FIND_IN_SET(IF(t.id IS NULL, 'not_translated', IF(t.provider IS NULL, 'manual', 'ai')), %s))
                AND (%s = '' OR FIND_IN_SET(IF(CHAR_LENGTH(p.original_text) <= 80, 'short', IF(CHAR_LENGTH(p.original_text) <= 300, 'medium', 'long')), %s)
                     OR (FIND_IN_SET('length_mismatch', %s) AND t.translated_text IS NOT NULL
                         AND CHAR_LENGTH(p.original_text) > 0 AND CHAR_LENGTH(t.translated_text) > 0
                         AND ABS(CHAR_LENGTH(t.translated_text) - CHAR_LENGTH(p.original_text)) > GREATEST(CHAR_LENGTH(p.original_text) * 0.3, 15)))
                ORDER BY p.option_name, p.id";

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB, WordPress.DB.PreparedSQL -- table names come from $wpdb and the plugin's schema map; every value goes through a placeholder.
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            $sql,
            $parent,
            $child,
            $langCode,
            TranslationStatus::SOURCE_DISCOVERED,
            ...$filterArgs
        ), ARRAY_A);
        // phpcs:enable

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

        $filterArgs = $this->filterArgs($search, $filters, false);

        $sql = "SELECT COUNT(*)           AS total,
                       SUM(t.translated_text IS NOT NULL AND t.status = -1)  AS needs_review,
                       SUM(t.translated_text IS NOT NULL AND t.status != -1) AS translated,
                       SUM(t.id IS NOT NULL AND t.translated_text IS NULL AND t.ai_pending_since IS NOT NULL) AS pending,
                       SUM(t.id IS NULL OR (t.translated_text IS NULL AND t.ai_pending_since IS NULL))        AS missing
                FROM %i p
                LEFT JOIN %i t ON t.original_hash = p.original_hash AND t.lang_code = %s
                WHERE p.status = %d
                AND (%s = '' OR p.original_text LIKE %s OR t.translated_text LIKE %s)
                AND (%s = '' OR FIND_IN_SET(IF(t.id IS NULL, 'not_translated', IF(t.provider IS NULL, 'manual', 'ai')), %s))
                AND (%s = '' OR FIND_IN_SET(IF(CHAR_LENGTH(p.original_text) <= 80, 'short', IF(CHAR_LENGTH(p.original_text) <= 300, 'medium', 'long')), %s)
                     OR (FIND_IN_SET('length_mismatch', %s) AND t.translated_text IS NOT NULL
                         AND CHAR_LENGTH(p.original_text) > 0 AND CHAR_LENGTH(t.translated_text) > 0
                         AND ABS(CHAR_LENGTH(t.translated_text) - CHAR_LENGTH(p.original_text)) > GREATEST(CHAR_LENGTH(p.original_text) * 0.3, 15)))";

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB, WordPress.DB.PreparedSQL -- table names come from $wpdb and the plugin's schema map; every value goes through a placeholder.
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            $sql,
            $parent,
            $child,
            $langCode,
            TranslationStatus::SOURCE_DISCOVERED,
            ...$filterArgs
        ), ARRAY_A) ?: [];
        // phpcs:enable

        return $this->summaryRow($row);
    }

    /** The discovered author-bio text units for one language. For expanding the Author-bios group. */
    public function listUserTexts(string $langCode, string $search = '', array $filters = []): array
    {
        $parent = $this->parent('user');
        $child  = $parent . '_translation';
        $users  = $this->wpdb->users;

        $filterArgs = $this->filterArgs($search, $filters, false);

        $sql = "SELECT p.id AS id, p.user_id AS user_id, u.display_name AS display_name,
                       p.original_text AS original_text,
                       LOWER(HEX(p.original_hash)) AS original_hash,
                       t.translated_text AS translated_text, t.status AS status, t.provider AS provider,
                       t.ai_pending_since AS ai_pending_since, t.ai_error AS ai_error
                FROM %i p
                JOIN %i u ON u.ID = p.user_id
                LEFT JOIN %i t ON t.original_hash = p.original_hash AND t.lang_code = %s
                WHERE p.status = %d
                AND (%s = '' OR p.original_text LIKE %s OR t.translated_text LIKE %s)
                AND (%s = '' OR FIND_IN_SET(IF(t.id IS NULL, 'not_translated', IF(t.provider IS NULL, 'manual', 'ai')), %s))
                AND (%s = '' OR FIND_IN_SET(IF(CHAR_LENGTH(p.original_text) <= 80, 'short', IF(CHAR_LENGTH(p.original_text) <= 300, 'medium', 'long')), %s)
                     OR (FIND_IN_SET('length_mismatch', %s) AND t.translated_text IS NOT NULL
                         AND CHAR_LENGTH(p.original_text) > 0 AND CHAR_LENGTH(t.translated_text) > 0
                         AND ABS(CHAR_LENGTH(t.translated_text) - CHAR_LENGTH(p.original_text)) > GREATEST(CHAR_LENGTH(p.original_text) * 0.3, 15)))
                ORDER BY u.display_name, p.id";

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB, WordPress.DB.PreparedSQL -- table names come from $wpdb and the plugin's schema map; every value goes through a placeholder.
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            $sql,
            $parent,
            $users,
            $child,
            $langCode,
            TranslationStatus::SOURCE_DISCOVERED,
            ...$filterArgs
        ), ARRAY_A);
        // phpcs:enable

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

        $filterArgs = $this->filterArgs($search, $filters, false);

        $sql = "SELECT p.term_taxonomy_id AS term_taxonomy_id, tx.taxonomy AS taxonomy, tm.name AS name,
                       COUNT(*)              AS total,
                       SUM(t.translated_text IS NOT NULL AND t.status = -1)  AS needs_review,
                       SUM(t.translated_text IS NOT NULL AND t.status != -1) AS translated,
                       SUM(t.id IS NOT NULL AND t.translated_text IS NULL AND t.ai_pending_since IS NOT NULL) AS pending,
                       SUM(t.id IS NULL OR (t.translated_text IS NULL AND t.ai_pending_since IS NULL))        AS missing
                FROM %i p
                JOIN %i tx ON tx.term_taxonomy_id = p.term_taxonomy_id
                JOIN %i tm ON tm.term_id = tx.term_id
                LEFT JOIN %i t ON t.original_hash = p.original_hash AND t.lang_code = %s
                WHERE p.status = %d
                AND (%s = '' OR p.original_text LIKE %s OR t.translated_text LIKE %s)
                AND (%s = '' OR FIND_IN_SET(IF(t.id IS NULL, 'not_translated', IF(t.provider IS NULL, 'manual', 'ai')), %s))
                AND (%s = '' OR FIND_IN_SET(IF(CHAR_LENGTH(p.original_text) <= 80, 'short', IF(CHAR_LENGTH(p.original_text) <= 300, 'medium', 'long')), %s)
                     OR (FIND_IN_SET('length_mismatch', %s) AND t.translated_text IS NOT NULL
                         AND CHAR_LENGTH(p.original_text) > 0 AND CHAR_LENGTH(t.translated_text) > 0
                         AND ABS(CHAR_LENGTH(t.translated_text) - CHAR_LENGTH(p.original_text)) > GREATEST(CHAR_LENGTH(p.original_text) * 0.3, 15)))
                GROUP BY p.term_taxonomy_id, tx.taxonomy, tm.name
                ORDER BY tx.taxonomy, tm.name";

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB, WordPress.DB.PreparedSQL -- table names come from $wpdb and the plugin's schema map; every value goes through a placeholder.
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            $sql,
            $parent,
            $tt,
            $terms,
            $child,
            $langCode,
            TranslationStatus::SOURCE_DISCOVERED,
            ...$filterArgs
        ), ARRAY_A);
        // phpcs:enable

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
                FROM %i p
                LEFT JOIN %i t ON t.original_hash = p.original_hash AND t.lang_code = %s
                WHERE p.term_taxonomy_id = %d AND p.status = %d
                ORDER BY CASE p.field WHEN 'post_title' THEN 1 WHEN 'name' THEN 1 WHEN 'post_content' THEN 2 WHEN 'description' THEN 2 WHEN 'post_excerpt' THEN 3 ELSE 4 END, p.field, p.id";

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB, WordPress.DB.PreparedSQL -- table names come from $wpdb and the plugin's schema map; every value goes through a placeholder.
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            $sql,
            $parent,
            $child,
            $langCode,
            $termTaxonomyId,
            TranslationStatus::SOURCE_DISCOVERED
        ), ARRAY_A);
        // phpcs:enable

        return array_map([$this, 'textUnitRow'], (array) $rows);
    }

    public function listTaxonomyTexts(string $taxonomy, string $langCode, string $search = '', array $filters = []): array
    {
        $parent = $this->parent('term');
        $child  = $parent . '_translation';
        $tt     = $this->wpdb->term_taxonomy;
        $terms  = $this->wpdb->terms;

        $filterArgs = $this->filterArgs($search, $filters, false);

        $sql = "SELECT p.id AS id, p.field AS field, tm.term_id AS term_id, p.original_text AS original_text,
                       LOWER(HEX(p.original_hash)) AS original_hash,
                       t.translated_text AS translated_text, t.status AS status, t.provider AS provider,
                       t.ai_pending_since AS ai_pending_since, t.ai_error AS ai_error
                FROM %i p
                JOIN %i tx ON tx.term_taxonomy_id = p.term_taxonomy_id
                JOIN %i tm ON tm.term_id = tx.term_id
                LEFT JOIN %i t ON t.original_hash = p.original_hash AND t.lang_code = %s
                WHERE tx.taxonomy = %s AND p.status = %d
                AND (%s = '' OR p.original_text LIKE %s OR t.translated_text LIKE %s)
                AND (%s = '' OR FIND_IN_SET(IF(t.id IS NULL, 'not_translated', IF(t.provider IS NULL, 'manual', 'ai')), %s))
                AND (%s = '' OR FIND_IN_SET(IF(CHAR_LENGTH(p.original_text) <= 80, 'short', IF(CHAR_LENGTH(p.original_text) <= 300, 'medium', 'long')), %s)
                     OR (FIND_IN_SET('length_mismatch', %s) AND t.translated_text IS NOT NULL
                         AND CHAR_LENGTH(p.original_text) > 0 AND CHAR_LENGTH(t.translated_text) > 0
                         AND ABS(CHAR_LENGTH(t.translated_text) - CHAR_LENGTH(p.original_text)) > GREATEST(CHAR_LENGTH(p.original_text) * 0.3, 15)))
                ORDER BY tm.name, CASE p.field WHEN 'post_title' THEN 1 WHEN 'name' THEN 1 WHEN 'post_content' THEN 2 WHEN 'description' THEN 2 WHEN 'post_excerpt' THEN 3 ELSE 4 END, p.field, p.id";

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB, WordPress.DB.PreparedSQL -- table names come from $wpdb and the plugin's schema map; every value goes through a placeholder.
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            $sql,
            $parent,
            $tt,
            $terms,
            $child,
            $langCode,
            $taxonomy,
            TranslationStatus::SOURCE_DISCOVERED,
            ...$filterArgs
        ), ARRAY_A);
        // phpcs:enable

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

        $filterArgs = $this->filterArgs($search, $filters, true);

        // meta is a parent_id child: join on parent_id, not original_hash.
        $sql = "SELECT p.post_id AS post_id, wp.post_type AS post_type, wp.post_title AS post_title,
                       wp.post_mime_type AS post_mime_type,
                       COUNT(*)              AS total,
                       SUM(t.translated_text IS NOT NULL AND t.status = -1)  AS needs_review,
                       SUM(t.translated_text IS NOT NULL AND t.status != -1) AS translated,
                       SUM(t.id IS NOT NULL AND t.translated_text IS NULL AND t.ai_pending_since IS NOT NULL) AS pending,
                       SUM(t.id IS NULL OR (t.translated_text IS NULL AND t.ai_pending_since IS NULL))        AS missing
                FROM %i p
                JOIN %i wp ON wp.ID = p.post_id
                LEFT JOIN %i t ON t.parent_id = p.id AND t.lang_code = %s
                WHERE p.status = %d AND FIND_IN_SET(wp.post_status, %s)
                AND (%s = '' OR p.original_text LIKE %s OR t.translated_text LIKE %s
                     OR (wp.post_type <> 'attachment' AND (wp.post_title LIKE %s OR wp.post_name LIKE %s)))
                AND (%s = '' OR FIND_IN_SET(IF(t.id IS NULL, 'not_translated', IF(t.provider IS NULL, 'manual', 'ai')), %s))
                AND (%s = '' OR FIND_IN_SET(IF(CHAR_LENGTH(p.original_text) <= 80, 'short', IF(CHAR_LENGTH(p.original_text) <= 300, 'medium', 'long')), %s)
                     OR (FIND_IN_SET('length_mismatch', %s) AND t.translated_text IS NOT NULL
                         AND CHAR_LENGTH(p.original_text) > 0 AND CHAR_LENGTH(t.translated_text) > 0
                         AND ABS(CHAR_LENGTH(t.translated_text) - CHAR_LENGTH(p.original_text)) > GREATEST(CHAR_LENGTH(p.original_text) * 0.3, 15)))
                GROUP BY p.post_id, wp.post_type, wp.post_title, wp.post_mime_type
                ORDER BY wp.post_type, wp.post_title";

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB, WordPress.DB.PreparedSQL -- table names come from $wpdb and the plugin's schema map; every value goes through a placeholder.
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            $sql,
            $parent,
            $posts,
            $child,
            $langCode,
            TranslationStatus::SOURCE_DISCOVERED,
            ScannablePostStatuses::csv(),
            ...$filterArgs
        ), ARRAY_A);
        // phpcs:enable

        return array_map([$this, 'collectionRow'], (array) $rows);
    }

    public function listMetaTexts(int $postId, string $langCode, string $search = '', array $filters = []): array
    {
        $parent = $this->parent('meta');
        $child  = $parent . '_translation';
        $posts  = $this->wpdb->posts;

        $filterArgs = $this->filterArgs($search, $filters, true);

        // LEFT (not INNER): see listPostTexts() — a leftover row for a since-deleted post
        // must still show up via ordinary content matching, same "wp.ID IS NULL OR" guard.
        $sql = "SELECT p.id AS id, p.meta_key AS meta_key, p.original_text AS original_text,
                       t.translated_text AS translated_text, t.status AS status, t.provider AS provider,
                       t.ai_pending_since AS ai_pending_since, t.ai_error AS ai_error
                FROM %i p
                LEFT JOIN %i wp ON wp.ID = p.post_id
                LEFT JOIN %i t ON t.parent_id = p.id AND t.lang_code = %s
                WHERE p.post_id = %d AND p.status = %d
                      AND (wp.ID IS NULL OR FIND_IN_SET(wp.post_status, %s))
                      AND (%s = '' OR p.original_text LIKE %s OR t.translated_text LIKE %s
                           OR (wp.post_type <> 'attachment' AND (wp.post_title LIKE %s OR wp.post_name LIKE %s)))
                      AND (%s = '' OR FIND_IN_SET(IF(t.id IS NULL, 'not_translated', IF(t.provider IS NULL, 'manual', 'ai')), %s))
                      AND (%s = '' OR FIND_IN_SET(IF(CHAR_LENGTH(p.original_text) <= 80, 'short', IF(CHAR_LENGTH(p.original_text) <= 300, 'medium', 'long')), %s)
                           OR (FIND_IN_SET('length_mismatch', %s) AND t.translated_text IS NOT NULL
                               AND CHAR_LENGTH(p.original_text) > 0 AND CHAR_LENGTH(t.translated_text) > 0
                               AND ABS(CHAR_LENGTH(t.translated_text) - CHAR_LENGTH(p.original_text)) > GREATEST(CHAR_LENGTH(p.original_text) * 0.3, 15)))
                ORDER BY p.meta_key, p.id";

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB, WordPress.DB.PreparedSQL -- table names come from $wpdb and the plugin's schema map; every value goes through a placeholder.
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            $sql,
            $parent,
            $posts,
            $child,
            $langCode,
            $postId,
            TranslationStatus::SOURCE_DISCOVERED,
            ScannablePostStatuses::csv(),
            ...$filterArgs
        ), ARRAY_A);
        // phpcs:enable

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


        $sql = "SELECT p.id AS id, p.post_id AS post_id, p.meta_key AS meta_key, p.original_text AS original_text,
                       t.translated_text AS translated_text, t.status AS status, t.provider AS provider,
                       t.ai_pending_since AS ai_pending_since, t.ai_error AS ai_error
                FROM %i p
                LEFT JOIN %i wp ON wp.ID = p.post_id
                LEFT JOIN %i t ON t.parent_id = p.id AND t.lang_code = %s
                WHERE p.status = %d
                      AND (wp.ID IS NULL OR FIND_IN_SET(wp.post_status, %s))
                ORDER BY p.post_id, p.meta_key, p.id";

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB, WordPress.DB.PreparedSQL -- table names come from $wpdb and the plugin's schema map; every value goes through a placeholder.
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            $sql,
            $parent,
            $posts,
            $child,
            $langCode,
            TranslationStatus::SOURCE_DISCOVERED,
            ScannablePostStatuses::csv()
        ), ARRAY_A);
        // phpcs:enable

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

        $filterArgs = $this->filterArgs($search, $filters, false);

        // term_meta is a parent_id child: join on parent_id, not original_hash.
        $sql = "SELECT tx.taxonomy AS taxonomy,
                       COUNT(*)              AS total,
                       SUM(t.translated_text IS NOT NULL AND t.status = -1)  AS needs_review,
                       SUM(t.translated_text IS NOT NULL AND t.status != -1) AS translated,
                       SUM(t.id IS NOT NULL AND t.translated_text IS NULL AND t.ai_pending_since IS NOT NULL) AS pending,
                       SUM(t.id IS NULL OR (t.translated_text IS NULL AND t.ai_pending_since IS NULL))        AS missing
                FROM %i p
                JOIN %i tx ON tx.term_id = p.term_id
                LEFT JOIN %i t ON t.parent_id = p.id AND t.lang_code = %s
                WHERE p.status = %d
                AND (%s = '' OR p.original_text LIKE %s OR t.translated_text LIKE %s)
                AND (%s = '' OR FIND_IN_SET(IF(t.id IS NULL, 'not_translated', IF(t.provider IS NULL, 'manual', 'ai')), %s))
                AND (%s = '' OR FIND_IN_SET(IF(CHAR_LENGTH(p.original_text) <= 80, 'short', IF(CHAR_LENGTH(p.original_text) <= 300, 'medium', 'long')), %s)
                     OR (FIND_IN_SET('length_mismatch', %s) AND t.translated_text IS NOT NULL
                         AND CHAR_LENGTH(p.original_text) > 0 AND CHAR_LENGTH(t.translated_text) > 0
                         AND ABS(CHAR_LENGTH(t.translated_text) - CHAR_LENGTH(p.original_text)) > GREATEST(CHAR_LENGTH(p.original_text) * 0.3, 15)))
                GROUP BY tx.taxonomy";

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB, WordPress.DB.PreparedSQL -- table names come from $wpdb and the plugin's schema map; every value goes through a placeholder.
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            $sql,
            $parent,
            $tt,
            $child,
            $langCode,
            TranslationStatus::SOURCE_DISCOVERED,
            ...$filterArgs
        ), ARRAY_A);
        // phpcs:enable

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

        $filterArgs = $this->filterArgs($search, $filters, false);

        $sql = "SELECT p.id AS id, p.term_id AS term_id, p.meta_key AS meta_key, p.original_text AS original_text,
                       t.translated_text AS translated_text, t.status AS status, t.provider AS provider,
                       t.ai_pending_since AS ai_pending_since, t.ai_error AS ai_error
                FROM %i p
                JOIN %i tx ON tx.term_id = p.term_id
                LEFT JOIN %i t ON t.parent_id = p.id AND t.lang_code = %s
                WHERE tx.taxonomy = %s AND p.status = %d
                AND (%s = '' OR p.original_text LIKE %s OR t.translated_text LIKE %s)
                AND (%s = '' OR FIND_IN_SET(IF(t.id IS NULL, 'not_translated', IF(t.provider IS NULL, 'manual', 'ai')), %s))
                AND (%s = '' OR FIND_IN_SET(IF(CHAR_LENGTH(p.original_text) <= 80, 'short', IF(CHAR_LENGTH(p.original_text) <= 300, 'medium', 'long')), %s)
                     OR (FIND_IN_SET('length_mismatch', %s) AND t.translated_text IS NOT NULL
                         AND CHAR_LENGTH(p.original_text) > 0 AND CHAR_LENGTH(t.translated_text) > 0
                         AND ABS(CHAR_LENGTH(t.translated_text) - CHAR_LENGTH(p.original_text)) > GREATEST(CHAR_LENGTH(p.original_text) * 0.3, 15)))
                ORDER BY p.meta_key, p.id";

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB, WordPress.DB.PreparedSQL -- table names come from $wpdb and the plugin's schema map; every value goes through a placeholder.
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            $sql,
            $parent,
            $tt,
            $child,
            $langCode,
            $taxonomy,
            TranslationStatus::SOURCE_DISCOVERED,
            ...$filterArgs
        ), ARRAY_A);
        // phpcs:enable

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

        $filterArgs = $this->filterArgs($search, $filters, false);

        $sql = "SELECT p.id AS id, p.field AS field, p.post_id AS post_id, p.original_text AS original_text,
                       t.translated_text AS translated_text, t.status AS status, t.provider AS provider,
                       t.ai_pending_since AS ai_pending_since, t.ai_error AS ai_error
                FROM %i p
                JOIN %i wp ON wp.ID = p.post_id
                LEFT JOIN %i t ON t.original_hash = p.original_hash AND t.lang_code = %s
                WHERE wp.post_type = 'attachment' AND wp.post_mime_type LIKE 'image/%%'
                      AND FIND_IN_SET(wp.post_status, %s)
                      AND p.status = %d
                      AND (%s = '' OR p.original_text LIKE %s OR t.translated_text LIKE %s)
                      AND (%s = '' OR FIND_IN_SET(IF(t.id IS NULL, 'not_translated', IF(t.provider IS NULL, 'manual', 'ai')), %s))
                      AND (%s = '' OR FIND_IN_SET(IF(CHAR_LENGTH(p.original_text) <= 80, 'short', IF(CHAR_LENGTH(p.original_text) <= 300, 'medium', 'long')), %s)
                           OR (FIND_IN_SET('length_mismatch', %s) AND t.translated_text IS NOT NULL
                               AND CHAR_LENGTH(p.original_text) > 0 AND CHAR_LENGTH(t.translated_text) > 0
                               AND ABS(CHAR_LENGTH(t.translated_text) - CHAR_LENGTH(p.original_text)) > GREATEST(CHAR_LENGTH(p.original_text) * 0.3, 15)))
                ORDER BY wp.post_title, CASE p.field WHEN 'post_title' THEN 1 WHEN 'name' THEN 1 WHEN 'post_content' THEN 2 WHEN 'description' THEN 2 WHEN 'post_excerpt' THEN 3 ELSE 4 END, p.field, p.id";

        $args = array_merge([$langCode, ScannablePostStatuses::csv(), TranslationStatus::SOURCE_DISCOVERED], $filterArgs);

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table names come from $wpdb and the plugin's schema map; every value goes through a placeholder.
        $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, $parent, $posts, $child, ...$args), ARRAY_A);
        // phpcs:enable

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

        $filterArgs = $this->filterArgs($search, $filters, false);

        $sql = "SELECT p.id AS id, p.meta_key AS meta_key, p.post_id AS post_id, p.original_text AS original_text,
                       t.translated_text AS translated_text, t.status AS status, t.provider AS provider,
                       t.ai_pending_since AS ai_pending_since, t.ai_error AS ai_error
                FROM %i p
                JOIN %i wp ON wp.ID = p.post_id
                LEFT JOIN %i t ON t.parent_id = p.id AND t.lang_code = %s
                WHERE wp.post_type = 'attachment' AND wp.post_mime_type LIKE 'image/%%'
                      AND FIND_IN_SET(wp.post_status, %s)
                      AND p.status = %d
                      AND (%s = '' OR p.original_text LIKE %s OR t.translated_text LIKE %s)
                      AND (%s = '' OR FIND_IN_SET(IF(t.id IS NULL, 'not_translated', IF(t.provider IS NULL, 'manual', 'ai')), %s))
                      AND (%s = '' OR FIND_IN_SET(IF(CHAR_LENGTH(p.original_text) <= 80, 'short', IF(CHAR_LENGTH(p.original_text) <= 300, 'medium', 'long')), %s)
                           OR (FIND_IN_SET('length_mismatch', %s) AND t.translated_text IS NOT NULL
                               AND CHAR_LENGTH(p.original_text) > 0 AND CHAR_LENGTH(t.translated_text) > 0
                               AND ABS(CHAR_LENGTH(t.translated_text) - CHAR_LENGTH(p.original_text)) > GREATEST(CHAR_LENGTH(p.original_text) * 0.3, 15)))
                ORDER BY wp.post_title, p.id";

        $args = array_merge([$langCode, ScannablePostStatuses::csv(), TranslationStatus::SOURCE_DISCOVERED], $filterArgs);

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table names come from $wpdb and the plugin's schema map; every value goes through a placeholder.
        $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, $parent, $posts, $child, ...$args), ARRAY_A);
        // phpcs:enable

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

        $filterArgs = $this->filterArgs($search, $filters, false);

        $sql = "SELECT COUNT(*)           AS total,
                       SUM(t.translated_text IS NOT NULL AND t.status = -1)  AS needs_review,
                       SUM(t.translated_text IS NOT NULL AND t.status != -1) AS translated,
                       SUM(t.id IS NOT NULL AND t.translated_text IS NULL AND t.ai_pending_since IS NOT NULL) AS pending,
                       SUM(t.id IS NULL OR (t.translated_text IS NULL AND t.ai_pending_since IS NULL))        AS missing
                FROM %i p
                LEFT JOIN %i t ON t.original_hash = p.original_hash AND t.lang_code = %s
                WHERE p.status = %d
                AND (%s = '' OR p.original_text LIKE %s OR t.translated_text LIKE %s)
                AND (%s = '' OR FIND_IN_SET(IF(t.id IS NULL, 'not_translated', IF(t.provider IS NULL, 'manual', 'ai')), %s))
                AND (%s = '' OR FIND_IN_SET(IF(CHAR_LENGTH(p.original_text) <= 80, 'short', IF(CHAR_LENGTH(p.original_text) <= 300, 'medium', 'long')), %s)
                     OR (FIND_IN_SET('length_mismatch', %s) AND t.translated_text IS NOT NULL
                         AND CHAR_LENGTH(p.original_text) > 0 AND CHAR_LENGTH(t.translated_text) > 0
                         AND ABS(CHAR_LENGTH(t.translated_text) - CHAR_LENGTH(p.original_text)) > GREATEST(CHAR_LENGTH(p.original_text) * 0.3, 15)))";

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB, WordPress.DB.PreparedSQL -- table names come from $wpdb and the plugin's schema map; every value goes through a placeholder.
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            $sql,
            $parent,
            $child,
            $langCode,
            TranslationStatus::SOURCE_DISCOVERED,
            ...$filterArgs
        ), ARRAY_A) ?: [];
        // phpcs:enable

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

        $filterArgs = $this->filterArgs($search, $filters, false);

        $sql = "SELECT p.id AS id, p.selector AS selector, p.source_url AS source_url, p.original_text AS original_text,
                       LOWER(HEX(p.original_hash)) AS original_hash,
                       t.translated_text AS translated_text, t.status AS status, t.provider AS provider,
                       t.ai_pending_since AS ai_pending_since, t.ai_error AS ai_error
                FROM %i p
                LEFT JOIN %i t ON t.original_hash = p.original_hash AND t.lang_code = %s
                WHERE p.status = %d
                AND (%s = '' OR p.original_text LIKE %s OR t.translated_text LIKE %s)
                AND (%s = '' OR FIND_IN_SET(IF(t.id IS NULL, 'not_translated', IF(t.provider IS NULL, 'manual', 'ai')), %s))
                AND (%s = '' OR FIND_IN_SET(IF(CHAR_LENGTH(p.original_text) <= 80, 'short', IF(CHAR_LENGTH(p.original_text) <= 300, 'medium', 'long')), %s)
                     OR (FIND_IN_SET('length_mismatch', %s) AND t.translated_text IS NOT NULL
                         AND CHAR_LENGTH(p.original_text) > 0 AND CHAR_LENGTH(t.translated_text) > 0
                         AND ABS(CHAR_LENGTH(t.translated_text) - CHAR_LENGTH(p.original_text)) > GREATEST(CHAR_LENGTH(p.original_text) * 0.3, 15)))
                ORDER BY p.selector, p.id";

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB, WordPress.DB.PreparedSQL -- table names come from $wpdb and the plugin's schema map; every value goes through a placeholder.
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            $sql,
            $parent,
            $child,
            $langCode,
            $status,
            ...$filterArgs
        ), ARRAY_A);
        // phpcs:enable

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
     * The kind label is bound as a value and the table and field column as %i
     * identifiers. The two meta tables have no original_hash (parent_id kinds
     * never share a translation), hence their NULL.
     */
    public function listIgnoredTexts(string $search = ''): array
    {
        $p    = TranslationMemorySchema::parentTables();
        $like = '%' . $this->wpdb->esc_like($search) . '%';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table names come from $wpdb and the plugin's schema map; every value goes through a placeholder.
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT %s AS kind, id, original_text, LOWER(HEX(original_hash)) AS original_hash, %i AS field,
                       NULL AS translated_text, NULL AS status, NULL AS provider, NULL AS ai_pending_since, NULL AS ai_error
                FROM %i WHERE status = %d AND (%s = '' OR original_text LIKE %s)
                UNION ALL
                SELECT %s AS kind, id, original_text, NULL AS original_hash, %i AS field,
                       NULL AS translated_text, NULL AS status, NULL AS provider, NULL AS ai_pending_since, NULL AS ai_error
                FROM %i WHERE status = %d AND (%s = '' OR original_text LIKE %s)
                UNION ALL
                SELECT %s AS kind, id, original_text, LOWER(HEX(original_hash)) AS original_hash, %i AS field,
                       NULL AS translated_text, NULL AS status, NULL AS provider, NULL AS ai_pending_since, NULL AS ai_error
                FROM %i WHERE status = %d AND (%s = '' OR original_text LIKE %s)
                UNION ALL
                SELECT %s AS kind, id, original_text, NULL AS original_hash, %i AS field,
                       NULL AS translated_text, NULL AS status, NULL AS provider, NULL AS ai_pending_since, NULL AS ai_error
                FROM %i WHERE status = %d AND (%s = '' OR original_text LIKE %s)
                UNION ALL
                SELECT %s AS kind, id, original_text, LOWER(HEX(original_hash)) AS original_hash, %i AS field,
                       NULL AS translated_text, NULL AS status, NULL AS provider, NULL AS ai_pending_since, NULL AS ai_error
                FROM %i WHERE status = %d AND (%s = '' OR original_text LIKE %s)
                UNION ALL
                SELECT %s AS kind, id, original_text, LOWER(HEX(original_hash)) AS original_hash, %i AS field,
                       NULL AS translated_text, NULL AS status, NULL AS provider, NULL AS ai_pending_since, NULL AS ai_error
                FROM %i WHERE status = %d AND (%s = '' OR original_text LIKE %s)
                UNION ALL
                SELECT %s AS kind, id, original_text, LOWER(HEX(original_hash)) AS original_hash, %i AS field,
                       NULL AS translated_text, NULL AS status, NULL AS provider, NULL AS ai_pending_since, NULL AS ai_error
                FROM %i WHERE status = %d AND (%s = '' OR original_text LIKE %s)
                ORDER BY kind, field, id",
            // Per table, in query order: kind, field column, table, status, search switch, LIKE.
            'post', 'field', $p['post'], TranslationStatus::SOURCE_IGNORED, $search, $like,
            'meta', 'meta_key', $p['meta'], TranslationStatus::SOURCE_IGNORED, $search, $like,
            'term', 'field', $p['term'], TranslationStatus::SOURCE_IGNORED, $search, $like,
            'term_meta', 'meta_key', $p['term_meta'], TranslationStatus::SOURCE_IGNORED, $search, $like,
            'option', 'option_name', $p['option'], TranslationStatus::SOURCE_IGNORED, $search, $like,
            'user', 'field', $p['user'], TranslationStatus::SOURCE_IGNORED, $search, $like,
            'html', 'selector', $p['html'], TranslationStatus::SOURCE_IGNORED, $search, $like
        ), ARRAY_A);
        // phpcs:enable

        return array_map(function (array $r): array {
            $unit         = $this->textUnitRow($r);
            $unit['kind'] = (string) $r['kind'];
            return $unit;
        }, (array) $rows);
    }

    /** One language's totals: languageProgressAll() for that language alone. */
    public function languageProgress(string $langCode): array
    {
        $all = $this->languageProgressAll([$langCode])[$langCode];

        return ['total' => $all['total'], 'translated' => $all['translated']];
    }

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
                $sql = "SELECT COUNT(*) FROM %i p
                    JOIN %i wp ON wp.ID = p.post_id
                    WHERE p.status = %d AND FIND_IN_SET(wp.post_status, %s)";
            $tables = [$parent, $posts];
            array_push($args, ScannablePostStatuses::csv());
        } else {
            $sql    = "SELECT COUNT(*) FROM %i p WHERE p.status = %d";
            $tables = [$parent];
        }

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table names come from $wpdb and the plugin's schema map; every value goes through a placeholder.
        return (int) $this->wpdb->get_var($this->wpdb->prepare($sql, ...$tables, ...$args));
        // phpcs:enable
    }

    /**
     * Translated + still-queued source rows in one parent table, grouped by
     * language. The child is unique per (link, lang_code), so each parent row
     * contributes at most one row per language.
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
        // Same TTL the board applies in presentText() via
        // AiQueueConfig::isFreshPending() -- an abandoned stamp must stop
        // counting as queued, or the rail would poll itself forever.
        $fresh  = gmdate('Y-m-d H:i:s', (int) current_time('timestamp') - (AiQueueConfig::PENDING_TTL_HOURS * 3600));
        $status = TranslationStatus::SOURCE_DISCOVERED;
        $live   = ScannablePostStatuses::csv();

        // Four shapes: post and meta rows only count while their WP post is
        // live; hash-link children join on original_hash, parent_id children
        // (meta, term_meta) on parent_id.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table names come from $wpdb and the plugin's schema map; every value goes through a placeholder.
        if ($tableKey === 'post') {
            $rows = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT t.lang_code AS lang_code, SUM(t.translated_text IS NOT NULL) AS done,
                        SUM(t.translated_text IS NULL AND t.ai_pending_since > %s) AS queued
                 FROM %i p
                 JOIN %i wp ON wp.ID = p.post_id
                 JOIN %i t ON t.original_hash = p.original_hash
                 WHERE p.status = %d AND FIND_IN_SET(wp.post_status, %s)
                 GROUP BY t.lang_code",
                $fresh, $parent, $this->wpdb->posts, $child, $status, $live
            ), ARRAY_A);
        } elseif ($tableKey === 'meta') {
            $rows = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT t.lang_code AS lang_code, SUM(t.translated_text IS NOT NULL) AS done,
                        SUM(t.translated_text IS NULL AND t.ai_pending_since > %s) AS queued
                 FROM %i p
                 JOIN %i wp ON wp.ID = p.post_id
                 JOIN %i t ON t.parent_id = p.id
                 WHERE p.status = %d AND FIND_IN_SET(wp.post_status, %s)
                 GROUP BY t.lang_code",
                $fresh, $parent, $this->wpdb->posts, $child, $status, $live
            ), ARRAY_A);
        } elseif ($parentId) {
            $rows = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT t.lang_code AS lang_code, SUM(t.translated_text IS NOT NULL) AS done,
                        SUM(t.translated_text IS NULL AND t.ai_pending_since > %s) AS queued
                 FROM %i p
                 JOIN %i t ON t.parent_id = p.id
                 WHERE p.status = %d
                 GROUP BY t.lang_code",
                $fresh, $parent, $child, $status
            ), ARRAY_A);
        } else {
            $rows = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT t.lang_code AS lang_code, SUM(t.translated_text IS NOT NULL) AS done,
                        SUM(t.translated_text IS NULL AND t.ai_pending_since > %s) AS queued
                 FROM %i p
                 JOIN %i t ON t.original_hash = p.original_hash
                 WHERE p.status = %d
                 GROUP BY t.lang_code",
                $fresh, $parent, $child, $status
            ), ARRAY_A);
        }
        // phpcs:enable

        $out = [];
        foreach ((array) $rows as $r) {
            $out[(string) $r['lang_code']] = [
                'translated' => (int) $r['done'],
                'pending'    => (int) $r['queued'],
            ];
        }

        return $out;
    }

    /**
     * The values for the board's fixed filter block — the three
     * `AND (%s = '' OR …)` lines every filtered query carries after its own
     * WHERE. Values only: the SQL is written out in each query, so nothing a
     * request sends ever becomes SQL text. An empty switch value turns its
     * filter off (`'' = ''` is true, and MySQL drops it before running).
     *
     * Search: the original or the translation contains the term — and, with
     * $postNames, the post's own title or slug, so searching a page's name
     * lists everything on it (attachments excluded: they belong to the Media
     * board). It sits in WHERE, not in the LEFT JOIN's ON: for an untranslated
     * row `t.translated_text LIKE …` is NULL and NULL OR TRUE is TRUE, so a
     * row whose ORIGINAL matches survives and still counts as missing.
     * ponytail: plain LIKE, no FULLTEXT index. A phrase that crosses a codec
     * marker ({1}…{/1}) does not match; revisit only if users hit it.
     *
     * Origin (manual / ai / not_translated) and Text Length (short ≤ 80,
     * medium 81–300, long > 300, length_mismatch — the 30% / 15-char rule of
     * admin-ui/screens/lengthWarning.mjs) are each a comma list for
     * FIND_IN_SET(): values inside one filter are ORed, filters are ANDed.
     *
     * @param array{origin?:string[], text_length?:string[]} $filters
     * @return array<int, string> in placeholder order
     */
    private function filterArgs(string $search, array $filters, bool $postNames): array
    {
        foreach ($filters['origin'] ?? [] as $origin) {
            if (!in_array($origin, ['manual', 'ai', 'not_translated'], true)) {
                throw new \RuntimeException('Unhandled match case: ' . esc_html((string) wp_json_encode($origin)));
            }
        }
        foreach ($filters['text_length'] ?? [] as $length) {
            if (!in_array($length, ['short', 'medium', 'long', 'length_mismatch'], true)) {
                throw new \RuntimeException('Unhandled match case: ' . esc_html((string) wp_json_encode($length)));
            }
        }

        // esc_like neutralizes % _ \ in the TERM; prepare() quotes the whole value.
        $like    = '%' . $this->wpdb->esc_like($search) . '%';
        $origins = implode(',', $filters['origin'] ?? []);
        $lengths = implode(',', $filters['text_length'] ?? []);

        return array_merge(
            [$search, $like, $like],
            $postNames ? [$like, $like] : [],
            [$origins, $origins, $lengths, $lengths, $lengths]
        );
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
