<?php

namespace TransLangs\TranslationMemory\Infrastructure;

use Masterminds\HTML5;
use TransLangs\Language\Domain\LanguageRegistry;
use TransLangs\TranslationMemory\Domain\SourceRoute;
use TransLangs\TranslationMemory\Domain\StringHashGenerator;
use TransLangs\TranslationMemory\Domain\StringNormalizer;
use TransLangs\TranslationMemory\Domain\TranslationMemoryRepository;
use TransLangs\TranslationMemory\Domain\TranslationSourceIdentity;

final class StructuredSourceScanner
{
    /** @var TranslationSourceIdentity[] */
    private array $identities = [];

    private int $skipped = 0;

    public function __construct(
        private LanguageRegistry $languageRegistry,
        private TranslationMemoryRepository $translationMemory,
        private StringNormalizer $normalizer,
        private StringHashGenerator $hashGenerator
    ) {}

    public function scan(): StructuredSourceScanResult
    {
        $this->identities = [];
        $this->skipped    = 0;

        $this->scanPostsOfType('attachment', ['inherit', 'publish']);
        $this->scanPostsOfType('nav_menu_item', ['publish']);
        $this->scanPostsOfType('wp_navigation', ['publish']);
        $this->scanPostsOfType('wp_block', ['publish']);
        $this->scanPostsOfType('wp_template_part', ['publish']);
        $this->scanPostsOfType('wp_template', ['publish']);
        $this->scanPostsOfType('page', ['publish']);
        $this->scanPostsOfType('post', ['publish']);

        foreach ($this->publicCustomPostTypes() as $postType) {
            $this->scanPostsOfType($postType, ['publish']);
        }
        $this->scanTerms();

        $this->identities = $this->dedupe($this->identities);
        $sourceByRoute   = $this->countByRoute($this->identities);
        $insertedByRoute = array_fill_keys(array_keys($sourceByRoute), 0);
        $targetLanguages = $this->languageRegistry->getTargetLanguages();

        foreach ($targetLanguages as $targetLang) {
            foreach ($this->groupByRoute($this->identities) as $route => $identities) {
                $insertedByRoute[$route] = ($insertedByRoute[$route] ?? 0)
                    + $this->translationMemory->insertMissingStrings($identities, $targetLang);
            }
        }

        return new StructuredSourceScanResult(
            $sourceByRoute,
            $insertedByRoute,
            count($targetLanguages),
            $this->skipped
        );
    }

    private function scanTerms(): void
    {
        if (!function_exists('get_taxonomies') || !function_exists('get_terms')) {
            return;
        }

        foreach (get_taxonomies(['public' => true], 'names') as $taxonomy) {
            $terms = get_terms([
                'taxonomy'   => $taxonomy,
                'hide_empty' => false,
            ]);

            if (!is_array($terms)) {
                continue;
            }

            foreach ($terms as $term) {
                if (!isset($term->term_taxonomy_id)) {
                    continue;
                }

                $termTaxonomyId = (int) $term->term_taxonomy_id;
                $this->addTermField($termTaxonomyId, 'name', (string) ($term->name ?? ''));
                $this->addTermField($termTaxonomyId, 'description', (string) ($term->description ?? ''));
                $this->addTermField($termTaxonomyId, 'slug', (string) ($term->slug ?? ''));
            }
        }
    }

    /**
     * @param string[] $statuses
     */
    private function scanPostsOfType(string $postType, array $statuses): void
    {
        if (!function_exists('post_type_exists') || !post_type_exists($postType) || !function_exists('get_posts')) {
            return;
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

        foreach ($posts as $post) {
            if (!$post instanceof \WP_Post) {
                continue;
            }

            $this->addPostField($post->ID, 'post_title', (string) $post->post_title, false);
            $this->addPostField($post->ID, 'post_content', (string) $post->post_content, true);
            $this->addPostField($post->ID, 'post_excerpt', (string) $post->post_excerpt, true);

            if ($postType === 'attachment') {
                $this->scanPostMeta($post->ID, ['_wp_attachment_image_alt']);
            } else {
                $this->scanPostMeta($post->ID, $this->translatableMetaKeys());
            }
        }
    }

    /**
     * @param string[] $keys
     */
    private function scanPostMeta(int $postId, array $keys): void
    {
        if (!function_exists('get_post_meta')) {
            return;
        }

        foreach (array_values(array_unique($keys)) as $metaKey) {
            $value = get_post_meta($postId, $metaKey, true);
            if (!is_scalar($value)) {
                continue;
            }

            $this->addPostmetaField($postId, $metaKey, (string) $value);
        }
    }

    private function addPostField(int $postId, string $field, string $text, bool $mayContainHtml): void
    {
        foreach ($mayContainHtml ? $this->textSegments($text) : [$text] as $segment) {
            $this->addIdentity(SourceRoute::Post, $postId, $field, null, null, null, $segment);
        }
    }

    private function addPostmetaField(int $postId, string $metaKey, string $text): void
    {
        $normalized = $this->normalizer->normalize($text);
        if ($normalized === '') {
            ++$this->skipped;
            return;
        }

        $this->identities[] = new TranslationSourceIdentity(
            SourceRoute::Postmeta,
            $postId,
            null,
            $metaKey,
            $this->hashGenerator->hashMetaKey($metaKey),
            null,
            $this->hashGenerator->hashContext(null),
            null,
            null,
            null,
            $text,
            $normalized,
            $this->hashGenerator->hashNormalized($normalized)
        );
    }

    private function addTermField(int $termTaxonomyId, string $field, string $text): void
    {
        foreach ($this->textSegments($text) as $segment) {
            $this->addIdentity(SourceRoute::Terms, $termTaxonomyId, $field, null, null, null, $segment);
        }
    }

    private function addIdentity(
        SourceRoute $route,
        int $parentId,
        ?string $field,
        ?string $source,
        ?string $reference,
        ?string $referenceHash,
        string $text
    ): void {
        $normalized = $this->normalizer->normalize($text);
        if ($normalized === '') {
            ++$this->skipped;
            return;
        }

        $this->identities[] = new TranslationSourceIdentity(
            $route,
            $parentId,
            $field,
            null,
            null,
            null,
            null,
            $source,
            $reference,
            $referenceHash,
            $text,
            $normalized,
            $this->hashGenerator->hashNormalized($normalized)
        );
    }

    /**
     * @return string[]
     */
    private function textSegments(string $text): array
    {
        if (trim($text) === '') {
            ++$this->skipped;
            return [];
        }

        if (!str_contains($text, '<')) {
            return $this->plainTextSegments($text);
        }

        if (!class_exists(HTML5::class)) {
            $plain = function_exists('wp_strip_all_tags') ? wp_strip_all_tags($text) : strip_tags($text);
            if (trim($plain) === '') {
                ++$this->skipped;
                return [];
            }

            return [$plain];
        }

        try {
            $html5 = new HTML5();
            $doc   = $html5->loadHTML('<!DOCTYPE html><html><body>' . $text . '</body></html>');
            $xpath = new \DOMXPath($doc);
            $nodes = $xpath->query('//body//text()[normalize-space(.) != ""]');

            if (!$nodes instanceof \DOMNodeList) {
                ++$this->skipped;
                return [];
            }

            $segments = [];
            foreach ($nodes as $node) {
                if (!$node instanceof \DOMText || $this->hasSkippedAncestor($node)) {
                    continue;
                }

                $segments[] = (string) $node->nodeValue;
            }

            if ($segments === []) {
                ++$this->skipped;
            }

            return $segments;
        } catch (\Throwable) {
            $plain = function_exists('wp_strip_all_tags') ? wp_strip_all_tags($text) : strip_tags($text);
            if (trim($plain) === '') {
                ++$this->skipped;
                return [];
            }

            return [$plain];
        }
    }

    /**
     * @return string[]
     */
    private function plainTextSegments(string $text): array
    {
        $segments = preg_split('/(?:\R\s*){2,}/u', trim($text)) ?: [];
        $segments = array_values(array_filter(
            array_map('trim', $segments),
            static fn(string $segment): bool => $segment !== ''
        ));

        return $segments === [] ? [$text] : $segments;
    }

    /**
     * @param TranslationSourceIdentity[] $identities
     * @return TranslationSourceIdentity[]
     */
    private function dedupe(array $identities): array
    {
        $seen   = [];
        $unique = [];

        foreach ($identities as $identity) {
            $key = implode('|', [
                $identity->routeTable->value,
                (string) $identity->parentId,
                (string) $identity->field,
                (string) $identity->metaKey,
                bin2hex((string) $identity->metaKeyHash),
                bin2hex((string) $identity->contextHash),
                (string) $identity->source,
                bin2hex((string) $identity->referenceHash),
                bin2hex($identity->originalHash),
            ]);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[]   = $identity;
        }

        return $unique;
    }

    private function hasSkippedAncestor(\DOMText $node): bool
    {
        $parent = $node->parentNode;
        while ($parent instanceof \DOMElement) {
            if (in_array(strtolower($parent->tagName), ['script', 'style', 'noscript', 'svg', 'math'], true)) {
                return true;
            }
            $parent = $parent->parentNode;
        }

        return false;
    }

    /**
     * @return string[]
     */
    private function publicCustomPostTypes(): array
    {
        if (!function_exists('get_post_types')) {
            return [];
        }

        $postTypes = get_post_types(['public' => true], 'names');
        $excluded  = [
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
            $postTypes,
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
            static fn(string $key): bool => $key !== ''
        ));
    }

    /**
     * @param TranslationSourceIdentity[] $identities
     * @return array<string, int>
     */
    private function countByRoute(array $identities): array
    {
        $counts = [
            SourceRoute::Post->value     => 0,
            SourceRoute::Postmeta->value => 0,
            SourceRoute::Terms->value    => 0,
            SourceRoute::I18n->value     => 0,
        ];

        foreach ($identities as $identity) {
            ++$counts[$identity->routeTable->value];
        }

        return $counts;
    }

    /**
     * @param TranslationSourceIdentity[] $identities
     * @return array<string, TranslationSourceIdentity[]>
     */
    private function groupByRoute(array $identities): array
    {
        $groups = [];

        foreach ($identities as $identity) {
            $groups[$identity->routeTable->value][] = $identity;
        }

        return $groups;
    }
}
