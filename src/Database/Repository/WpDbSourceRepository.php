<?php

namespace LingoWP\Database\Repository;

use LingoWP\Shared\Source\Type\HtmlSource;
use LingoWP\Shared\Text\IdentityKey;
use LingoWP\Shared\Source\SourceRecord;
use LingoWP\Shared\Source\SourceRef;
use LingoWP\Shared\Text\TranslationKey;
use LingoWP\Shared\Text\TranslatableText;
use LingoWP\Shared\Source\TranslationStatus;
use LingoWP\Shared\Parsing\TranslationSubmissionGuard;
use LingoWP\Database\TranslationMemorySchema;
use LingoWP\LocalizationRouting\SlugMap;

/**
 * $wpdb persistence for the per-type content tables — the sole implementation
 * of the translation persistence contract (writes, render reads, board reads,
 * discovery reconciliation), backed by TWO child-key models:
 *
 *   hash-link (post/term/html/option): child keyed
 *     (original_hash, lang_code); a translation is shared by every parent
 *     position with the same text. Render reads it by hash (no parent join);
 *     ignore/delete are text-global.
 *   parent_id (meta): child keyed (parent_id, lang_code); the position
 *     owns its translation. Render reads it by joining the parent.
 *
 * Binary identity columns (every *_hash) travel as hex via UNHEX(%s). Parent
 * rows are addressed for writes by SourceRef ({kind}:{id}); for hash-link types
 * the parent's original_hash is resolved from that id, since the translation is
 * keyed by text, not position.
 *
 * There is no translated_against_hash and no stale parent status: a source-text
 * change deletes the stale parent and seeds the new translation as
 * needs_review. Ignore sets parent.status=-1 AND deletes the children, so render's
 * "no child ⇒ original" covers untranslated and ignored alike. Adding a language
 * creates zero rows. Languages stay option-backed (lang_code is a plain column).
 */
final class WpDbSourceRepository
{
    private const INSERT_CHUNK = 100;
    private const SELECT_CHUNK = 200;

    private static ?self $primed = null;

    private \wpdb $wpdb;
    private WpDbBoardQueries $board;

    public function __construct(\wpdb $wpdb, WpDbBoardQueries $board)
    {
        $this->wpdb  = $wpdb;
        $this->board = $board;
    }

    /**
     * The instance Plugin::boot() primed — the one stable way an add-on plugin
     * (lingowp-acf, lingowp-elementor, …) reaches the repository, via the public
     * lingowp_source_repository() wrapper in functions.php, without constructing
     * its own copy. Falls back to a self-built default (same shape as
     * LanguageLinks::instance()) so code calling this before boot finishes still
     * works, backed by the same $wpdb connection.
     */
    public static function instance(): self
    {
        if (self::$primed instanceof self) {
            return self::$primed;
        }

        global $wpdb;

        return new self($wpdb, new WpDbBoardQueries($wpdb));
    }

    public static function prime(self $instance): void
    {
        self::$primed = $instance;
    }

    // ── discovery write ────────────────────────────────────────────────────────

    /**
     * Upsert parent rows (INSERT IGNORE on uniq_source), grouped by table. A new
     * identity inserts; an existing one is left as-is (identity includes
     * original_hash, so same identity = same text). No child rows are touched.
     *
     * @param SourceRecord[] $records
     * @return int Rows inserted.
     */
    public function upsertSources(array $records): int
    {
        $inserted = 0;
        foreach ($this->groupByTable($records) as $tableKey => $group) {
            $inserted += $this->insertParents($tableKey, $group);
            if ($tableKey === 'html') {
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

        $table = $this->parent('html');
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

    /**
     * Render read for hash-link types (post/term/html): the produced translations
     * for a set of original hashes in one language, from that type's child table.
     * Keyed by lowercase hex(original_hash) => translated_text.
     *
     * @param string   $parentTableKey TranslationMemorySchema key, e.g. 'post'
     * @param string[] $hashes         raw 32-byte original hashes
     * @return array<string, string>
     */
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
                      AND translated_text IS NOT NULL
                      AND original_hash IN ({$placeholders})";

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, ...$args), ARRAY_A);
            foreach ((array) $rows as $row) {
                $out[strtolower((string) $row['h'])] = (string) $row['translated_text'];
            }
        }

        return $out;
    }

    /**
     * Every produced translation for a hash-link type in one language, keyed by
     * lowercase hex(original_hash) => translated_text. For the HTML fallback,
     * where the lookup hashes are only known after walking the page DOM.
     *
     * @return array<string, string>
     */
    public function fetchAllHashTranslations(string $parentTableKey, string $langCode): array
    {
        if ($langCode === '') {
            return [];
        }

        $child = $this->parent($parentTableKey) . '_translation';
        $sql   = "SELECT LOWER(HEX(original_hash)) AS h, translated_text
                  FROM {$child} WHERE lang_code = %s AND translated_text IS NOT NULL";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, $langCode), ARRAY_A);

        $out = [];
        foreach ((array) $rows as $row) {
            $out[strtolower((string) $row['h'])] = (string) $row['translated_text'];
        }

        return $out;
    }


    /**
     * Render read for parent_id types (meta): join each parent lookup
     * (parent.status = 0) to its child for $langCode. Keyed by
     * IdentityKey::of(lookup) => translated_text.
     *
     * @param string                           $parentTableKey e.g. 'meta'
     * @param array<int, array<string,scalar>> $lookups        list of identity-column maps
     * @return array<string, string>
     */
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

            $sql = "SELECT {$select}, tr.translated_text AS test_text
                    FROM {$parent} p
                    INNER JOIN {$child} tr ON tr.parent_id = p.id AND tr.lang_code = %s
                        AND tr.translated_text IS NOT NULL
                    WHERE p.status = 0 AND {$tupleList}";

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, ...$args), ARRAY_A);
            foreach ((array) $rows as $row) {
                $out[$this->rowIdentityKey($columns, $row)] = (string) $row['test_text'];
            }
        }

        return $out;
    }

    /**
     * Render read for parent_id types, widened past a single leaf: unlike
     * fetchParentTranslations(), $lookups omit context_hash (just the owner
     * column + meta_key_hash), so this returns EVERY context row under that
     * location — a render lookup doesn't know in advance whether a value
     * holds one leaf (a plain scalar, context '') or many (a repeater, a JSON
     * blob), so it has to ask for all of them and let the caller decide.
     *
     * @param string                           $parentTableKey e.g. 'meta' or 'term_meta'
     * @param array<int, array<string,scalar>> $lookups        list of {owner col => id, meta_key_hash => raw hash}
     * @return array<string, array<string, string>> IdentityKey(lookup) => [context ('' for none) => translated_text]
     */
    public function fetchMetaLeafTranslations(string $parentTableKey, array $lookups, string $langCode): array
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

            $sql = "SELECT {$select}, p.context AS context, tr.translated_text AS translated_text
                    FROM {$parent} p
                    INNER JOIN {$child} tr ON tr.parent_id = p.id AND tr.lang_code = %s
                        AND tr.translated_text IS NOT NULL
                    WHERE p.status = 0 AND {$tupleList}";

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, ...$args), ARRAY_A);
            foreach ((array) $rows as $row) {
                $identityKey = $this->rowIdentityKey($columns, $row);
                $context     = (string) ($row['context'] ?? '');
                $out[$identityKey][$context] = (string) $row['translated_text'];
            }
        }

        return $out;
    }

    /**
     * Raw stored post-meta value, unserialized exactly as get_post_meta()
     * would return it, bypassing get_post_meta() itself on purpose: calling it
     * from inside StructuredRenderResolver::metadata() — the very filter that
     * intercepts get_post_meta() — would recurse. Only needed for the
     * multi-leaf case (a repeater, a JSON blob); the common single-leaf case
     * never reads the raw value at all.
     *
     * @return mixed null if the key has no stored value.
     */
    public function rawPostMetaValue(int $postId, string $metaKey)
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $raw = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT meta_value FROM {$this->wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
            $postId,
            $metaKey
        ));

        return $raw === null ? null : maybe_unserialize($raw);
    }

    /** The term-meta counterpart of rawPostMetaValue() — same reasoning, same caveat. */
    public function rawTermMetaValue(int $termId, string $metaKey)
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $raw = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT meta_value FROM {$this->wpdb->termmeta} WHERE term_id = %d AND meta_key = %s LIMIT 1",
            $termId,
            $metaKey
        ));

        return $raw === null ? null : maybe_unserialize($raw);
    }

    /**
     * Fallback-chain lookup for parent_id types (meta, term_meta): unlike
     * hash-link children, a parent_id translation table has no original_hash
     * column (its translations are never hash-shared), so a fallback probe
     * needs the parent row ids directly rather than a hash set.
     *
     * @param int[] $parentIds
     * @return array<int, string> parent_id => translated_text
     */
    public function fetchTranslationsByParentIds(array $parentIds, string $langCode, string $tableKey = 'meta'): array
    {
        if ($parentIds === [] || $langCode === '') {
            return [];
        }

        $child = $this->parent($tableKey) . '_translation';
        $out   = [];

        foreach (array_chunk(array_values($parentIds), self::SELECT_CHUNK) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '%d'));
            $args         = array_merge([$langCode], $chunk);

            $sql = "SELECT parent_id, translated_text
                    FROM {$child}
                    WHERE lang_code = %s
                      AND translated_text IS NOT NULL
                      AND parent_id IN ({$placeholders})";

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, ...$args), ARRAY_A);
            foreach ((array) $rows as $row) {
                $out[(int) $row['parent_id']] = (string) $row['translated_text'];
            }
        }

        return $out;
    }

    // ── board reads (admin dashboard) — delegated to WpDbBoardQueries ───────────
    // Same tables, board-shaped read/presentation logic; split out because this
    // class had grown to mix write persistence with that.
    //
    // Board search (the `$search` argument on every read below): a non-empty
    // term matches the source text OR its translation in `$langCode`, case- and
    // accent-insensitively (utf8mb4_unicode_520_ci), with % and _ taken
    // literally. Empty string means no search, and runs exactly the query that
    // shipped before search existed.
    //
    // IMPORTANT — while searching, the counts describe the MATCHING SUBSET, not
    // the collection: `total` is the match count, and translated/needs_review/
    // missing are the status breakdown of the matching rows only. A collection
    // with no matches produces no row at all, which is how the board hides
    // empty groups.

    /**
     * Post-content collections for one language: one row per WP post that has
     * discovered post sources, with per-status counts. Joined to wp_posts for
     * post_type + title — also load-bearing for search: a term matching the post's
     * own title/slug widens the match to the whole post, not just post_type/title
     * display. For the workspace group list (Pages/Posts/CPT).
     *
     * @return array<int, array{post_id:int, post_type:string, post_title:string,
     *   post_mime_type:string, total:int, translated:int, needs_review:int, missing:int}>
     */
    public function listPostCollections(string $langCode, string $search = '', array $filters = []): array
    {
        return $this->board->listPostCollections($langCode, $search, $filters);
    }

    /**
     * The discovered post text units of one WP post for one language, each with
     * its translation (or null when untranslated). For expanding a content group.
     *
     * @return array<int, array{id:int, field:string, original_text:string,
     *   translated_text:?string, status:?int, provider:?string}>
     */
    public function listPostTexts(int $postId, string $langCode, string $search = '', array $filters = []): array
    {
        return $this->board->listPostTexts($postId, $langCode, $search, $filters);
    }

    /**
     * Every discovered post text unit for one language in one query, each with its
     * post_id. Feeds the AI "translate this whole language" scope, which would
     * otherwise ask post-by-post.
     *
     * @return array<int, array{id:int, post_id:int, field:string, original_text:string,
     *   translated_text:?string, status:?int, provider:?string}>
     */
    public function listAllPostTexts(string $langCode): array
    {
        return $this->board->listAllPostTexts($langCode);
    }

    /**
     * The single Site-settings collection (all discovered option sources) with
     * per-status counts for one language.
     *
     * @return array{total:int, translated:int, needs_review:int, missing:int}
     */
    public function optionCollectionSummary(string $langCode, string $search = '', array $filters = []): array
    {
        return $this->board->optionCollectionSummary($langCode, $search, $filters);
    }

    /**
     * The discovered option text units for one language, each with its
     * translation (or null). For expanding the Site-settings group.
     *
     * @return array<int, array{id:int, option_name:string, original_text:string,
     *   translated_text:?string, status:?int, provider:?string}>
     */
    public function listOptionTexts(string $langCode, string $search = '', array $filters = []): array
    {
        return $this->board->listOptionTexts($langCode, $search, $filters);
    }

    /**
     * The single Author-bios collection (all discovered user sources) with
     * per-status counts for one language.
     *
     * @return array{total:int, translated:int, needs_review:int, missing:int}
     */
    public function userCollectionSummary(string $langCode, string $search = '', array $filters = []): array
    {
        return $this->board->userCollectionSummary($langCode, $search, $filters);
    }

    /**
     * The discovered author-bio text units for one language, each with its
     * translation (or null). For expanding the Author-bios group.
     *
     * @return array<int, array{id:int, user_id:int, display_name:string, original_text:string,
     *   translated_text:?string, status:?int, provider:?string}>
     */
    public function listUserTexts(string $langCode, string $search = '', array $filters = []): array
    {
        return $this->board->listUserTexts($langCode, $search, $filters);
    }

    /**
     * Taxonomy collections (grouped by term) with per-status counts for one
     * language, joined to wp_term_taxonomy + wp_terms for the term name.
     *
     * @return array<int, array{term_taxonomy_id:int, taxonomy:string, name:string,
     *   total:int, translated:int, needs_review:int, missing:int}>
     */
    public function listTermCollections(string $langCode, string $search = '', array $filters = []): array
    {
        return $this->board->listTermCollections($langCode, $search, $filters);
    }

    /**
     * The discovered term text units of one term for one language.
     *
     * Takes no `$search`: the board expands taxonomies whole via listTaxonomyTexts,
     * and this one exists only to feed AI translation of a single term.
     *
     * @return array<int, array{id:int, field:string, original_text:string,
     *   translated_text:?string, status:?int, provider:?string}>
     */
    public function listTermTexts(int $termTaxonomyId, string $langCode): array
    {
        return $this->board->listTermTexts($termTaxonomyId, $langCode);
    }

    /**
     * The discovered term text units of every term in one taxonomy for one
     * language, ordered by term name then field.
     *
     * @return array<int, array{id:int, field:string, original_text:string,
     *   translated_text:?string, status:?int, provider:?string}>
     */
    public function listTaxonomyTexts(string $taxonomy, string $langCode, string $search = '', array $filters = []): array
    {
        return $this->board->listTaxonomyTexts($taxonomy, $langCode, $search, $filters);
    }

    /**
     * Post-meta per-status counts grouped by WP post (joined to wp_posts) for one
     * language. Merged into each post's content collection alongside post — the
     * wp_posts join is also load-bearing for search, same as listPostCollections().
     *
     * @return array<int, array{post_id:int, post_type:string, post_title:string,
     *   post_mime_type:string, total:int, translated:int, needs_review:int, missing:int}>
     */
    public function postMetaCounts(string $langCode, string $search = '', array $filters = []): array
    {
        return $this->board->postMetaCounts($langCode, $search, $filters);
    }

    /**
     * The discovered meta text units of one post for one language.
     *
     * @return array<int, array{id:int, meta_key:string, original_text:string,
     *   translated_text:?string, status:?int, provider:?string}>
     */
    public function listMetaTexts(int $postId, string $langCode, string $search = '', array $filters = []): array
    {
        return $this->board->listMetaTexts($postId, $langCode, $search, $filters);
    }

    /**
     * Every discovered post-meta text unit for one language in one query, each with
     * its post_id. The listAllPostTexts() counterpart for meta.
     *
     * @return array<int, array{id:int, post_id:int, field:string, original_text:string,
     *   translated_text:?string, status:?int, provider:?string}>
     */
    public function listAllMetaTexts(string $langCode): array
    {
        return $this->board->listAllMetaTexts($langCode);
    }

    /**
     * Term-meta per-status counts grouped by taxonomy for one language. Merged
     * into each taxonomy's collection alongside term name/description.
     *
     * @return array<int, array{taxonomy:string, total:int, translated:int, needs_review:int, missing:int}>
     */
    public function termMetaCounts(string $langCode, string $search = '', array $filters = []): array
    {
        return $this->board->termMetaCounts($langCode, $search, $filters);
    }

    /**
     * The discovered term-meta text units of one taxonomy for one language.
     *
     * @return array<int, array{id:int, term_id:int, meta_key:string, original_text:string,
     *   translated_text:?string, status:?int, provider:?string}>
     */
    public function listTermMetaTexts(string $taxonomy, string $langCode, string $search = '', array $filters = []): array
    {
        return $this->board->listTermMetaTexts($taxonomy, $langCode, $search, $filters);
    }

    /**
     * The discovered post text units (title, and post_content's
     * paragraph-level units) of every image attachment for one language,
     * across ALL matching posts at once — unlike listPostTexts(), which is
     * scoped to one post. Each row carries post_id, since the Media
     * collection has no other way to resolve which image a row belongs to
     * (for the thumbnail).
     *
     * @return array<int, array{id:int, field:string, post_id:int, original_text:string,
     *   translated_text:?string, status:?int, provider:?string}>
     */
    public function listMediaPostTexts(string $langCode, string $search = '', array $filters = []): array
    {
        return $this->board->listMediaPostTexts($langCode, $search, $filters);
    }

    /**
     * The discovered meta ALT text of every image attachment for one
     * language, across ALL matching posts at once — the meta counterpart of
     * listMediaPostTexts().
     *
     * @return array<int, array{id:int, field:string, post_id:int, original_text:string,
     *   translated_text:?string, status:?int, provider:?string}>
     */
    public function listMediaAltTexts(string $langCode, string $search = '', array $filters = []): array
    {
        return $this->board->listMediaAltTexts($langCode, $search, $filters);
    }

    /**
     * The single Template-content collection (all discovered html block units)
     * with per-status counts for one language. html has no post link, so every
     * marked block shares one bucket.
     *
     * @return array{total:int, translated:int, needs_review:int, missing:int}
     */
    public function htmlCollectionSummary(string $langCode, string $search = '', array $filters = []): array
    {
        return $this->board->htmlCollectionSummary($langCode, $search, $filters);
    }

    /**
     * The discovered html text units for one language (field = selector).
     *
     * @return array<int, array{id:int, field:string, original_text:string,
     *   translated_text:?string, status:?int, provider:?string}>
     */
    public function listHtmlTexts(string $langCode, string $search = '', array $filters = []): array
    {
        return $this->board->listHtmlTexts($langCode, $search, $filters);
    }

    /**
     * html rows flagged SOURCE_STALE (see flagStaleHtmlSources()) — nobody has
     * re-encountered them via marking mode or the background crawl in the grace
     * window. Same shape as listHtmlTexts(), for the admin review-before-delete
     * surface.
     *
     * @return array<int, array{id:int, field:string, original_text:string,
     *   translated_text:?string, status:?int, provider:?string}>
     */
    public function listStaleHtmlSources(string $langCode, string $search = '', array $filters = []): array
    {
        return $this->board->listStaleHtmlSources($langCode, $search, $filters);
    }

    /**
     * Every ignored source across all parent tables, for the synthetic
     * "ignored:all" board collection. Language-global (ignore deletes children).
     */
    public function listIgnoredTexts(string $search = ''): array
    {
        return $this->board->listIgnoredTexts($search);
    }

    /**
     * Aggregate translation progress for one language across the content tables
     * (post, meta, term, option, html) — for the rail's per-language percentage.
     *
     * @return array{total:int, translated:int}
     */
    public function languageProgress(string $langCode): array
    {
        return $this->board->languageProgress($langCode);
    }

    /**
     * languageProgress() for every language in one pass -- what the language rail
     * needs. Flat in the number of languages; the per-language call is 7 table
     * aggregates each.
     *
     * @param string[] $langCodes
     * @return array<string, array{total:int, translated:int}>
     */
    public function languageProgressAll(array $langCodes): array
    {
        return $this->board->languageProgressAll($langCodes);
    }

    /**
     * Refresh last_seen_at for every hash a discovery pass resolved that has a
     * html row (see HtmlDiscovery::discover()) — both freshly inserted rows
     * and ones already owned by html. A hash belonging to another parent
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

        $parent       = $this->parent('html');
        $hexes        = array_map('bin2hex', array_values(array_unique($rawHashes)));
        $placeholders = implode(', ', array_fill(0, count($hexes), 'UNHEX(%s)'));

        $sql = "UPDATE {$parent} SET last_seen_at = %s WHERE original_hash IN ({$placeholders})";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $this->wpdb->query($this->wpdb->prepare($sql, current_time('mysql'), ...$hexes));
    }

    /**
     * Flag html rows nobody has re-encountered (marking mode or the crawl)
     * in over $graceDays as stale, for admin review — never an automatic
     * delete, since a bounded crawl scope or a single fetch failure can make a
     * still-valid row look absent without it actually being gone.
     *
     * @return int rows flagged
     */
    public function flagStaleHtmlSources(int $graceDays): int
    {
        $parent = $this->parent('html');

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
     * Hard-delete confirmed-stale html rows. Only ever acts on rows already
     * flagged SOURCE_STALE, never anything else. No child-translation cleanup
     * needed: html_translation has no FK to its parent (hash-link, shared by
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

        $parent       = $this->parent('html');
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


    /** Original text of one discovered source by ref, or null if gone/ignored. */
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
     * @return array{text: string, kind: string, meta_key: ?string, field: ?string, post_id: ?int}|null
     */
    public function loadSourceValidationContext(SourceRef $ref): ?array
    {
        $parent = $this->parent($ref->parentTableKey());
        // Every parent_id table (meta, term_meta) has a meta_key column, used by
        // TranslationSubmissionGuard's per-key grammar; 'field'/'post_id' only
        // exist on post, used to tell a post_content paragraph apart from
        // post_title/post_excerpt.
        $isMeta = in_array($ref->parentTableKey(), TranslationMemorySchema::PARENT_ID_CHILD_FKS, true);
        $isPost = $ref->kind === 'post';
        $selected = $isMeta ? 'original_text, meta_key' : ($isPost ? 'original_text, field, post_id' : 'original_text');

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
            'field'    => $isPost ? (string) ($row['field'] ?? '') : null,
            'post_id'  => $isPost ? (int) ($row['post_id'] ?? 0) : null,
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

        $parent = $this->parent('meta');
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



    /**
     * Whether this post has AT LEAST ONE translated unit (post field or meta)
     * in this language — any status counts (machine/reviewed/needs_review all
     * render, per the same "any child renders" rule the rest of the render
     * path already follows). For goals-seo-overview.md §5.3: a page with zero
     * translated content in the visitor's language is treated as not-yet-
     * started; the moment this returns true, it's "normal" again.
     */
    public function hasAnyTranslation(int $postId, string $langCode): bool
    {
        return $this->existsTranslatedChild('post', false, $postId, $langCode)
            || $this->existsTranslatedChild('meta', true, $postId, $langCode);
    }

    /** Cheap EXISTS check: does $postId have at least one translated child row in $langCode for this table? */
    private function existsTranslatedChild(string $tableKey, bool $parentId, int $postId, string $langCode): bool
    {
        $parent = $this->parent($tableKey);
        $child  = $parent . '_translation';
        $join   = $parentId ? 't.parent_id = p.id' : 't.original_hash = p.original_hash';

        $sql = "SELECT EXISTS(
                    SELECT 1 FROM {$parent} p
                    JOIN {$child} t ON {$join} AND t.lang_code = %s AND t.translated_text IS NOT NULL
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

    /**
     * Create or update one translation for the parent addressed by $ref.
     * hash-link: keyed (parent.original_hash, lang) — shared across positions.
     * parent_id: keyed (parent.id, lang). No-op if the parent row is gone.
     *
     * Returns false when the database refused the write, or when a hash-link
     * parent is gone; true otherwise. Callers that don't need it may ignore it.
     */
    public function saveTranslation(SourceRef $ref, string $langCode, string $text, int $status, ?string $provider): bool
    {
        $tableKey = $ref->parentTableKey();

        if ($this->isHashLink($tableKey)) {
            $hashHex = $this->parentHashHex($ref);
            if ($hashHex === null) {
                return false;
            }
            $saved = $this->saveTranslationByHash($tableKey, $hashHex, $langCode, $text, $status, $provider);
            $this->flushHtmlCache($ref, $langCode);
            return $saved;
        }

        $child = $this->parent($tableKey) . '_translation';
        $now   = current_time('mysql');

        // Clearing ai_pending_since/ai_error here means a successful AI apply
        // (queue delivery) and a manual save both land on the same "this
        // unit is settled, nothing pending" state in one statement.
        $update = 'ON DUPLICATE KEY UPDATE translated_text = VALUES(translated_text),
                       status = VALUES(status), provider = VALUES(provider), updated_at = VALUES(updated_at),
                       ai_pending_since = NULL, ai_error = NULL';

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
        return $this->wpdb->query($this->wpdb->prepare($sql, ...$args)) !== false;
    }

    /** Hash-link upsert, shared by saveTranslation() (ref-driven) and the AI
     * queue's applyQueueResult() (hash-driven, no ref at all — see below).
     * False when the database refused the write. */
    private function saveTranslationByHash(string $tableKey, string $hashHex, string $langCode, string $text, int $status, ?string $provider): bool
    {
        $child = $this->parent($tableKey) . '_translation';
        $now   = current_time('mysql');

        $providerExpr = $provider === null ? 'NULL' : '%s';
        $args         = [$hashHex, $langCode, $text, $status];
        if ($provider !== null) {
            $args[] = $provider;
        }
        $args[] = $now;
        $args[] = $now;

        $sql = "INSERT INTO {$child}
                    (original_hash, lang_code, translated_text, status, provider, created_at, updated_at)
                VALUES (UNHEX(%s), %s, %s, %d, {$providerExpr}, %s, %s)
                ON DUPLICATE KEY UPDATE translated_text = VALUES(translated_text),
                    status = VALUES(status), provider = VALUES(provider), updated_at = VALUES(updated_at),
                    ai_pending_since = NULL, ai_error = NULL";

        // Captured before bumpSlugMapForHash(), whose own query would replace
        // $wpdb->last_error and hide a failed write.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $saved = $this->wpdb->query($this->wpdb->prepare($sql, ...$args)) !== false;

        // The one writer a manual save, an AI queue delivery and a bulk apply
        // all pass through for the hash-link tables, so this is where an edited
        // title reaches the URL instead of waiting out the map's cache.
        $this->bumpSlugMapForHash($tableKey, $hashHex);

        return $saved;
    }

    /**
     * Invalidate the slug map, but only when this hash belongs to a
     * post_title / term name row — the only translations a URL slug is derived
     * from.
     *
     * Keyed on the FIELD, not the table: the post table also holds every
     * post_content fragment and every excerpt, so a table-level check made
     * translating one long article invalidate every language's map once per
     * fragment. idx_hash makes the check an index lookup.
     */
    private function bumpSlugMapForHash(string $tableKey, ?string $hashHex): void
    {
        if ($hashHex === null) {
            return;
        }

        $field = ['post' => 'post_title', 'term' => 'name'][$tableKey] ?? null;
        if ($field === null) {
            return;
        }

        $parent = $this->parent($tableKey);

        $owns = $this->wpdb->get_var( // phpcs:ignore WordPress.DB
            $this->wpdb->prepare(
                "SELECT EXISTS(SELECT 1 FROM {$parent} WHERE original_hash = UNHEX(%s) AND field = %s)",
                $hashHex,
                $field
            )
        );

        if ((int) $owns === 1) {
            SlugMap::bumpVersion();
        }
    }

    // ── AI translate-all queue ──────────────────────────────────────────────────
    // Submit stamps ai_pending_since (ref-driven, since a submission always
    // starts from concretely-resolved units); the return trip is hash-driven
    // only (see decision log: the backend is source-type-agnostic and only
    // ever hands back {source_hash, target_lang, output|error}), so applying
    // a result never parses a ref at all.

    /**
     * Whether ANY child row, across every content table, is still waiting on
     * the queue (ai_pending_since set — regardless of freshness: even a
     * TTL-stale stamp means the row was never collected/acked, so it's
     * still worth asking the backend). Local-only, no HTTP — the point is
     * to let a caller skip the pull round trip entirely when there is
     * nothing to collect, which is the common case on most page loads.
     * idx_ai_pending makes each check an index lookup, not a scan.
     */
    public function hasAnyPending(): bool
    {
        foreach (TranslationMemorySchema::HASH_LINK_CHILDREN as $tableKey) {
            $child = $this->parent($tableKey) . '_translation';
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            if ($this->wpdb->get_var("SELECT EXISTS(SELECT 1 FROM {$child} WHERE ai_pending_since IS NOT NULL)")) { // nosemgrep: wpdb-interpolated-sql -- table name only, from TranslationMemorySchema::parentTables(); no values interpolated
                return true;
            }
        }

        foreach (array_values(TranslationMemorySchema::PARENT_ID_CHILD_FKS) as $tableKey) {
            $child = $this->parent($tableKey) . '_translation';
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            if ($this->wpdb->get_var("SELECT EXISTS(SELECT 1 FROM {$child} WHERE ai_pending_since IS NOT NULL)")) { // nosemgrep: wpdb-interpolated-sql -- table name only, from TranslationMemorySchema::parentTables(); no values interpolated
                return true;
            }
        }

        return false;
    }

    /**
     * True mutex via MySQL's own named lock, timeout 0 (never wait -- a
     * losing caller just skips its pull this time, the next poll tries
     * again). Replaces an earlier transient-based throttle: get_transient()
     * + set_transient() is a read-then-write against wp_options, not
     * atomic, so two genuinely concurrent requests (index() and texts()
     * firing together) could both pass the check before either set the
     * lock -- this can't happen with a real DB-level lock.
     */
    public function tryAcquireAiPullLock(): bool
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        return (int) $this->wpdb->get_var("SELECT GET_LOCK('lingowp_ai_pull', 0)") === 1;
    }

    public function releaseAiPullLock(): void
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $this->wpdb->query("SELECT RELEASE_LOCK('lingowp_ai_pull')");
    }

    /**
     * Stamp ai_pending_since (and clear any stale ai_error) on every
     * (ref, lang) pair about to be submitted. Batched per table per
     * language — one statement per (table, lang), not per unit.
     *
     * @param SourceRef[] $refs
     * @param string[]    $langs
     */
    public function markPendingBatch(array $refs, array $langs): void
    {
        if ($refs === [] || $langs === []) {
            return;
        }

        $now = current_time('mysql');
        foreach ($this->refsByTable($refs) as $tableKey => $ids) {
            $child = $this->parent($tableKey) . '_translation';

            foreach (array_chunk(array_unique($ids), self::SELECT_CHUNK) as $chunk) {
                $placeholders = implode(', ', array_fill(0, count($chunk), '%d'));

                foreach ($langs as $lang) {
                    if ($this->isHashLink($tableKey)) {
                        $sql = "INSERT INTO {$child} (original_hash, lang_code, ai_pending_since, created_at, updated_at)
                                SELECT original_hash, %s, %s, %s, %s
                                FROM {$this->parent($tableKey)} WHERE id IN ({$placeholders})
                                ON DUPLICATE KEY UPDATE ai_pending_since = VALUES(ai_pending_since),
                                    ai_error = NULL, updated_at = VALUES(updated_at)";
                    } else {
                        $sql = "INSERT INTO {$child} (parent_id, lang_code, ai_pending_since, created_at, updated_at)
                                SELECT id, %s, %s, %s, %s
                                FROM {$this->parent($tableKey)} WHERE id IN ({$placeholders})
                                ON DUPLICATE KEY UPDATE ai_pending_since = VALUES(ai_pending_since),
                                    ai_error = NULL, updated_at = VALUES(updated_at)";
                    }

                    $args = array_merge([$lang, $now, $now, $now], $chunk);
                    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
                    $this->wpdb->query($this->wpdb->prepare($sql, ...$args));
                }
            }
        }
    }

    /**
     * Apply one pulled queue result, source-agnostic: the same hash may
     * independently exist in more than one parent table (e.g. an option value
     * and an unrelated post fragment sharing text), and every match gets it.
     * For the parent_id tables (meta, term_meta) specifically, several distinct
     * positions can share a hash (never hash-shared for MANUAL translation, by
     * design — see the class doc) but the async queue dedupes site-wide by
     * text, so one AI translation is deliberately fanned out to every position
     * sharing it too.
     *
     * ponytail: one lookup per parent table per call, not batched across a
     * whole pulled page. Revisit if a pull page's typical result count ever
     * makes this a measurable hot path.
     *
     * $hashHex is original_hash, exactly as stored on the parent -- the
     * backend hashes on the same normalized form (see
     * services/translation_queue/wp_text_hash.py, the Python port of
     * StringNormalizer::normalize()), so no separate "queue hash" is needed.
     */
    public function applyQueueResult(string $hashHex, string $langCode, ?string $translatedText, ?string $errorMessage): void
    {
        foreach (TranslationMemorySchema::HASH_LINK_CHILDREN as $tableKey) {
            $parent = $this->parent($tableKey);
            $row    = $this->wpdb->get_row( // phpcs:ignore WordPress.DB
                $this->wpdb->prepare("SELECT original_text FROM {$parent} WHERE original_hash = UNHEX(%s) LIMIT 1", $hashHex),
                ARRAY_A
            );
            if ($row === null) {
                continue;
            }
            $this->applyResultToPosition($tableKey, $hashHex, null, $langCode, (string) $row['original_text'], null, $translatedText, $errorMessage);
        }

        foreach (array_values(TranslationMemorySchema::PARENT_ID_CHILD_FKS) as $tableKey) {
            $parent = $this->parent($tableKey);
            $rows   = $this->wpdb->get_results( // phpcs:ignore WordPress.DB
                $this->wpdb->prepare("SELECT id, meta_key, original_text FROM {$parent} WHERE original_hash = UNHEX(%s)", $hashHex),
                ARRAY_A
            );
            foreach ((array) $rows as $row) {
                $this->applyResultToPosition(
                    $tableKey, null, (int) $row['id'], $langCode,
                    (string) $row['original_text'], (string) $row['meta_key'],
                    $translatedText, $errorMessage
                );
            }
        }
    }

    /** One position's share of applyQueueResult() — validated write, or a failure marker. */
    private function applyResultToPosition(
        string $tableKey,
        ?string $hashHex,
        ?int $parentId,
        string $langCode,
        string $originalText,
        ?string $metaKey,
        ?string $translatedText,
        ?string $errorMessage
    ): void {
        if ($errorMessage !== null || $translatedText === null || $translatedText === '') {
            $this->markQueueFailure($tableKey, $hashHex, $parentId, $langCode, $errorMessage ?? 'ERR_BACKEND_EMPTY_RESULT');
            return;
        }

        $guardErrors = TranslationSubmissionGuard::validate($originalText, $translatedText, $metaKey);
        if ($guardErrors !== []) {
            $this->markQueueFailure($tableKey, $hashHex, $parentId, $langCode, 'ERR_PROTECTED_TOKEN_STRUCTURE');
            return;
        }

        if ($this->isHashLink($tableKey)) {
            $this->saveTranslationByHash($tableKey, (string) $hashHex, $langCode, $translatedText, TranslationStatus::NEEDS_REVIEW, 'ai');
            $this->flushHtmlCacheForLang($tableKey, $langCode);
            return;
        }

        $this->saveTranslation(SourceRef::make('meta', (int) $parentId), $langCode, $translatedText, TranslationStatus::NEEDS_REVIEW, 'ai');
    }

    /** Sets ai_error and clears ai_pending_since; never touches translated_text/status. */
    private function markQueueFailure(string $tableKey, ?string $hashHex, ?int $parentId, string $langCode, string $errorMessage): void
    {
        $child = $this->parent($tableKey) . '_translation';
        $now   = current_time('mysql');

        if ($this->isHashLink($tableKey)) {
            $sql = "INSERT INTO {$child} (original_hash, lang_code, ai_error, created_at, updated_at)
                    VALUES (UNHEX(%s), %s, %s, %s, %s)
                    ON DUPLICATE KEY UPDATE ai_error = VALUES(ai_error), ai_pending_since = NULL, updated_at = VALUES(updated_at)";
            $args = [$hashHex, $langCode, $errorMessage, $now, $now];
        } else {
            $sql = "INSERT INTO {$child} (parent_id, lang_code, ai_error, created_at, updated_at)
                    SELECT id, %s, %s, %s, %s FROM {$this->parent($tableKey)} WHERE id = %d
                    ON DUPLICATE KEY UPDATE ai_error = VALUES(ai_error), ai_pending_since = NULL, updated_at = VALUES(updated_at)";
            $args = [$langCode, $errorMessage, $now, $now, $parentId];
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $this->wpdb->query($this->wpdb->prepare($sql, ...$args));
    }

    /** The actual check, by table key rather than SourceRef -- the queue's
     * hash-driven apply path has no ref to construct (SourceRef::make()
     * requires a real, positive id). flushHtmlCache() delegates here. */
    private function flushHtmlCacheForLang(string $tableKey, string $langCode): void
    {
        if (($tableKey === 'html' || $tableKey === 'option') && $langCode !== '' && function_exists('wp_cache_delete')) {
            wp_cache_delete('lingowp:html:' . $langCode, 'lingowp');
        }
    }

    /** Remove the translation for $ref + language (hash-link delete is text-global). */
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
            // A removed title translation must stop producing a URL slug.
            $this->bumpSlugMapForHash($tableKey, $hashHex);
            $this->flushHtmlCache($ref, $langCode);
            return;
        }

        $this->wpdb->query( // phpcs:ignore WordPress.DB
            $this->wpdb->prepare("DELETE FROM {$child} WHERE parent_id = %d AND lang_code = %s", $ref->id, $langCode)
        );
    }


    /**
     * Update only the status of $ref's translation for one language (no text
     * change): mark reviewed (2) or flag needs_review (-1). Both are review
     * states of an existing translation, so the row must already carry one —
     * `translated_text IS NOT NULL` keeps a still-missing text from being
     * flipped straight to reviewed/needs_review. No-op if there is no
     * matching (translated) child row. hash-link updates by original_hash,
     * parent_id by parent id.
     */
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
                    "UPDATE {$child} SET status = %d, updated_at = %s WHERE original_hash = UNHEX(%s) AND lang_code = %s AND translated_text IS NOT NULL",
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
                "UPDATE {$child} SET status = %d, updated_at = %s WHERE parent_id = %d AND lang_code = %s AND translated_text IS NOT NULL",
                $status,
                $now,
                $ref->id,
                $langCode
            )
        );
    }

    /**
     * Apply the same translation to several parents (apply-everywhere). Mostly for
     * parent_id types — hash-link positions already share by text.
     *
     * @param SourceRef[] $refs
     * @return int Translations written.
     */
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
     * Copy every translation that exists in $fromLang but is missing in
     * $toLang, across every content table, marked needs_review — a
     * sibling-region translation should get a quick human check before
     * being treated as final. Existing $toLang translations are never
     * touched or overwritten.
     *
     * @return int rows inserted
     */
    public function copyFallbackTranslations(string $fromLang, string $toLang): int
    {
        if ($fromLang === '' || $toLang === '' || $fromLang === $toLang) {
            return 0;
        }

        $now   = current_time('mysql');
        $count = 0;

        foreach (TranslationMemorySchema::HASH_LINK_CHILDREN as $tableKey) {
            $child = $this->parent($tableKey) . '_translation';
            $sql   = "INSERT IGNORE INTO {$child}
                        (original_hash, lang_code, translated_text, status, provider, created_at, updated_at)
                    SELECT original_hash, %s, translated_text, %d, provider, %s, %s
                    FROM {$child} WHERE lang_code = %s AND translated_text IS NOT NULL";

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            $result = $this->wpdb->query($this->wpdb->prepare(
                $sql,
                $toLang,
                TranslationStatus::NEEDS_REVIEW,
                $now,
                $now,
                $fromLang
            ));
            $count += max(0, (int) $result);
        }

        foreach (array_values(TranslationMemorySchema::PARENT_ID_CHILD_FKS) as $tableKey) {
            $child = $this->parent($tableKey) . '_translation';
            $sql   = "INSERT IGNORE INTO {$child}
                        (parent_id, lang_code, translated_text, status, provider, created_at, updated_at)
                    SELECT parent_id, %s, translated_text, %d, provider, %s, %s
                    FROM {$child} WHERE lang_code = %s AND translated_text IS NOT NULL";

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            $result = $this->wpdb->query($this->wpdb->prepare(
                $sql,
                $toLang,
                TranslationStatus::NEEDS_REVIEW,
                $now,
                $now,
                $fromLang
            ));
            $count += max(0, (int) $result);
        }

        // AFTER the writes, never before. Bulk INSERT..SELECT across every
        // table at once, so there is no single hash to test and the bump is
        // unconditional — but bumping first let a concurrent request build the
        // NEW version's slug map from half-copied rows and cache that for the
        // whole TTL. Every other invalidation in this class already follows
        // its write; this one was the exception.
        SlugMap::bumpVersion();

        return $count;
    }

    /**
     * Whether $fromLang has at least one translation that $toLang is missing —
     * the "is there anything to copy" check behind the rail's "Use fallback
     * translation" button, without actually running the copy. Short-circuits
     * across content tables on the first hit.
     */
    public function hasFallbackTranslationsToCopy(string $fromLang, string $toLang): bool
    {
        if ($fromLang === '' || $toLang === '' || $fromLang === $toLang) {
            return false;
        }

        foreach (TranslationMemorySchema::HASH_LINK_CHILDREN as $tableKey) {
            $child = $this->parent($tableKey) . '_translation';
            $sql   = "SELECT 1 FROM {$child} t1
                    LEFT JOIN {$child} t2 ON t2.original_hash = t1.original_hash AND t2.lang_code = %s
                        AND t2.translated_text IS NOT NULL
                    WHERE t1.lang_code = %s AND t1.translated_text IS NOT NULL AND t2.id IS NULL
                    LIMIT 1";

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            if ($this->wpdb->get_var($this->wpdb->prepare($sql, $toLang, $fromLang)) !== null) {
                return true;
            }
        }

        foreach (array_values(TranslationMemorySchema::PARENT_ID_CHILD_FKS) as $tableKey) {
            $child = $this->parent($tableKey) . '_translation';
            $sql   = "SELECT 1 FROM {$child} t1
                    LEFT JOIN {$child} t2 ON t2.parent_id = t1.parent_id AND t2.lang_code = %s
                        AND t2.translated_text IS NOT NULL
                    WHERE t1.lang_code = %s AND t1.translated_text IS NOT NULL AND t2.id IS NULL
                    LIMIT 1";

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            if ($this->wpdb->get_var($this->wpdb->prepare($sql, $toLang, $fromLang)) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Drop the per-language map PublicRenderPipeline caches under 'lingowp:html:{lang}'
     * (now covering both html and option), so an edited template or option
     * translation shows on the next render instead of waiting out the 300s TTL.
     * No-op for other kinds.
     */
    private function flushHtmlCache(SourceRef $ref, string $langCode): void
    {
        // SourceRef::TABLES is an identity map (kind === parentTableKey), so
        // $ref->kind is exactly the $tableKey flushHtmlCacheForLang() checks.
        $this->flushHtmlCacheForLang($ref->kind, $langCode);
    }

    /** Mark a parent ignored (status -1) AND delete its child(ren) across languages. */
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
            // status = -1 drops the parent out of the slug map's candidates.
            $this->bumpSlugMapForHash($tableKey, $hashHex);
            return;
        }

        $this->wpdb->query( // phpcs:ignore WordPress.DB
            $this->wpdb->prepare("DELETE FROM {$child} WHERE parent_id = %d", $ref->id)
        );
    }

    /**
     * Return ignored parents to discovered (status 0). Children were deleted on
     * ignore, so the string is untranslated until re-translated.
     *
     * @param SourceRef[] $refs
     */
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

            // Restoring puts a title back among the slug map's candidates, the
            // exact mirror of ignoreSource() taking it out — which already
            // invalidates. Without this the translated URL stayed missing for
            // the whole TTL.
            if ($tableKey === 'post' || $tableKey === 'term') {
                SlugMap::bumpVersion();
            }
        }
    }

    /** updated_at of $ref's translation for the concurrent-edit guard, or null. */
    /** Current translated text of $ref in one language, or null when there is none. */
    public function translatedText(SourceRef $ref, string $langCode): ?string
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
                    "SELECT translated_text FROM {$child} WHERE original_hash = UNHEX(%s) AND lang_code = %s",
                    $hashHex,
                    $langCode
                )
            );
        } else {
            $value = $this->wpdb->get_var( // phpcs:ignore WordPress.DB
                $this->wpdb->prepare(
                    "SELECT translated_text FROM {$child} WHERE parent_id = %d AND lang_code = %s",
                    $ref->id,
                    $langCode
                )
            );
        }

        return is_string($value) && $value !== '' ? $value : null;
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

    /**
     * Reconcile a post's parents (post fields + meta) after re-discovery: upsert
     * the current $records, then delete this post's still-discovered parents that
     * are absent from $records. Empty $records deletes all of the post's parents.
     *
     * @param SourceRecord[] $records
     */
    public function reconcilePost(int $postId, array $records): void
    {
        $byTable = $this->groupByTable($records);
        $this->reconcileScope('post', 'post_id = %d', [$postId], $byTable['post'] ?? []);
        $this->reconcileScope('meta', 'post_id = %d', [$postId], $byTable['meta'] ?? []);
    }

    /**
     * Reconcile one post + meta key's parents. Same delete-stale rule, scoped.
     *
     * @param SourceRecord[] $records
     */
    public function reconcilePostMetaKey(int $postId, string $metaKey, array $records): void
    {
        $this->reconcileScope(
            'meta',
            'post_id = %d AND meta_key_hash = UNHEX(%s)',
            [$postId, bin2hex(hash('sha256', $metaKey, true))],
            $records
        );
    }

    /**
     * Reconcile a term's parents.
     *
     * @param SourceRecord[] $records
     */
    public function reconcileTerm(int $termTaxonomyId, array $records): void
    {
        $this->reconcileScope('term', 'term_taxonomy_id = %d', [$termTaxonomyId], $records);
    }

    /**
     * Reconcile one term's meta parents. Same delete-stale rule as post meta,
     * scoped by term_id (not term_taxonomy_id — matches get_term_meta()'s own
     * relationship).
     *
     * @param SourceRecord[] $records
     */
    public function reconcileTermMeta(int $termId, array $records): void
    {
        $this->reconcileScope('term_meta', 'term_id = %d', [$termId], $records);
    }

    /**
     * Reconcile one term + meta key's parents. Same delete-stale rule as
     * reconcilePostMetaKey(), scoped to just this key: for a value that isn't
     * real term meta at all (a plugin's own external representation, synced
     * in under a synthetic key — e.g. lingowp-seo's Yoast term-breadcrumb
     * sync), reconcileTermMeta()'s term_id-only scope would wrongly delete
     * every OTHER key's rows for that same term on every sync.
     *
     * @param SourceRecord[] $records
     */
    public function reconcileTermMetaKey(int $termId, string $metaKey, array $records): void
    {
        $this->reconcileScope(
            'term_meta',
            'term_id = %d AND meta_key_hash = UNHEX(%s)',
            [$termId, bin2hex(hash('sha256', $metaKey, true))],
            $records
        );
    }

    /**
     * Reconcile one wp_options row's parents. Same delete-stale + carry-forward
     * rule as posts/terms, scoped to a single option_id: an edited tagline keeps
     * its translation, re-attached to the new text as needs_review.
     *
     * @param SourceRecord[] $records
     */
    public function reconcileOption(int $optionId, array $records): void
    {
        $this->reconcileScope('option', 'option_id = %d', [$optionId], $records);
    }

    /**
     * Reconcile one user's author-bio parent. Same delete-stale + carry-forward
     * rule as options/terms: an edited bio keeps its translation, re-attached to
     * the new text as needs_review; a cleared bio deletes the parent.
     *
     * @param SourceRecord[] $records
     */
    public function reconcileUser(int $userId, array $records): void
    {
        $this->reconcileScope('user', 'user_id = %d', [$userId], $records);
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
                    FROM {$child} WHERE original_hash = UNHEX(%s) AND translated_text IS NOT NULL";

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            $this->wpdb->query($this->wpdb->prepare($sql, $target['hash'], TranslationStatus::NEEDS_REVIEW, $now, $stale['hash']));
            return;
        }

        $sql = "INSERT IGNORE INTO {$child}
                    (parent_id, lang_code, translated_text, status, provider, created_at, updated_at)
                SELECT %d, lang_code, translated_text, %d, provider, created_at, %s
                FROM {$child} WHERE parent_id = %d AND translated_text IS NOT NULL";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $this->wpdb->query($this->wpdb->prepare($sql, $target['id'], TranslationStatus::NEEDS_REVIEW, $now, $stale['id']));
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private function isHashLink(string $tableKey): bool
    {
        return in_array($tableKey, TranslationMemorySchema::HASH_LINK_CHILDREN, true);
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

    /**
     * Append one cell's placeholder + arg; binary (*_hash) uses UNHEX, NULL is literal.
     *
     * @param mixed $value
     */
    private function cell(string $column, $value, array &$args): string
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
        return array_intersect_key($assoc, array_flip($keys));
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
