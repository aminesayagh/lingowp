<?php

namespace LingoWP\Resolution;
use LingoWP\Resolution\PageOwnedHashes;
use LingoWP\Resolution\RequestContext;

use LingoWP\Language\Application\ResolveRequestLanguage;
use LingoWP\Language\Domain\LanguageRegistry;
use LingoWP\Shared\Text\IdentityKey;
use LingoWP\Shared\Parsing\HtmlUnitExtractor;
use LingoWP\Database\Repository\WpDbSourceRepository;
use LingoWP\Shared\Text\TranslationKey;
use LingoWP\Shared\Source\TranslatableMetaKeys;
use LingoWP\Shared\Source\TranslatableTermMetaKeys;
use LingoWP\Shared\Source\PostMetaValuePaths;
use LingoWP\Shared\Source\BlockContentAttributes;

final class StructuredRenderResolver
{
    use DeferredTranslationActivation;

    private ?array $langsToTry = null;

    private array $hashMap = [];

    private array $hashLoaded = [];

    private array $metaLeafMap = [];

    private array $metaLeafLoaded = [];

    private ?array $metaKeys = null;

    private ?array $termMetaKeys = null;

    private ResolveRequestLanguage $language;
    private RequestContext $context;
    private WpDbSourceRepository $repository;
    private HtmlUnitExtractor $unitExtractor;
    private PageOwnedHashes $pageOwned;
    private LanguageRegistry $languageRegistry;

    public function __construct(
        ResolveRequestLanguage $language,
        RequestContext $context,
        WpDbSourceRepository $repository,
        HtmlUnitExtractor $unitExtractor,
        PageOwnedHashes $pageOwned,
        LanguageRegistry $languageRegistry
    ) {
        $this->language         = $language;
        $this->context          = $context;
        $this->repository       = $repository;
        $this->unitExtractor    = $unitExtractor;
        $this->pageOwned        = $pageOwned;
        $this->languageRegistry = $languageRegistry;
    }

    public function register(): void
    {
        add_filter('the_posts', [$this, 'preloadPosts'], 10, 2);
        add_filter('the_title', [$this, 'title'], 10, 2);
        add_filter('the_content', [$this, 'content'], 10, 1);
        add_filter('render_block_data', [$this, 'blockAttributes'], 10, 1);
        add_filter('get_the_excerpt', [$this, 'excerpt'], 10, 2);
        add_filter('wp_get_attachment_caption', [$this, 'excerpt'], 10, 2);
        add_filter('get_post_metadata', [$this, 'metadata'], 10, 4);
        add_filter('get_term_metadata', [$this, 'termMetadata'], 10, 4);
        add_filter('get_term', [$this, 'term'], 10, 2);
        add_filter('get_terms', [$this, 'terms'], 10, 1);
        add_filter('get_the_terms', [$this, 'theTerms'], 10, 1);
        add_filter('get_the_author_description', [$this, 'authorDescription'], 10, 1);
    }

    private function metaLookup(int $postId, string $metaKey): array
    {
        return [
            'post_id'       => $postId,
            'meta_key_hash' => hash('sha256', $metaKey, true),
        ];
    }

    private function termMetaLookup(int $termId, string $metaKey): array
    {
        return [
            'term_id'       => $termId,
            'meta_key_hash' => hash('sha256', $metaKey, true),
        ];
    }

    public function preloadPosts($posts, $query = null)
    {
        unset($query);

        if (!$this->isActive() || !is_array($posts)) {
            return $posts;
        }

        $postHashes  = [];
        $metaLookups = [];
        foreach ($posts as $post) {
            if (!$post instanceof \WP_Post) {
                continue;
            }
            $postHashes[] = TranslationKey::currentHash((string) $post->post_title);
            $postHashes[] = TranslationKey::currentHash((string) $post->post_excerpt);
            foreach ($this->unitExtractor->units((string) $post->post_content) as $unit) {
                $postHashes[] = TranslationKey::currentHash($unit);
            }
            foreach ($this->metaKeys() as $metaKey) {
                $metaLookups[] = $this->metaLookup($post->ID, $metaKey);
            }
        }

        $this->ensureHashLoaded('post', $postHashes);
        $this->ensureMetaLeavesLoaded($metaLookups);

        return $posts;
    }

    public function title($title, $postId = null)
    {
        if (!$this->isActive()) {
            return $title;
        }

        return $this->hashValue('post', (string) $title);
    }

    public function content($content)
    {
        if (!$this->isActive()) {
            return $content;
        }

        $content = (string) $content;

        $hashes = [];
        foreach ($this->unitExtractor->units($content) as $unit) {
            $hashes[] = TranslationKey::currentHash($unit);
        }
        $this->ensureHashLoaded('post', $hashes);

        return $this->unitExtractor->render(
            $content,
            fn(string $unit): ?string => $this->hashMap[$this->hashKey('post', TranslationKey::currentHash($unit))] ?? null
        );
    }

    public function blockAttributes(array $parsedBlock): array
    {
        $name = $parsedBlock['blockName'] ?? null;

        if (!$this->isActive() || !is_string($name) || $name === '' || !BlockContentAttributes::isDynamic($name)) {
            return $parsedBlock;
        }

        foreach (BlockContentAttributes::contentKeys($name) as $key) {
            $value = $parsedBlock['attrs'][$key] ?? null;
            if (is_string($value) && $value !== '') {
                $parsedBlock['attrs'][$key] = $this->hashValue('post', $value);
            }
        }

        return $parsedBlock;
    }

    public function excerpt($excerpt, $post = null)
    {
        unset($post);

        if (!$this->isActive()) {
            return $excerpt;
        }

        return $this->hashValue('post', (string) $excerpt);
    }

    public function metadata($value, $objectId, $metaKey, $single)
    {
        unset($single);

        if (!$this->isActive()) {
            return $value;
        }
        $objectId = (int) $objectId;
        $metaKey  = (string) $metaKey;
        if ($objectId <= 0 || $metaKey === '' || !$this->isResolvableMetaKey($metaKey)) {
            return $value;
        }

        $lookup = $this->metaLookup($objectId, $metaKey);
        $this->ensureMetaLeavesLoaded([$lookup]);
        $leaves = $this->metaLeafMap[IdentityKey::of($lookup)] ?? [];

        if ($leaves === []) {
            return $value;
        }
        if (count($leaves) === 1 && array_key_exists('', $leaves)) {
            return [$leaves['']];
        }

        $raw = $this->repository->rawPostMetaValue($objectId, $metaKey);

        return [PostMetaValuePaths::patch($raw, $leaves)];
    }

    public function termMetadata($value, $objectId, $metaKey, $single)
    {
        unset($single);

        if (!$this->isActive()) {
            return $value;
        }
        $objectId = (int) $objectId;
        $metaKey  = (string) $metaKey;
        if ($objectId <= 0 || $metaKey === '' || !$this->isResolvableTermMetaKey($metaKey)) {
            return $value;
        }

        $lookup = $this->termMetaLookup($objectId, $metaKey);
        $this->ensureMetaLeavesLoaded([$lookup], 'term_meta');
        $leaves = $this->metaLeafMap[IdentityKey::of($lookup)] ?? [];

        if ($leaves === []) {
            return $value;
        }
        if (count($leaves) === 1 && array_key_exists('', $leaves)) {
            return [$leaves['']];
        }

        $raw = $this->repository->rawTermMetaValue($objectId, $metaKey);

        return [PostMetaValuePaths::patch($raw, $leaves)];
    }

    public function term($term, $taxonomy = '')
    {
        unset($taxonomy);

        if ($this->isActive() && $term instanceof \WP_Term) {
            $this->resolveTerms([$term]);
        }

        return $term;
    }

    public function terms($terms)
    {
        if ($this->isActive() && is_array($terms)) {
            $this->resolveTerms($terms);
        }

        return $terms;
    }

    public function theTerms($terms)
    {
        if ($this->isActive() && is_array($terms)) {
            $this->resolveTerms($terms);
        }

        return $terms;
    }

    public function authorDescription($description)
    {
        if (!$this->isActive()) {
            return $description;
        }

        return $this->hashValue('user', (string) $description);
    }

    private function hashValue(string $tableKey, string $original): string
    {
        $hash = TranslationKey::currentHash($original);
        $this->ensureHashLoaded($tableKey, [$hash]);

        return $this->hashMap[$this->hashKey($tableKey, $hash)] ?? $original;
    }

    private function resolveTerms(array $terms): void
    {
        $objects = array_filter($terms, static fn($t): bool => $t instanceof \WP_Term);
        if ($objects === []) {
            return;
        }

        $hashes = [];
        foreach ($objects as $term) {
            $hashes[] = TranslationKey::currentHash((string) $term->name);
            $hashes[] = TranslationKey::currentHash((string) $term->description);
        }
        $this->ensureHashLoaded('term', $hashes);

        foreach ($objects as $term) {
            $nameKey = $this->hashKey('term', TranslationKey::currentHash((string) $term->name));
            $descKey = $this->hashKey('term', TranslationKey::currentHash((string) $term->description));
            if (isset($this->hashMap[$nameKey])) {
                $term->name = $this->hashMap[$nameKey];
            }
            if (isset($this->hashMap[$descKey])) {
                $term->description = $this->hashMap[$descKey];
            }
        }
    }

    private function ensureHashLoaded(string $tableKey, array $rawHashes): void
    {
        $todo = [];
        foreach ($rawHashes as $hash) {
            $this->pageOwned->mark($hash);

            $key = $this->hashKey($tableKey, $hash);
            if (!isset($this->hashLoaded[$key])) {
                $todo[$key] = $hash;
            }
        }
        if ($todo === []) {
            return;
        }

        $remaining = $todo;
        foreach ($this->langsToTry() as $lang) {
            if ($remaining === []) {
                break;
            }
            $fetched = $this->repository->fetchHashTranslations($tableKey, array_values($remaining), $lang);
            foreach ($remaining as $key => $hash) {
                $hex = bin2hex($hash);
                if (isset($fetched[$hex])) {
                    $this->hashMap[$key] = $fetched[$hex];
                    unset($remaining[$key]);
                }
            }
        }

        foreach ($todo as $key => $hash) {
            $this->hashLoaded[$key] = true;
        }
    }

    private function ensureMetaLeavesLoaded(array $lookups, string $tableKey = 'meta'): void
    {
        $todo = [];
        foreach ($lookups as $lookup) {
            $key = IdentityKey::of($lookup);
            if (!isset($this->metaLeafLoaded[$key])) {
                $todo[$key] = $lookup;
            }
        }
        if ($todo === []) {
            return;
        }

        $remaining = $todo;
        foreach ($this->langsToTry() as $lang) {
            if ($remaining === []) {
                break;
            }
            $fetched = $this->repository->fetchMetaLeafTranslations($tableKey, array_values($remaining), $lang);
            foreach ($remaining as $key => $lookup) {
                if (isset($fetched[$key])) {
                    $this->metaLeafMap[$key] = $fetched[$key];
                    unset($remaining[$key]);
                }
            }
        }

        foreach ($todo as $key => $lookup) {
            $this->metaLeafLoaded[$key] = true;
        }
    }

    private function hashKey(string $tableKey, string $rawHash): string
    {
        return $tableKey . '|' . bin2hex($rawHash);
    }

    private function langsToTry(): array
    {
        return $this->langsToTry ??= $this->languageRegistry->resolutionChain($this->lang);
    }

    private function isResolvableMetaKey(string $metaKey): bool
    {
        return $metaKey === TranslatableMetaKeys::ATTACHMENT_ALT
            || in_array($metaKey, $this->metaKeys(), true);
    }

    private function metaKeys(): array
    {
        return $this->metaKeys ??= TranslatableMetaKeys::keys();
    }

    private function isResolvableTermMetaKey(string $metaKey): bool
    {
        return in_array($metaKey, $this->termMetaKeys(), true);
    }

    private function termMetaKeys(): array
    {
        return $this->termMetaKeys ??= TranslatableTermMetaKeys::keys();
    }
}
