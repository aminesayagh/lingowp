<?php

namespace TransLangs\PublicSite\Infrastructure;

use TransLangs\Language\Application\ResolveRequestLanguage;
use TransLangs\TranslationMemory\Domain\SourceTranslationRepository;
use TransLangs\TranslationMemory\Domain\TL_Key;
use TransLangs\TranslationMemory\Domain\TranslatableMetaKeys;

/**
 * v4 structured render resolution (data pipeline §3, types 1–3).
 *
 * Resolves text BY LOCATION (D26): WordPress filter hooks supply the identity
 * (post id + field, meta key, term), the key is recomputed via TL_Key, and the
 * translation is read from a request-local map preloaded in batches — never a
 * query per string, never a join on the hot path.
 *
 * Staleness policy is the default "stale-but-shown": a produced translation is
 * always returned when present, so no current_hash comparison is needed here
 * (the admin queue surfaces edited-since-translated rows separately).
 *
 * Gettext (type 4) and the HTML output-buffer fallback (type 5) are handled
 * elsewhere; this class only touches structured content.
 */
final class StructuredRenderResolver
{
    private ?bool $active = null;
    private string $lang = '';

    /** @var array<string, string> hex(source_key) => translated_text */
    private array $map = [];

    /** @var array<string, true> hex(source_key) => already-queried (negative cache) */
    private array $loaded = [];

    /** @var string[]|null memoized allow-listed meta keys */
    private ?array $metaKeys = null;

    public function __construct(
        private ResolveRequestLanguage $language,
        private RequestContext $context,
        private SourceTranslationRepository $repository
    ) {}

    public function register(): void
    {
        add_filter('the_posts', [$this, 'preloadPosts'], 10, 2);
        add_filter('the_title', [$this, 'title'], 10, 2);
        add_filter('the_content', [$this, 'content'], 10, 1);
        add_filter('get_the_excerpt', [$this, 'excerpt'], 10, 2);
        add_filter('get_post_metadata', [$this, 'metadata'], 10, 4);
        add_filter('get_term', [$this, 'term'], 10, 2);
        add_filter('get_terms', [$this, 'terms'], 10, 1);
        add_filter('get_the_terms', [$this, 'theTerms'], 10, 1);
    }

    // ── preload (one batch per WP_Query) ──────────────────────────────────────

    /**
     * @param mixed $posts
     * @return mixed
     */
    public function preloadPosts($posts, $query = null)
    {
        unset($query);

        if (!$this->isActive() || !is_array($posts)) {
            return $posts;
        }

        $keys = [];
        foreach ($posts as $post) {
            if (!$post instanceof \WP_Post) {
                continue;
            }
            foreach (['post_title', 'post_content', 'post_excerpt'] as $field) {
                $keys[] = TL_Key::make(TL_Key::TYPE_POST, $post->ID, $field, null, '');
            }
            foreach ($this->metaKeys() as $metaKey) {
                $keys[] = TL_Key::make(TL_Key::TYPE_POSTMETA, $post->ID, $metaKey, null, '');
            }
        }

        $this->ensureKeysLoaded($keys);

        return $posts;
    }

    // ── type 1: post fields ───────────────────────────────────────────────────

    /**
     * @param mixed $title
     * @return mixed
     */
    public function title($title, $postId = null)
    {
        $id = (int) $postId;
        if ($id <= 0) {
            return $title;
        }

        return $this->translate(TL_Key::TYPE_POST, $id, 'post_title', (string) $title);
    }

    /**
     * Post content is one whole-field type-1 source. The resolved output is
     * wrapped in a data-tl-region marker so the type-5 parser skips the whole
     * post body (it is already handled here), per the parsing spec §4/P5.
     *
     * @param mixed $content
     * @return mixed
     */
    public function content($content)
    {
        if (!$this->isActive() || !function_exists('get_the_ID')) {
            return $content;
        }
        $id = (int) get_the_ID();
        if ($id <= 0) {
            return $content;
        }

        $out = $this->translate(TL_Key::TYPE_POST, $id, 'post_content', (string) $content);

        return '<div data-tl-region="content">' . $out . '</div>';
    }

    /**
     * @param mixed $excerpt
     * @return mixed
     */
    public function excerpt($excerpt, $post = null)
    {
        $id = $post instanceof \WP_Post
            ? $post->ID
            : (function_exists('get_the_ID') ? (int) get_the_ID() : 0);
        if ($id <= 0) {
            return $excerpt;
        }

        return $this->translate(TL_Key::TYPE_POST, $id, 'post_excerpt', (string) $excerpt);
    }

    // ── type 2: scalar post meta ──────────────────────────────────────────────

    /**
     * get_post_metadata short-circuit. Returns a translation only for an
     * allow-listed key that has one; otherwise returns $value (null) so WordPress
     * fetches the original. Never calls get_post_meta() — no recursion.
     *
     * @param mixed $value
     * @return mixed
     */
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

        $key = TL_Key::make(TL_Key::TYPE_POSTMETA, $objectId, $metaKey, null, '');
        $this->ensureKeysLoaded([$key]);
        $hex = bin2hex($key);

        // Wrap in an array; core unwraps to a scalar when $single is true.
        return isset($this->map[$hex]) ? [$this->map[$hex]] : $value;
    }

    // ── type 3: terms ─────────────────────────────────────────────────────────

    /**
     * @param mixed $term
     * @return mixed
     */
    public function term($term, $taxonomy = '')
    {
        unset($taxonomy);

        if ($this->isActive() && $term instanceof \WP_Term) {
            $this->resolveTerms([$term]);
        }

        return $term;
    }

    /**
     * @param mixed $terms
     * @return mixed
     */
    public function terms($terms)
    {
        if ($this->isActive() && is_array($terms)) {
            $this->resolveTerms($terms);
        }

        return $terms;
    }

    /**
     * @param mixed $terms
     * @return mixed
     */
    public function theTerms($terms)
    {
        if ($this->isActive() && is_array($terms)) {
            $this->resolveTerms($terms);
        }

        return $terms;
    }

    // ── internals ─────────────────────────────────────────────────────────────

    private function translate(int $type, int $ownerId, string $field, string $original): string
    {
        if (!$this->isActive()) {
            return $original;
        }

        $key = TL_Key::make($type, $ownerId, $field, null, '');
        $this->ensureKeysLoaded([$key]);

        return $this->map[bin2hex($key)] ?? $original;
    }

    /**
     * Replace name/description on every WP_Term in the list, batching the lookup.
     *
     * @param array<int, mixed> $terms
     */
    private function resolveTerms(array $terms): void
    {
        $objects = array_filter($terms, static fn($t): bool => $t instanceof \WP_Term);
        if ($objects === []) {
            return;
        }

        $keys = [];
        foreach ($objects as $term) {
            $tt = (int) ($term->term_taxonomy_id ?? 0);
            if ($tt <= 0) {
                continue;
            }
            $keys[] = TL_Key::make(TL_Key::TYPE_TERMS, $tt, 'name', null, '');
            $keys[] = TL_Key::make(TL_Key::TYPE_TERMS, $tt, 'description', null, '');
        }
        $this->ensureKeysLoaded($keys);

        foreach ($objects as $term) {
            $tt = (int) ($term->term_taxonomy_id ?? 0);
            if ($tt <= 0) {
                continue;
            }
            $nameHex = bin2hex(TL_Key::make(TL_Key::TYPE_TERMS, $tt, 'name', null, ''));
            $descHex = bin2hex(TL_Key::make(TL_Key::TYPE_TERMS, $tt, 'description', null, ''));
            if (isset($this->map[$nameHex])) {
                $term->name = $this->map[$nameHex];
            }
            if (isset($this->map[$descHex])) {
                $term->description = $this->map[$descHex];
            }
        }
    }

    /**
     * Query the keys not yet seen this request; cache hits AND misses.
     *
     * @param string[] $rawKeys
     */
    private function ensureKeysLoaded(array $rawKeys): void
    {
        $todo = [];
        foreach ($rawKeys as $raw) {
            $hex = bin2hex($raw);
            if (!isset($this->loaded[$hex])) {
                $todo[$hex] = $raw;
            }
        }
        if ($todo === []) {
            return;
        }

        $fetched = $this->repository->fetchTranslations(array_values($todo), $this->lang);

        foreach ($todo as $hex => $raw) {
            $this->loaded[$hex] = true;
            if (isset($fetched[$hex])) {
                $this->map[$hex] = $fetched[$hex];
            }
        }
    }

    private function isActive(): bool
    {
        if ($this->active !== null) {
            return $this->active;
        }

        $this->active = $this->context->shouldTranslate() && $this->language->isTranslatedRequest();
        if ($this->active) {
            $this->lang = $this->language->resolve();
        }

        return $this->active;
    }

    private function isResolvableMetaKey(string $metaKey): bool
    {
        return $metaKey === TranslatableMetaKeys::ATTACHMENT_ALT
            || in_array($metaKey, $this->metaKeys(), true);
    }

    /**
     * @return string[]
     */
    private function metaKeys(): array
    {
        return $this->metaKeys ??= TranslatableMetaKeys::keys();
    }
}
