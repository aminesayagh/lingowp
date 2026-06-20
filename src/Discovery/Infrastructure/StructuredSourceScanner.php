<?php

namespace TransLangs\Discovery\Infrastructure;

use TransLangs\Language\Domain\LanguageRegistry;
use TransLangs\Source\Infrastructure\HtmlUnitExtractor;
use TransLangs\Source\Domain\PostMetaSource;
use TransLangs\Source\Domain\PostMetaValuePaths;
use TransLangs\Source\Domain\PostSource;
use TransLangs\Source\Domain\SourceRecord;
use TransLangs\Source\Domain\SourceTranslationRepository;
use TransLangs\Source\Domain\SplitPostSource;
use TransLangs\Source\Domain\TermSource;
use TransLangs\Source\Domain\TL_Key;
use TransLangs\Source\Domain\TranslatableMetaKeys;

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
    private const SCANNABLE_STATUSES = ['publish', 'inherit'];

    private int $skipped = 0;

    public function __construct(
        private LanguageRegistry $languageRegistry,
        private SourceTranslationRepository $repository,
        private HtmlUnitExtractor $unitExtractor
    ) {}

    /**
     * Bulk (re)discovery, triggered from the admin. Upserts every structured
     * source string across posts and terms. Language-independent.
     */
    public function scan(): StructuredSourceScanResult
    {
        $this->skipped = 0;

        $records = [];

        foreach ($this->scannablePostTypes() as $postType => $statuses) {
            foreach ($this->postsOfType($postType, $statuses) as $post) {
                array_push($records, ...$this->candidatesForPost($post));
            }
        }

        foreach ($this->allTerms() as [$termTaxonomyId, $name, $description]) {
            array_push($records, ...$this->candidatesForTerm($termTaxonomyId, $name, $description));
        }

        $records = $this->dedupeByIdentity($records);

        $sourceByRoute   = ['post' => 0, 'meta' => 0, 'term' => 0];
        $insertedByRoute = $sourceByRoute;

        foreach ($this->groupByRoute($records) as $route => $group) {
            $sourceByRoute[$route]   = count($group);
            $insertedByRoute[$route] = $this->repository->upsertSources($group);
        }

        return new StructuredSourceScanResult(
            $sourceByRoute,
            $insertedByRoute,
            count($this->languageRegistry->getTargetLanguages()),
            $this->skipped
        );
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
     * @return SourceRecord[]
     */
    private function candidatesForPost(\WP_Post $post): array
    {
        $records = [];

        foreach (self::POST_FIELDS as $field) {
            $text = (string) $post->{$field};
            if (TL_Key::normalize($text) === '') {
                ++$this->skipped;
                continue;
            }
            $records[] = new PostSource($post->ID, $field, $text);
        }

        // post_content is split HTML (split-field spec): one source row per
        // block-level piece, text-tailed. Extracts the stored markup ($post->
        // post_content) — block comments/wrappers are scaffolding and ignored.
        foreach ($this->unitExtractor->units((string) $post->post_content) as $unit) {
            if (TL_Key::normalize($unit) === '') {
                continue;
            }
            $records[] = new SplitPostSource($post->ID, 'post_content', $unit);
        }

        if (function_exists('get_post_meta')) {
            foreach ($this->metaKeysForPost($post) as $metaKey) {
                $value = get_post_meta($post->ID, $metaKey, true);
                array_push($records, ...$this->metaCandidates($post->ID, $metaKey, $value));
            }
        }

        return $records;
    }

    /**
     * @return SourceRecord[]
     */
    private function metaCandidates(int $postId, string $metaKey, mixed $value): array
    {
        $records = [];

        foreach (PostMetaValuePaths::extract($value) as $leaf) {
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
            if (TL_Key::normalize($text) === '') {
                ++$this->skipped;
                continue;
            }
            $records[] = new TermSource($termTaxonomyId, $field, $text);
        }

        return $records;
    }

    private function shouldScanPost(\WP_Post $post): bool
    {
        return array_key_exists($post->post_type, $this->scannablePostTypes())
            || in_array($post->post_type, $this->publicCustomPostTypes(), true);
    }

    private function isScannableStatus(\WP_Post $post): bool
    {
        return in_array($post->post_status, self::SCANNABLE_STATUSES, true);
    }

    /** Attachments expose only ALT text; other post types use the meta allow-list. */
    private function isDiscoverableMetaKey(\WP_Post $post, string $metaKey): bool
    {
        if ($this->isSlugKey($metaKey)) {
            return false;
        }
        if ($post->post_type === 'attachment') {
            return $metaKey === '_wp_attachment_image_alt';
        }

        return in_array($metaKey, $this->translatableMetaKeys(), true);
    }

    /**
     * @return string[]
     */
    private function metaKeysForPost(\WP_Post $post): array
    {
        $keys = $post->post_type === 'attachment'
            ? ['_wp_attachment_image_alt']
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
        $types = [
            'attachment'       => ['inherit', 'publish'],
            'nav_menu_item'    => ['publish'],
            'wp_navigation'    => ['publish'],
            'wp_block'         => ['publish'],
            'wp_template_part' => ['publish'],
            'wp_template'      => ['publish'],
            'page'             => ['publish'],
            'post'             => ['publish'],
        ];

        foreach ($this->publicCustomPostTypes() as $postType) {
            $types[$postType] = ['publish'];
        }

        return $types;
    }

    /**
     * @param string[] $statuses
     * @return \WP_Post[]
     */
    private function postsOfType(string $postType, array $statuses): array
    {
        if (!function_exists('post_type_exists') || !post_type_exists($postType) || !function_exists('get_posts')) {
            return [];
        }

        $posts = get_posts([
            'post_type'              => $postType,
            'post_status'            => $statuses,
            'posts_per_page'         => -1,
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'suppress_filters'       => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]);

        return array_values(array_filter(
            $posts,
            static fn($post): bool => $post instanceof \WP_Post
        ));
    }

    /**
     * @return list<array{0:int,1:string,2:string}> [term_taxonomy_id, name, description]
     */
    private function allTerms(): array
    {
        if (!function_exists('get_terms')) {
            return [];
        }

        $rows = [];

        foreach ($this->translatableTaxonomies() as $taxonomy) {
            $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
            if (!is_array($terms)) {
                continue;
            }

            foreach ($terms as $term) {
                if (!isset($term->term_taxonomy_id)) {
                    continue;
                }
                $rows[] = [
                    (int) $term->term_taxonomy_id,
                    (string) ($term->name ?? ''),
                    (string) ($term->description ?? ''),
                ];
            }
        }

        return $rows;
    }

    /**
     * @return string[]
     */
    private function publicCustomPostTypes(): array
    {
        if (!function_exists('get_post_types')) {
            return [];
        }

        $excluded = [
            'attachment'       => true,
            'nav_menu_item'    => true,
            'wp_navigation'    => true,
            'wp_block'         => true,
            'wp_template_part' => true,
            'wp_template'      => true,
            'page'             => true,
            'post'             => true,
        ];

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
            ? apply_filters('translangs_translatable_taxonomies', $all)
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

    /**
     * @param SourceRecord[] $records
     * @return array<string, SourceRecord[]> route slug => records
     */
    private function groupByRoute(array $records): array
    {
        $route  = [
            'tl_post' => 'post',
            'tl_meta' => 'meta',
            'tl_term' => 'term',
        ];
        $groups = [];

        foreach ($records as $record) {
            $slug            = $route[$record->table()] ?? 'post';
            $groups[$slug][] = $record;
        }

        return $groups;
    }
}
