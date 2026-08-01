<?php

namespace TransLangs\Core\Repository;
use TransLangs\Core\Source\Value\SourceRef;
use TransLangs\Core\Source\Base\SourceRecord;
use TransLangs\Core\Text\IdentityKey;

/**
 * The v5 translation persistence contract — per-type, with TWO child-key models.
 *
 *   hash-link (post, term, html): child keyed (original_hash, lang_code); a
 *     translation is shared by every parent position with the same text. Render
 *     reads it by hash (no parent join); ignore/delete are text-global.
 *   parent_id (meta, staticText): child keyed (parent_id, lang_code); the
 *     position owns its translation. Render reads it by joining the parent.
 *
 * There is no translated_against_hash and no stale parent status: a source-text
 * change deletes the stale parent and (Phase 5) seeds the new translation as
 * needs_review. Ignore sets parent.status=-1 AND deletes the children, so render's
 * "no child ⇒ original" covers untranslated and ignored alike. Adding a language
 * creates zero rows. Languages stay option-backed (lang_code is a plain column).
 */
interface SourceTranslationRepository
{
    /**
     * Upsert parent rows (INSERT IGNORE on uniq_source), grouped by table. A new
     * identity inserts; an existing one is left as-is (identity includes
     * original_hash, so same identity = same text). No child rows are touched.
     *
     * @param SourceRecord[] $records
     * @return int Rows inserted.
     */
    public function upsertSources(array $records): int;

    /**
     * Discover gettext plural messages: ensure each singular anchor exists in
     * tl_staticText, then link the plural original via tl_staticPluralText.
     *
     * @param array<int, array{domain:string, context:?string, single:string, plural:string}> $candidates
     * @return int Plural rows inserted.
     */
    public function upsertPluralSources(array $candidates): int;

    /**
     * Render read for hash-link types (post/term/html): the produced translations
     * for a set of original hashes in one language, from that type's child table.
     * Keyed by lowercase hex(original_hash) => translated_text.
     *
     * @param string   $parentTableKey TranslationMemorySchema key, e.g. 'tl_post'
     * @param string[] $hashes         raw 32-byte original hashes
     * @return array<string, string>
     */
    public function fetchHashTranslations(string $parentTableKey, array $hashes, string $langCode): array;

    /**
     * Every produced translation for a hash-link type in one language, keyed by
     * lowercase hex(original_hash) => translated_text. For the HTML fallback,
     * where the lookup hashes are only known after walking the page DOM.
     *
     * @return array<string, string>
     */
    public function fetchAllHashTranslations(string $parentTableKey, string $langCode): array;


    /**
     * Render read for parent_id types (meta/staticText): join each parent lookup
     * (parent.status = 0) to its child for $langCode. Keyed by
     * IdentityKey::of(lookup) => translated_text.
     *
     * @param string                           $parentTableKey e.g. 'tl_meta'
     * @param array<int, array<string,scalar>> $lookups        list of identity-column maps
     * @return array<string, string>
     */
    public function fetchParentTranslations(string $parentTableKey, array $lookups, string $langCode): array;

    /**
     * Render read for gettext plurals: every translated form per message in one
     * language, keyed IdentityKey(lookup) => [form_index => translated_text].
     *
     * @param array<int, array<string,scalar>> $lookups singular staticText identities
     * @return array<string, array<int, string>>
     */
    public function fetchPluralForms(array $lookups, string $langCode): array;

    // ── translation writes (admin) ─────────────────────────────────────────────

    // ── board reads (admin dashboard) ──────────────────────────────────────────

    /**
     * Board search (the `$search` argument on every read below).
     *
     * A non-empty term matches the source text OR its translation in `$langCode`,
     * case- and accent-insensitively (utf8mb4_unicode_520_ci), with % and _ taken
     * literally. Empty string means no search, and runs exactly the query that
     * shipped before search existed.
     *
     * IMPORTANT — while searching, the counts describe the MATCHING SUBSET, not the
     * collection: `total` is the match count, and translated/needs_review/missing are
     * the status breakdown of the matching rows only. A collection with no matches
     * produces no row at all, which is how the board hides empty groups.
     */

    /**
     * Post-content collections for one language: one row per WP post that has
     * discovered tl_post sources, with per-status counts. Joined to wp_posts for
     * post_type + title. For the workspace group list (Pages/Posts/CPT).
     *
     * @return array<int, array{post_id:int, post_type:string, post_title:string,
     *   total:int, translated:int, needs_review:int, missing:int}>
     */
    public function listPostCollections(string $langCode, string $search = '', array $filters = []): array;

    /**
     * The discovered tl_post text units of one WP post for one language, each with
     * its translation (or null when untranslated). For expanding a content group.
     *
     * @return array<int, array{id:int, field:string, original_text:string,
     *   translated_text:?string, status:?int, provider:?string}>
     */
    public function listPostTexts(int $postId, string $langCode, string $search = '', array $filters = []): array;

    /**
     * The single Site-settings collection (all discovered tl_option sources) with
     * per-status counts for one language.
     *
     * @return array{total:int, translated:int, needs_review:int, missing:int}
     */
    public function optionCollectionSummary(string $langCode, string $search = '', array $filters = []): array;

    /**
     * The discovered tl_option text units for one language, each with its
     * translation (or null). For expanding the Site-settings group.
     *
     * @return array<int, array{id:int, option_name:string, original_text:string,
     *   translated_text:?string, status:?int, provider:?string}>
     */
    public function listOptionTexts(string $langCode, string $search = '', array $filters = []): array;


    /**
     * Taxonomy collections (grouped by term) with per-status counts for one
     * language, joined to wp_term_taxonomy + wp_terms for the term name.
     *
     * @return array<int, array{term_taxonomy_id:int, taxonomy:string, name:string,
     *   total:int, translated:int, needs_review:int, missing:int}>
     */
    public function listTermCollections(string $langCode, string $search = '', array $filters = []): array;

    /**
     * The discovered tl_term text units of one term for one language.
     *
     * Takes no `$search`: the board expands taxonomies whole via listTaxonomyTexts,
     * and this one exists only to feed AI translation of a single term.
     *
     * @return array<int, array{id:int, field:string, original_text:string,
     *   translated_text:?string, status:?int, provider:?string}>
     */
    public function listTermTexts(int $termTaxonomyId, string $langCode): array;

    /**
     * The discovered tl_term text units of every term in one taxonomy for one
     * language, ordered by term name then field.
     *
     * @return array<int, array{id:int, field:string, original_text:string,
     *   translated_text:?string, status:?int, provider:?string}>
     */
    public function listTaxonomyTexts(string $taxonomy, string $langCode, string $search = '', array $filters = []): array;

    /**
     * Post-meta per-status counts grouped by WP post (joined to wp_posts) for one
     * language. Merged into each post's content collection alongside tl_post.
     *
     * @return array<int, array{post_id:int, post_type:string, post_title:string,
     *   total:int, translated:int, needs_review:int, missing:int}>
     */
    public function postMetaCounts(string $langCode, string $search = '', array $filters = []): array;

    /**
     * The discovered tl_meta text units of one post for one language.
     *
     * @return array<int, array{id:int, meta_key:string, original_text:string,
     *   translated_text:?string, status:?int, provider:?string}>
     */
    public function listMetaTexts(int $postId, string $langCode, string $search = '', array $filters = []): array;

    /**
     * Gettext collections grouped by textdomain (the plugin/theme slug) for one
     * language: one row per raw domain string found in tl_staticText.reference
     * (`domain|context`). For the Plugins & Themes board.
     *
     * @return array<int, array{domain:string, total:int, translated:int,
     *   needs_review:int, missing:int}>
     */
    public function staticTextDomainSummary(string $langCode, string $search = '', array $filters = []): array;

    /**
     * The discovered tl_staticText units for one textdomain, one language.
     * `field` is the gettext context half of `reference` (may be empty). For
     * expanding a Plugins & Themes group.
     *
     * @return array<int, array{id:int, field:string, original_text:string,
     *   translated_text:?string, status:?int, provider:?string}>
     */
    public function listStaticTextDomainTexts(string $domain, string $langCode, string $search = '', array $filters = []): array;


    /**
     * Every unique content text (post/meta/term/option) across the whole
     * site, deduplicated GLOBALLY by original_hash — NOT per table. CSV
     * export is a flat text-to-translation matrix: the client explicitly
     * does not want to know or care whether "Hello world!" lives on 4 posts
     * and 2 terms, only that it is one string needing one translation per
     * language (design-service_export-import.md).
     *
     * Only discovered (non-ignored) parents contribute. When sources sharing
     * a hash disagree — e.g. a post and a term happen to hold the same
     * normalized text with two different French translations, or two
     * postmeta occurrences (meta is never DB-shared, see class docblock)
     * diverge — one wins, deterministically, per source table queried
     * (first-found). This collapsing is deliberate, not a bug: it is what
     * "share the same translation for the same text" means for CSV
     * purposes, and importing a merged row back writes that ONE value to
     * every matching source (see findContentRefsByHash()).
     *
     * @param string[] $langCodes
     * @return array<int, array{original_text:string, translations:array<string,?string>}>
     */
    public function exportTextsForLanguages(array $langCodes): array;

    /**
     * Every currently-discovered SourceRef across the four content tables
     * sharing this original_hash — the CSV import lookup. Hash-link kinds
     * (post/term/option) contribute at most one ref each (any parent with
     * that hash addresses the one shared translation); meta contributes one
     * ref PER occurrence, since meta translations are never shared. Empty
     * when the hash matches nothing currently discovered (e.g. the source
     * text was edited or removed since the CSV was exported).
     *
     * @return SourceRef[]
     */
    public function findContentRefsByHash(string $hashHex): array;


    /**
     * The single Template-content collection (all discovered tl_html block units)
     * with per-status counts for one language. tl_html has no post link, so every
     * marked block shares one bucket.
     *
     * @return array{total:int, translated:int, needs_review:int, missing:int}
     */
    public function htmlCollectionSummary(string $langCode, string $search = '', array $filters = []): array;

    /**
     * The discovered tl_html text units for one language (field = selector).
     *
     * @return array<int, array{id:int, field:string, original_text:string,
     *   translated_text:?string, status:?int, provider:?string}>
     */
    public function listHtmlTexts(string $langCode, string $search = '', array $filters = []): array;

    /**
     * tl_html rows flagged SOURCE_STALE (see flagStaleHtmlSources()) — nobody has
     * re-encountered them via marking mode or the background crawl in the grace
     * window. Same shape as listHtmlTexts(), for the admin review-before-delete
     * surface.
     *
     * @return array<int, array{id:int, field:string, original_text:string,
     *   translated_text:?string, status:?int, provider:?string}>
     */
    public function listStaleHtmlSources(string $langCode, string $search = '', array $filters = []): array;

    /**
     * Refresh last_seen_at on every tl_html row matching one of these original
     * hashes. Called by HtmlDiscovery::discover() after each pass (marking mode
     * or crawl) with every candidate hash it resolved, new or pre-existing.
     *
     * @param string[] $rawHashes
     */
    public function touchHtmlSeen(array $rawHashes): void;

    /**
     * Flag tl_html rows whose last_seen_at predates $graceDays as stale, for
     * admin review — never an automatic delete (a bounded crawl scope or a
     * single fetch failure can make a still-valid row look absent).
     *
     * @return int rows flagged
     */
    public function flagStaleHtmlSources(int $graceDays): int;

    /**
     * Hard-delete confirmed-stale tl_html rows (only ever acts on rows already
     * flagged SOURCE_STALE). Their translation children are reclaimed by
     * OrphanTranslationSweep's existing daily sweep, not deleted here.
     *
     * @param int[] $ids
     * @return int rows deleted
     */
    public function deleteStaleHtmlSources(array $ids): int;


    /** Original text of one discovered source by ref, or null if gone/ignored. */
    public function loadSourceText(SourceRef $ref): ?string;


    /**
     * Source text plus the identity the write guard needs to choose a validation
     * grammar (SEO templates use per-plugin variable syntax, and a SourceRef only
     * carries {kind}:{id}). Manual saves only — the AI batch carries these fields
     * on its units and must not query per item.
     *
     * @return array{text: string, kind: string, meta_key: ?string}|null
     */
    public function loadSourceValidationContext(SourceRef $ref): ?array;


    /**
     * Source template + translation for a post's SEO meta keys, in one read, so
     * the output layer can tell "translated" from "exists but untranslated" —
     * states that need opposite handling. LEFT JOIN keeps untranslated rows;
     * child status is not filtered, matching every other render read.
     *
     * @param  string[] $metaKeys
     * @return array<int, array{meta_key: string, original_text: string, translated_text: ?string}>
     */
    public function fetchPostMetaTranslationStates(int $postId, array $metaKeys, string $langCode): array;


    /**
     * Aggregate translation progress for one language across the content tables
     * (post, meta, term, option, html) — for the rail's per-language percentage.
     *
     * @return array{total:int, translated:int}
     */
    public function languageProgress(string $langCode): array;

    /**
     * Create or update one translation for the parent addressed by $ref.
     * hash-link: keyed (parent.original_hash, lang) — shared across positions.
     * parent_id: keyed (parent.id, lang). No-op if the parent row is gone.
     */
    public function saveTranslation(SourceRef $ref, string $langCode, string $text, int $status, ?string $provider): void;

    /**
     * Create or update one gettext plural FORM for the message anchored by $ref,
     * keyed (parent_id, lang_code, form_index). Singular saves use saveTranslation.
     */
    public function savePluralForm(SourceRef $ref, string $langCode, int $formIndex, string $text, int $status, ?string $provider): void;

    /** Remove a single gettext plural FORM for $ref + language. */
    public function deletePluralForm(SourceRef $ref, string $langCode, int $formIndex): void;

    /**
     * The translated plural forms of $ref for one language, keyed
     * form_index => translated_text. For the admin plural editor.
     *
     * @return array<int, string>
     */
    public function loadPluralForms(SourceRef $ref, string $langCode): array;

    /** Remove the translation for $ref + language (hash-link delete is text-global). */
    public function deleteTranslation(SourceRef $ref, string $langCode): void;

    /**
     * Update only the status of $ref's translation for one language (no text
     * change): mark reviewed (2) or flag needs_review (-1). No-op if there is no
     * child row. hash-link updates by original_hash, parent_id by parent id.
     */
    public function setTranslationStatus(SourceRef $ref, string $langCode, int $status): void;

    /**
     * Apply the same translation to several parents (apply-everywhere). Mostly for
     * parent_id types — hash-link positions already share by text.
     *
     * @param SourceRef[] $refs
     * @return int Translations written.
     */
    public function applyTranslationToRefs(array $refs, string $langCode, string $text, int $status, bool $overwrite): int;

    /** Mark a parent ignored (status -1) AND delete its child(ren) across languages. */
    public function ignoreSource(SourceRef $ref): void;

    /**
     * Return ignored parents to discovered (status 0). Children were deleted on
     * ignore, so the string is untranslated until re-translated.
     *
     * @param SourceRef[] $refs
     */
    public function restoreSources(array $refs): void;

    /** updated_at of $ref's translation for the concurrent-edit guard, or null. */
    public function translationUpdatedAt(SourceRef $ref, string $langCode): ?string;

    // ── discovery reconciliation (delete-stale; carry-forward in Phase 5) ───────

    /**
     * Reconcile a post's parents (post fields + meta) after re-discovery: upsert
     * the current $records, then delete this post's still-discovered parents that
     * are absent from $records. Empty $records deletes all of the post's parents.
     *
     * @param SourceRecord[] $records
     */
    public function reconcilePost(int $postId, array $records): void;

    /**
     * Reconcile one post + meta key's parents. Same delete-stale rule, scoped.
     *
     * @param SourceRecord[] $records
     */
    public function reconcilePostMetaKey(int $postId, string $metaKey, array $records): void;

    /**
     * Reconcile a term's parents.
     *
     * @param SourceRecord[] $records
     */
    public function reconcileTerm(int $termTaxonomyId, array $records): void;

    /**
     * Reconcile one wp_options row's parents. Same delete-stale + carry-forward
     * rule as posts/terms, scoped to a single option_id: an edited tagline keeps
     * its translation, re-attached to the new text as needs_review.
     *
     * @param SourceRecord[] $records
     */
    public function reconcileOption(int $optionId, array $records): void;
}
