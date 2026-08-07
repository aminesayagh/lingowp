<?php

namespace TransLangs\Resolution;
use TransLangs\Resolution\PageOwnedHashes;
use TransLangs\Resolution\RequestContext;

use TransLangs\Language\Application\ResolveRequestLanguage;
use TransLangs\Language\Domain\LanguageRegistry;
use TransLangs\Shared\Text\IdentityKey;
use TransLangs\Shared\Parsing\HtmlUnitExtractor;
use TransLangs\Database\Repository\WpDbSourceRepository;
use TransLangs\Shared\Text\TL_Key;
use TransLangs\Shared\Source\TranslatableMetaKeys;

/**
 * Structured render resolution (post, term, meta).
 *
 * Two lookup models, matching the child-key split:
 *   - post + term are HASH-LINK: the original text is on the filter, so the
 *     resolver hashes it and reads a hash → translation map (no parent join).
 *   - meta is PARENT_ID: get_post_metadata short-circuits without the original
 *     value, so it is matched by location (post_id, meta_key_hash, context_hash)
 *     against the single discovered parent, joined to its child.
 *
 * Maps are preloaded in batches per WP_Query — never a query per string. A
 * missing entry means untranslated or ignored (ignore deletes the child), so the
 * original text renders. Gettext and the HTML fallback are handled elsewhere.
 */
final class StructuredRenderResolver
{
    private ?bool $active = null;
    private bool $activating = false;
    private string $lang = '';

    /** @var string[] requested language followed by its fallback chain */
    private array $langsToTry = [];

    /** @var array<string, string> "tableKey|hex(original_hash)" => translated_text (post/term) */
    private array $hashMap = [];

    /** @var array<string, true> "tableKey|hex(original_hash)" => already-queried */
    private array $hashLoaded = [];

    /** @var array<string, string> IdentityKey(meta lookup) => translated_text */
    private array $metaMap = [];

    /** @var array<string, true> IdentityKey(meta lookup) => already-queried */
    private array $metaLoaded = [];

    /** @var string[]|null memoized allow-listed meta keys */
    private ?array $metaKeys = null;

    public function __construct(
        private ResolveRequestLanguage $language,
        private RequestContext $context,
        private WpDbSourceRepository $repository,
        private HtmlUnitExtractor $unitExtractor,
        private PageOwnedHashes $pageOwned,
        private LanguageRegistry $languageRegistry
    ) {}

    public function register(): void
    {
        add_filter('the_posts', [$this, 'preloadPosts'], 10, 2);
        add_filter('the_title', [$this, 'title'], 10, 2);
        add_filter('the_content', [$this, 'content'], 10, 1);
        add_filter('get_the_excerpt', [$this, 'excerpt'], 10, 2);
        // wp_get_attachment_caption() reads post_excerpt directly and fires this
        // filter instead of get_the_excerpt — the gallery/[caption] shortcode paths
        // never call get_the_excerpt(), so without this an attachment caption
        // translation is stored but never rendered. excerpt() already resolves
        // purely from its first argument (it ignores $post entirely), so it is
        // plug-compatible with this filter's (string $caption, int $post_id) shape.
        add_filter('wp_get_attachment_caption', [$this, 'excerpt'], 10, 2);
        add_filter('get_post_metadata', [$this, 'metadata'], 10, 4);
        add_filter('get_term', [$this, 'term'], 10, 2);
        add_filter('get_terms', [$this, 'terms'], 10, 1);
        add_filter('get_the_terms', [$this, 'theTerms'], 10, 1);
    }

    /** Scalar meta render lookup — matched by location (no original text here). @return array<string, scalar> */
    private function metaLookup(int $postId, string $metaKey): array
    {
        return [
            'post_id'       => $postId,
            'meta_key_hash' => hash('sha256', $metaKey, true),
            'context_hash'  => hash('sha256', '', true),
        ];
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

        $postHashes  = [];
        $metaLookups = [];
        foreach ($posts as $post) {
            if (!$post instanceof \WP_Post) {
                continue;
            }
            $postHashes[] = TL_Key::currentHash((string) $post->post_title);
            $postHashes[] = TL_Key::currentHash((string) $post->post_excerpt);
            foreach ($this->unitExtractor->units((string) $post->post_content) as $unit) {
                $postHashes[] = TL_Key::currentHash($unit);
            }
            foreach ($this->metaKeys() as $metaKey) {
                $metaLookups[] = $this->metaLookup($post->ID, $metaKey);
            }
        }

        $this->ensureHashLoaded('tl_post', $postHashes);
        $this->ensureMetaLoaded($metaLookups);

        return $posts;
    }

    // ── post fields (hash-link) ───────────────────────────────────────────────

    /**
     * @param mixed $title
     * @return mixed
     */
    public function title($title, $postId = null)
    {
        if (!$this->isActive()) {
            return $title;
        }

        return $this->hashValue('tl_post', (string) $title);
    }

    /**
     * Post content is split into block-level pieces; each is translated by its
     * own text hash and reassembled in place. Scaffolding/wrappers pass through.
     *
     * @param mixed $content
     * @return mixed
     */
    public function content($content)
    {
        if (!$this->isActive()) {
            return $content;
        }

        $content = (string) $content;

        $hashes = [];
        foreach ($this->unitExtractor->units($content) as $unit) {
            $hashes[] = TL_Key::currentHash($unit);
        }
        $this->ensureHashLoaded('tl_post', $hashes);

        return $this->unitExtractor->render(
            $content,
            fn(string $unit): ?string => $this->hashMap[$this->hashKey('tl_post', TL_Key::currentHash($unit))] ?? null
        );
    }

    /**
     * @param mixed $excerpt
     * @return mixed
     */
    public function excerpt($excerpt, $post = null)
    {
        unset($post);

        if (!$this->isActive()) {
            return $excerpt;
        }

        return $this->hashValue('tl_post', (string) $excerpt);
    }

    // ── scalar post meta (parent_id) ──────────────────────────────────────────

    /**
     * get_post_metadata short-circuit. Returns a translation only for an
     * allow-listed key that has one; otherwise returns $value. Never calls
     * get_post_meta() — no recursion.
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

        $lookup = $this->metaLookup($objectId, $metaKey);
        $this->ensureMetaLoaded([$lookup]);
        $key = IdentityKey::of($lookup);

        // Wrap in an array; core unwraps to a scalar when $single is true.
        return isset($this->metaMap[$key]) ? [$this->metaMap[$key]] : $value;
    }

    // ── terms (hash-link) ─────────────────────────────────────────────────────

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

    /** Hash-link lookup: hash the original text, read the map, fall back to original. */
    private function hashValue(string $tableKey, string $original): string
    {
        $hash = TL_Key::currentHash($original);
        $this->ensureHashLoaded($tableKey, [$hash]);

        return $this->hashMap[$this->hashKey($tableKey, $hash)] ?? $original;
    }

    /**
     * Replace name/description on every WP_Term, batching the hash lookup.
     *
     * @param array<int, mixed> $terms
     */
    private function resolveTerms(array $terms): void
    {
        $objects = array_filter($terms, static fn($t): bool => $t instanceof \WP_Term);
        if ($objects === []) {
            return;
        }

        $hashes = [];
        foreach ($objects as $term) {
            $hashes[] = TL_Key::currentHash((string) $term->name);
            $hashes[] = TL_Key::currentHash((string) $term->description);
        }
        $this->ensureHashLoaded('tl_term', $hashes);

        foreach ($objects as $term) {
            $nameKey = $this->hashKey('tl_term', TL_Key::currentHash((string) $term->name));
            $descKey = $this->hashKey('tl_term', TL_Key::currentHash((string) $term->description));
            if (isset($this->hashMap[$nameKey])) {
                $term->name = $this->hashMap[$nameKey];
            }
            if (isset($this->hashMap[$descKey])) {
                $term->description = $this->hashMap[$descKey];
            }
        }
    }

    /**
     * Batch-load hash-link translations for the hashes not yet seen this request.
     *
     * @param string[] $rawHashes
     */
    private function ensureHashLoaded(string $tableKey, array $rawHashes): void
    {
        $todo = [];
        foreach ($rawHashes as $hash) {
            // Tier-1 ownership: this text is owned by a structured source, so
            // gettext capture must not also claim it as static text.
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
        foreach ($this->langsToTry as $lang) {
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

    /**
     * Batch-load meta (parent_id) translations for the lookups not yet seen.
     *
     * @param array<int, array<string, scalar>> $lookups
     */
    private function ensureMetaLoaded(array $lookups): void
    {
        $todo = [];
        foreach ($lookups as $lookup) {
            $key = IdentityKey::of($lookup);
            if (!isset($this->metaLoaded[$key])) {
                $todo[$key] = $lookup;
            }
        }
        if ($todo === []) {
            return;
        }

        $remaining = $todo;
        foreach ($this->langsToTry as $lang) {
            if ($remaining === []) {
                break;
            }
            $fetched = $this->repository->fetchParentTranslations('tl_meta', array_values($remaining), $lang);
            foreach ($remaining as $key => $lookup) {
                if (isset($fetched[$key])) {
                    $this->metaMap[$key] = $fetched[$key];
                    unset($remaining[$key]);
                }
            }
        }

        foreach ($todo as $key => $lookup) {
            $this->metaLoaded[$key] = true;
        }
    }

    private function hashKey(string $tableKey, string $rawHash): string
    {
        return $tableKey . '|' . bin2hex($rawHash);
    }

    private function isActive(): bool
    {
        if ($this->active !== null) {
            return $this->active;
        }

        // shouldTranslate()/isTranslatedRequest() read the main query (conditional
        // tags, the resolved language) which does not exist until the `wp` action.
        // Structured filters fire earlier — the MAIN query's `the_posts` runs
        // inside WP::main() BEFORE `wp`, and get_term(s) fire during menu/widget
        // setup. Returning a verdict then would memoize "untranslated" for the
        // whole request. Defer without memoizing until the query is up (mirrors
        // GettextResolver); the re-entrancy guard covers a filter firing while we
        // are still deciding.
        if (!did_action('wp') || $this->activating) {
            return false;
        }

        $this->activating = true;
        try {
            $active = $this->context->shouldTranslate() && $this->language->isTranslatedRequest();
        } finally {
            $this->activating = false;
        }

        $this->active = $active;
        if ($active) {
            $this->lang       = $this->language->resolve();
            $this->langsToTry = array_merge([$this->lang], $this->languageRegistry->fallbackChain($this->lang));
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
