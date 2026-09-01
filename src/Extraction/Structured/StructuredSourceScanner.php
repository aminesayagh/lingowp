<?php

namespace LingoWP\Extraction\Structured;

use LingoWP\Shared\Parsing\HtmlUnitExtractor;
use LingoWP\Shared\Source\Type\PostMetaSource;
use LingoWP\Shared\Source\PostMetaValuePaths;
use LingoWP\Shared\Source\Type\PostSource;
use LingoWP\Shared\Source\SourceRecord;
use LingoWP\Database\Repository\WpDbSourceRepository;
use LingoWP\Shared\Source\Type\SplitPostSource;
use LingoWP\Shared\Source\Type\TermSource;
use LingoWP\Shared\Source\Type\TermMetaSource;
use LingoWP\Shared\Source\Type\UserSource;
use LingoWP\Shared\Source\TranslatableTermMetaKeys;
use LingoWP\Shared\Parsing\SeoTemplateVariables;
use LingoWP\Shared\Text\TranslationKey;
use LingoWP\Shared\Source\TranslatableMetaKeys;
use LingoWP\Shared\Source\ScannablePostStatuses;
use LingoWP\Shared\Source\BlockAttributeValues;

/**
 * Save-time structured discovery for owned WordPress content (data pipeline §1-2,
 * Path A). Builds typed source records (PostSource / PostMetaSource / TermSource)
 * and hands them to the single repository writer.
 *
 * Discovery is language-independent: each translatable string becomes exactly
 * one wp_tl_source row, keyed by LOCATION (D26). Type-1 post fields are keyed by
 * their WHOLE value, matching the render hooks. On save, the entity's current
 * strings are upserted — an edit updates original_text + current_hash in place
 * (same key, translations preserved, D27) — and only rows whose LOCATION no
 * longer exists (field/meta key removed, repeater item deleted) are pruned
 * (FK cascade clears their translations). Entity deletion is handled by the FKs.
 */
final class StructuredSourceScanner
{
    /** Atomic type-1 post fields, each keyed by its whole value. post_content is
     * split into block-level pieces instead (split-field spec), see below. */
    private const POST_FIELDS = ['post_title', 'post_excerpt'];

    /** Post statuses eligible for discovery (publish, plus inherit for attachments). */
    private const SCANNABLE_STATUSES = ScannablePostStatuses::ALL;

    /** Built-in post types scanned by name rather than via the public-custom-type filter. */
    private const BUILTIN_TYPES = [
        'attachment', 'nav_menu_item', 'wp_navigation', 'wp_block',
        'wp_template_part', 'wp_template', 'page', 'post',
    ];

    private WpDbSourceRepository $repository;
    private HtmlUnitExtractor $unitExtractor;

    public function __construct(
        WpDbSourceRepository $repository,
        HtmlUnitExtractor $unitExtractor
    ) {
        $this->repository    = $repository;
        $this->unitExtractor = $unitExtractor;
    }

    /**
     * Save-time discovery for one post (types 1 + 2). Upserts current strings
     * (edits update in place) and prunes locations that no longer exist.
     */
    public function refreshPost(int $postId): void
    {
        $post = function_exists('get_post') ? get_post($postId) : null;
        if (!$post instanceof \WP_Post || !$this->shouldScanPost($post) || !$this->isScannableStatus($post)) {
            return;
        }

        $records = $this->dedupeByIdentity($this->candidatesForPost($post));

        $this->repository->reconcilePost($post->ID, $records);
    }

    /**
     * Single-key discovery for meta written outside a post save (importers,
     * frontend forms, cron). Upserts this meta key's current paths and prunes
     * paths that disappeared.
     */
    public function refreshPostMetaKey(int $postId, string $metaKey): void
    {
        $post = function_exists('get_post') ? get_post($postId) : null;
        if (!$post instanceof \WP_Post || !$this->shouldScanPost($post) || !$this->isScannableStatus($post)) {
            return;
        }
        if (!$this->isDiscoverableMetaKey($post, $metaKey) || !function_exists('get_post_meta')) {
            return;
        }

        $value   = get_post_meta($postId, $metaKey, true);
        $records = $this->dedupeByIdentity($this->metaCandidates($postId, $metaKey, $value));

        $this->repository->reconcilePostMetaKey($postId, $metaKey, $records);
    }

    /**
     * Save-time discovery for one taxonomy term (type 3). Skips non-translatable
     * taxonomies.
     */
    public function refreshTerm(int $termTaxonomyId, string $taxonomy, string $name, string $description): void
    {
        if ($termTaxonomyId <= 0 || !$this->isTranslatableTaxonomy($taxonomy)) {
            return;
        }

        $records = $this->dedupeByIdentity($this->candidatesForTerm($termTaxonomyId, $name, $description));

        $this->repository->reconcileTerm($termTaxonomyId, $records);
    }

    /**
     * Save-time discovery for one user's translatable fields (type 4) — the
     * author bio ('description') by default, plus whatever an add-on extends
     * via UserSource::allowedFields(). Every allow-listed field is read and
     * reconciled together in ONE call: reconcileUser() scopes by user_id
     * alone (not per field), so reconciling just one field independently
     * would silently delete every other field's translation — same reasoning
     * as refreshTermMeta() below reconciling all of TranslatableTermMetaKeys
     * together.
     */
    public function refreshUser(int $userId): void
    {
        if ($userId <= 0 || !function_exists('get_user_meta')) {
            return;
        }

        $records = [];
        foreach (UserSource::allowedFields() as $field) {
            $value = (string) get_user_meta($userId, $field, true);
            if (TranslationKey::normalize($value) !== '') {
                $records[] = new UserSource($userId, $field, $value);
            }
        }

        $this->repository->reconcileUser($userId, $records);
    }

    /**
     * Save-time discovery for one taxonomy term's meta (type 5: an ACF field on a
     * category, or any other plugin's per-term custom field). Allow-listed via
     * TranslatableTermMetaKeys — empty by default, opted into per key via the
     * `lingowp_translatable_term_meta_keys` filter, since (unlike post meta)
     * there's no known-upfront set of term-meta keys to default to.
     */
    public function refreshTermMeta(int $termId): void
    {
        if ($termId <= 0 || !function_exists('get_term_meta')) {
            return;
        }

        $records = [];
        foreach (TranslatableTermMetaKeys::keys() as $metaKey) {
            $value = get_term_meta($termId, $metaKey, true);
            foreach (PostMetaValuePaths::extract($value) as $leaf) {
                $records[] = new TermMetaSource($termId, $metaKey, $leaf['context'], $leaf['text']);
            }
        }

        $this->repository->reconcileTermMeta($termId, $this->dedupeByIdentity($records));
    }

    /**
     * @return SourceRecord[]
     */
    private function candidatesForPost(\WP_Post $post): array
    {
        $records = [];

        foreach (self::POST_FIELDS as $field) {
            $text = (string) $post->{$field};
            // post_title (not post_excerpt) is texturized by WordPress before a
            // visitor ever sees it (the_title runs core's own wptexturize BEFORE
            // this plugin's render filter), so a straight quote or "--" in the
            // stored title would otherwise hash differently at discovery than at
            // render — a translation that shows done but never actually renders.
            // post_excerpt's render hook (get_the_excerpt) runs BEFORE that same
            // texturize layer, so it already matches the raw text as-is.
            if ($field === 'post_title') {
                $text = wptexturize($text);
            }
            if (TranslationKey::normalize($text) === '') {
                continue;
            }
            $records[] = new PostSource($post->ID, $field, $text);
        }

        // post_content is split HTML (split-field spec): one source row per
        // block-level piece, text-tailed. Extracts the stored markup ($post->
        // post_content) — block comments/wrappers are scaffolding and ignored.
        //
        // Normalized the same way WordPress transforms it before a visitor sees
        // it: wptexturize() always (the_content runs core's own wptexturize
        // before this plugin's render filter, same reasoning as post_title
        // above), then wpautop() ONLY when the content isn't already block
        // markup — a classic-editor/imported/programmatic post stores plain
        // text with blank-line paragraphs and no HTML at all, which WordPress
        // only wraps into <p> tags at display time; without this, such a post's
        // entire body was invisible to discovery even though a visitor reads it
        // fine. Real block content already has real <p> tags and must not be
        // re-wrapped — has_blocks() is the exact, cheap guard for that (running
        // do_blocks() itself would execute dynamic block render callbacks,
        // which is unnecessary: a static block's raw markup already contains
        // its final text, and any texturize applied to a block comment's own
        // JSON is harmless since comment nodes are discarded during extraction
        // either way).
        $content = wptexturize((string) $post->post_content);
        if (!has_blocks($content)) {
            $content = wpautop($content);
        }
        foreach ($this->unitExtractor->units($content) as $unit) {
            if (TranslationKey::normalize($unit) === '') {
                continue;
            }
            $records[] = new SplitPostSource($post->ID, 'post_content', $unit);
        }

        // Some blocks (Search, Navigation Link, Post Terms, …) store their
        // whole visible text only in their own settings, inside the block
        // comment's JSON — never in real page markup, so the DOM-based scan
        // above never sees it (see BlockContentAttributes' class doc). Reads
        // the RAW stored post_content, not the texturized/autop'd $content
        // above — wptexturize() would corrupt straight quotes inside a block's
        // JSON attributes, breaking parse_blocks() entirely. Reuses PostSource
        // directly: a block-attribute value's identity is exactly (post_id,
        // 'post_content', original_hash), the same shape as a regular
        // split-content piece, so it shares the existing carry-forward/prune
        // reconciliation with zero changes needed there.
        foreach (BlockAttributeValues::extract((string) $post->post_content) as $text) {
            $records[] = new PostSource($post->ID, 'post_content', $text);
        }

        if (function_exists('get_post_meta')) {
            foreach ($this->metaKeysForPost($post) as $metaKey) {
                $value = get_post_meta($post->ID, $metaKey, true);
                array_push($records, ...$this->metaCandidates($post->ID, $metaKey, $value));
            }
        }

        // Lets an add-on (lingowp-elementor, a future lingowp-bricks, …) contribute
        // additional records into this SAME atomic reconcile, for content a
        // page-builder plugin stores outside real post_content/postmeta entirely.
        // Must be additive, never a separate reconcile call of the add-on's own:
        // reconcilePost() deletes anything in scope not present in $records, so a
        // partial-scope call would wipe out the post's title/body on the very next
        // save — the exact bug class already caught once this session elsewhere.
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('lingowp_post_source_records', $records, $post);
            $records  = is_array($filtered) ? $filtered : $records;
        }

        return $records;
    }

    /**
     * Filtering happens HERE rather than inside PostMetaValuePaths::isTranslatable()
     * because the variable grammar depends on the meta key, which only this scope
     * knows — and because that filter is shared by every allow-listed key, so an
     * SEO-specific rule there would change behaviour for WooCommerce and custom
     * fields too. extract() has already flattened scalar, array and JSON leaves,
     * so filtering its output still covers all of them.
     *
     * @return SourceRecord[]
     */
    private function metaCandidates(int $postId, string $metaKey, $value): array
    {
        $records = [];

        foreach (PostMetaValuePaths::extract($value) as $leaf) {
            // A pure template ("%%title%% %%sep%% %%sitename%%") holds no words:
            // storing it only invites a translator to break the variables.
            if (!SeoTemplateVariables::hasTranslatableProse($metaKey, $leaf['text'])) {
                continue;
            }
            $records[] = new PostMetaSource($postId, $metaKey, $leaf['context'], $leaf['text']);
        }

        return $records;
    }

    /**
     * @return SourceRecord[]
     */
    private function candidatesForTerm(int $termTaxonomyId, string $name, string $description): array
    {
        $records = [];

        foreach (['name' => $name, 'description' => $description] as $field => $text) {
            if (TranslationKey::normalize($text) === '') {
                continue;
            }
            $records[] = new TermSource($termTaxonomyId, $field, $text);
        }

        return $records;
    }

    private function shouldScanPost(\WP_Post $post): bool
    {
        return array_key_exists($post->post_type, $this->scannablePostTypes());
    }

    private function isScannableStatus(\WP_Post $post): bool
    {
        return in_array($post->post_status, self::SCANNABLE_STATUSES, true);
    }

    /** Attachments expose only ALT text; other post types use the meta allow-list. */
    private function isDiscoverableMetaKey(\WP_Post $post, string $metaKey): bool
    {
        return in_array($metaKey, $this->metaKeysForPost($post), true);
    }

    /**
     * @return string[]
     */
    private function metaKeysForPost(\WP_Post $post): array
    {
        $keys = $post->post_type === 'attachment'
            ? [TranslatableMetaKeys::ATTACHMENT_ALT]
            : $this->translatableMetaKeys();

        return array_values(array_filter(
            array_unique($keys),
            fn(string $key): bool => !$this->isSlugKey($key)
        ));
    }

    private function isTranslatableTaxonomy(string $taxonomy): bool
    {
        return in_array($taxonomy, $this->translatableTaxonomies(), true);
    }

    /**
     * Post types scanned in bulk, with the statuses to include.
     *
     * @return array<string, string[]>
     */
    private function scannablePostTypes(): array
    {
        $types = ['attachment' => ['inherit', 'publish']];

        foreach (self::BUILTIN_TYPES as $postType) {
            $types[$postType] ??= ['publish'];
        }

        foreach ($this->publicCustomPostTypes() as $postType) {
            $types[$postType] = ['publish'];
        }

        return $types;
    }

    /**
     * @return string[]
     */
    private function publicCustomPostTypes(): array
    {
        if (!function_exists('get_post_types')) {
            return [];
        }

        $excluded = array_fill_keys(self::BUILTIN_TYPES, true);

        return array_values(array_filter(
            get_post_types(['public' => true], 'names'),
            static fn(string $postType): bool => !isset($excluded[$postType])
        ));
    }

    /**
     * Translatable taxonomies — all public by default; the filter only restricts.
     *
     * @return string[]
     */
    private function translatableTaxonomies(): array
    {
        $all = function_exists('get_taxonomies')
            ? array_values(get_taxonomies(['public' => true], 'names'))
            : [];

        $list = function_exists('apply_filters')
            ? apply_filters('lingowp_translatable_taxonomies', $all)
            : $all;

        return array_values(array_filter(
            array_map('strval', is_array($list) ? $list : $all),
            static fn(string $taxonomy): bool => $taxonomy !== ''
        ));
    }

    /**
     * @return string[]
     */
    private function translatableMetaKeys(): array
    {
        return TranslatableMetaKeys::keys();
    }

    private function isSlugKey(string $key): bool
    {
        return TranslatableMetaKeys::isSlugKey($key);
    }

    /**
     * @param SourceRecord[] $records
     * @return SourceRecord[]
     */
    private function dedupeByIdentity(array $records): array
    {
        $seen   = [];
        $unique = [];

        foreach ($records as $record) {
            $key = $record->dedupeKey();
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[]   = $record;
        }

        return $unique;
    }
}
