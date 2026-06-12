<?php

namespace TransLangs\TranslationMemory\Infrastructure;

use TransLangs\Language\Domain\LanguageRegistry;
use TransLangs\TranslationMemory\Domain\SourceCandidate;
use TransLangs\TranslationMemory\Domain\SourceTranslationRepository;
use TransLangs\TranslationMemory\Domain\TL_Key;

/**
 * Save-time structured discovery for owned WordPress content (architecture §5.5).
 *
 * Discovery is language-independent: each translatable string becomes exactly
 * one wp_tl_source row, regardless of how many target languages exist. Type-1
 * post fields are keyed by their WHOLE value (post_title / post_content /
 * post_excerpt), matching the render hooks that resolve them — no segmentation.
 *
 * On save, the object's current source set is upserted and any of its rows whose
 * key no longer exists are deleted (the FK cascade clears their translations,
 * UC-I2).
 */
final class StructuredSourceScanner
{
    /** Type-1 post fields, each keyed by its whole value (matches the render hooks). */
    private const POST_FIELDS = ['post_title', 'post_content', 'post_excerpt'];

    private int $skipped = 0;

    public function __construct(
        private LanguageRegistry $languageRegistry,
        private SourceTranslationRepository $repository
    ) {}

    /**
     * Bulk (re)discovery, triggered from the admin. Upserts every structured
     * source string across posts and terms. Language-independent: no inserts are
     * multiplied by language count.
     */
    public function scan(): StructuredSourceScanResult
    {
        $this->skipped = 0;

        $candidates = [];

        foreach ($this->scannablePostTypes() as $postType => $statuses) {
            foreach ($this->postsOfType($postType, $statuses) as $post) {
                array_push($candidates, ...$this->candidatesForPost($post));
            }
        }

        foreach ($this->allTerms() as [$termTaxonomyId, $name, $description]) {
            array_push($candidates, ...$this->candidatesForTerm($termTaxonomyId, $name, $description));
        }

        $candidates = $this->dedupeByKey($candidates);

        $sourceByRoute   = [
            'post'     => 0,
            'postmeta' => 0,
            'terms'    => 0,
            'i18n'     => 0,
        ];
        $insertedByRoute = $sourceByRoute;

        foreach ($this->groupByRoute($candidates) as $route => $group) {
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
     * and deletes the post's stale source rows.
     */
    public function refreshPost(int $postId): void
    {
        $post = function_exists('get_post') ? get_post($postId) : null;
        if (!$post instanceof \WP_Post || !$this->shouldScanPost($post)) {
            return;
        }

        $candidates = $this->dedupeByKey($this->candidatesForPost($post));

        $this->repository->upsertSources($candidates);
        $this->repository->deleteStaleSourcesForPost($post->ID, $this->keysOf($candidates));
    }

    /**
     * Save-time discovery for one taxonomy term (type 3). Upserts name and
     * description and deletes the term's stale source rows.
     */
    public function refreshTerm(int $termTaxonomyId, string $name, string $description): void
    {
        if ($termTaxonomyId <= 0) {
            return;
        }

        $candidates = $this->dedupeByKey($this->candidatesForTerm($termTaxonomyId, $name, $description));

        $this->repository->upsertSources($candidates);
        $this->repository->deleteStaleSourcesForTerm($termTaxonomyId, $this->keysOf($candidates));
    }

    /**
     * @return SourceCandidate[]
     */
    private function candidatesForPost(\WP_Post $post): array
    {
        $candidates = [];

        foreach (self::POST_FIELDS as $field) {
            $this->addCandidate($candidates, TL_Key::TYPE_POST, $post->ID, $field, null, (string) $post->{$field});
        }

        $metaKeys = $post->post_type === 'attachment'
            ? ['_wp_attachment_image_alt']
            : $this->translatableMetaKeys();

        foreach (array_unique($metaKeys) as $metaKey) {
            if ($this->isSlugKey($metaKey) || !function_exists('get_post_meta')) {
                continue;
            }

            $value = get_post_meta($post->ID, $metaKey, true);
            if (!is_scalar($value)) {
                continue;
            }

            // Scalar postmeta only in v1; ref2 (serialized/repeater context) is deferred.
            $this->addCandidate($candidates, TL_Key::TYPE_POSTMETA, $post->ID, $metaKey, null, (string) $value);
        }

        return $candidates;
    }

    /**
     * @return SourceCandidate[]
     */
    private function candidatesForTerm(int $termTaxonomyId, string $name, string $description): array
    {
        $candidates = [];

        $this->addCandidate($candidates, TL_Key::TYPE_TERMS, $termTaxonomyId, 'name', null, $name);
        $this->addCandidate($candidates, TL_Key::TYPE_TERMS, $termTaxonomyId, 'description', null, $description);

        return $candidates;
    }

    /**
     * Push a candidate unless its normalized text is empty or the ref is slug-like.
     *
     * @param SourceCandidate[] $candidates
     */
    private function addCandidate(
        array &$candidates,
        int $type,
        int $ownerId,
        string $ref1,
        ?string $ref2,
        string $text
    ): void {
        if ($this->isSlugKey($ref1)) {
            return;
        }

        if (TL_Key::normalize($text) === '') {
            ++$this->skipped;
            return;
        }

        $candidates[] = new SourceCandidate($type, $ownerId, $ref1, $ref2, $text);
    }

    private function shouldScanPost(\WP_Post $post): bool
    {
        return array_key_exists($post->post_type, $this->scannablePostTypes())
            || in_array($post->post_type, $this->publicCustomPostTypes(), true);
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
        if (!function_exists('get_taxonomies') || !function_exists('get_terms')) {
            return [];
        }

        $rows = [];

        foreach (get_taxonomies(['public' => true], 'names') as $taxonomy) {
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
     * @return string[]
     */
    private function translatableMetaKeys(): array
    {
        $default = [
            '_yoast_wpseo_title',
            '_yoast_wpseo_metadesc',
            '_wc_product_short_description',
        ];

        $keys = function_exists('apply_filters')
            ? apply_filters('translangs_translatable_meta_keys', $default)
            : $default;

        return array_values(array_filter(
            array_map('strval', is_array($keys) ? $keys : $default),
            fn(string $key): bool => $key !== '' && !$this->isSlugKey($key)
        ));
    }

    private function isSlugKey(string $key): bool
    {
        $key = strtolower(trim($key));

        return in_array($key, ['slug', '_slug', 'post_name', '_wp_old_slug'], true)
            || str_ends_with($key, '_slug');
    }

    /**
     * @param SourceCandidate[] $candidates
     * @return SourceCandidate[]
     */
    private function dedupeByKey(array $candidates): array
    {
        $seen   = [];
        $unique = [];

        foreach ($candidates as $candidate) {
            $hex = bin2hex($candidate->key());
            if (isset($seen[$hex])) {
                continue;
            }
            $seen[$hex] = true;
            $unique[]   = $candidate;
        }

        return $unique;
    }

    /**
     * @param SourceCandidate[] $candidates
     * @return array<string, SourceCandidate[]> route slug => candidates
     */
    private function groupByRoute(array $candidates): array
    {
        $route  = [
            TL_Key::TYPE_POST     => 'post',
            TL_Key::TYPE_POSTMETA => 'postmeta',
            TL_Key::TYPE_TERMS    => 'terms',
        ];
        $groups = [];

        foreach ($candidates as $candidate) {
            $slug              = $route[$candidate->type] ?? 'post';
            $groups[$slug][]   = $candidate;
        }

        return $groups;
    }

    /**
     * @param SourceCandidate[] $candidates
     * @return string[] Raw 32-byte keys.
     */
    private function keysOf(array $candidates): array
    {
        return array_map(static fn(SourceCandidate $c): string => $c->key(), $candidates);
    }
}
