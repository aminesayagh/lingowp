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

final class StructuredSourceScanner
{
    private const POST_FIELDS = ['post_title', 'post_excerpt'];

    private const SCANNABLE_STATUSES = ScannablePostStatuses::ALL;

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

    public function refreshPost(int $postId): void
    {
        $post = function_exists('get_post') ? get_post($postId) : null;
        if (!$post instanceof \WP_Post || !$this->shouldScanPost($post) || !$this->isScannableStatus($post)) {
            return;
        }

        $records = $this->dedupeByIdentity($this->candidatesForPost($post));

        $this->repository->reconcilePost($post->ID, $records);
    }

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

    public function refreshTerm(int $termTaxonomyId, string $taxonomy, string $name, string $description): void
    {
        if ($termTaxonomyId <= 0 || !$this->isTranslatableTaxonomy($taxonomy)) {
            return;
        }

        $records = $this->dedupeByIdentity($this->candidatesForTerm($termTaxonomyId, $name, $description));

        $this->repository->reconcileTerm($termTaxonomyId, $records);
    }

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

    private function candidatesForPost(\WP_Post $post): array
    {
        $records = [];

        foreach (self::POST_FIELDS as $field) {
            $text = (string) $post->{$field};
            if ($field === 'post_title') {
                $text = wptexturize($text);
            }
            if (TranslationKey::normalize($text) === '') {
                continue;
            }
            $records[] = new PostSource($post->ID, $field, $text);
        }

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

        foreach (BlockAttributeValues::extract((string) $post->post_content) as $text) {
            $records[] = new PostSource($post->ID, 'post_content', $text);
        }

        if (function_exists('get_post_meta')) {
            foreach ($this->metaKeysForPost($post) as $metaKey) {
                $value = get_post_meta($post->ID, $metaKey, true);
                array_push($records, ...$this->metaCandidates($post->ID, $metaKey, $value));
            }
        }

        if (function_exists('apply_filters')) {
            $filtered = apply_filters('lingowp_post_source_records', $records, $post);
            $records  = is_array($filtered) ? $filtered : $records;
        }

        return $records;
    }

    private function metaCandidates(int $postId, string $metaKey, $value): array
    {
        $records = [];

        foreach (PostMetaValuePaths::extract($value) as $leaf) {
            if (!SeoTemplateVariables::hasTranslatableProse($metaKey, $leaf['text'])) {
                continue;
            }
            $records[] = new PostMetaSource($postId, $metaKey, $leaf['context'], $leaf['text']);
        }

        return $records;
    }

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

    private function isDiscoverableMetaKey(\WP_Post $post, string $metaKey): bool
    {
        return in_array($metaKey, $this->metaKeysForPost($post), true);
    }

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

    private function translatableMetaKeys(): array
    {
        return TranslatableMetaKeys::keys();
    }

    private function isSlugKey(string $key): bool
    {
        return TranslatableMetaKeys::isSlugKey($key);
    }

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
