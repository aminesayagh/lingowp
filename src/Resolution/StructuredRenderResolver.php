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

/**
 * Structured render resolution (post, term, meta, user, term_meta).
 *
 * Two lookup models, matching the child-key split:
 *   - post + term + user are HASH-LINK: the original text is on the filter, so
 *     the resolver hashes it and reads a hash → translation map (no parent join).
 *   - meta + term_meta are PARENT_ID: get_post_metadata/get_term_metadata
 *     short-circuit without the original value, so each is matched by location
 *     (post_id or term_id, meta_key_hash) against every discovered leaf under
 *     it, joined to its child. A location with exactly one leaf and no context
 *     (a plain scalar — the common case) returns it directly; a location with
 *     more than one leaf (a repeater, a JSON blob) reads the raw stored value
 *     and patches each translated leaf into a copy of it (PostMetaValuePaths::patch()).
 *   - blockAttributes() is a third, separate mechanism: some blocks store text
 *     only in their own settings (never in real page markup), reached via
 *     WordPress's render_block_data filter rather than the_content — see its
 *     own doc comment and BlockContentAttributes for the full reasoning.
 *

 * Maps are preloaded in batches per WP_Query — never a query per string. A
 * missing entry means untranslated or ignored (ignore deletes the child), so the
 * original text renders. Gettext and the HTML fallback are handled elsewhere.
 */
final class StructuredRenderResolver
{
    use DeferredTranslationActivation;

    /** @var string[]|null memoized: requested language followed by its fallback chain */
    private ?array $langsToTry = null;

    /** @var array<string, string> "tableKey|hex(original_hash)" => translated_text (post/term) */
    private array $hashMap = [];

    /** @var array<string, true> "tableKey|hex(original_hash)" => already-queried */
    private array $hashLoaded = [];

    /** @var array<string, array<string, string>> IdentityKey(meta location) => [context ('' for none) => translated_text] */
    private array $metaLeafMap = [];

    /** @var array<string, true> IdentityKey(meta location) => already-queried */
    private array $metaLeafLoaded = [];

    /** @var string[]|null memoized allow-listed meta keys */
    private ?array $metaKeys = null;

    /** @var string[]|null memoized allow-listed term-meta keys */
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
        // Output escaping happens where a translation is substituted, per context
        // (see hashValue() and content()): a plain value is run through
        // wp_kses_post(), a content unit is rebuilt from the post's own markup
        // with the translated text passed through htmlspecialchars() by the
        // codec. The filter return values are therefore already escaped;
        // escaping them again here would double-encode content.
        add_filter('the_title', [$this, 'title'], 10, 2);
        add_filter('the_content', [$this, 'content'], 10, 1);
        add_filter('render_block_data', [$this, 'blockAttributes'], 10, 1);
        add_filter('get_the_excerpt', [$this, 'excerpt'], 10, 2);
        // wp_get_attachment_caption() reads post_excerpt directly and fires this
        // filter instead of get_the_excerpt — the gallery/[caption] shortcode paths
        // never call get_the_excerpt(), so without this an attachment caption
        // translation is stored but never rendered. excerpt() already resolves
        // purely from its first argument (it ignores $post entirely), so it is
        // plug-compatible with this filter's (string $caption, int $post_id) shape.
        add_filter('wp_get_attachment_caption', [$this, 'excerpt'], 10, 2);
        add_filter('get_post_metadata', [$this, 'metadata'], 10, 4);
        add_filter('get_term_metadata', [$this, 'termMetadata'], 10, 4);
        add_filter('get_term', [$this, 'term'], 10, 2);
        add_filter('get_terms', [$this, 'terms'], 10, 1);
        add_filter('get_the_terms', [$this, 'theTerms'], 10, 1);
        add_filter('get_the_author_description', [$this, 'authorDescription'], 10, 1);
    }

    /** Meta render lookup — identifies a LOCATION (owner + meta key), not one leaf. @return array<string, scalar> */
    private function metaLookup(int $postId, string $metaKey): array
    {
        return [
            'post_id'       => $postId,
            'meta_key_hash' => hash('sha256', $metaKey, true),
        ];
    }

    /** Term-meta render lookup — the term-meta counterpart of metaLookup(). @return array<string, scalar> */
    private function termMetaLookup(int $termId, string $metaKey): array
    {
        return [
            'term_id'       => $termId,
            'meta_key_hash' => hash('sha256', $metaKey, true),
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

        return $this->hashValue('post', (string) $title);
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
            $hashes[] = TranslationKey::currentHash($unit);
        }
        $this->ensureHashLoaded('post', $hashes);

        return $this->unitExtractor->render(
            $content,
            fn(string $unit): ?string => $this->hashMap[$this->hashKey('post', TranslationKey::currentHash($unit))] ?? null
        );
    }

    /**
     * render_block_data fires once per block — including every nested block,
     * not just top-level ones (WP_Block::render() applies it too) —
     * immediately before that block renders. Some blocks (Search, Navigation
     * Link, Post Terms, …) store their whole visible text only in these attrs,
     * never in real page markup, so content() above never sees it (see
     * BlockContentAttributes' class doc). Only meaningful for a dynamic block
     * (one with a render_callback): a static block reproduces its own saved
     * HTML verbatim and ignores attrs entirely, so mutating attrs for one
     * would be a silent no-op — isDynamic() skips those, leaving them to
     * content()'s existing DOM-based swap.
     *
     * @param array<string, mixed> $parsedBlock
     * @return array<string, mixed>
     */
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

        return $this->hashValue('post', (string) $excerpt);
    }

    // ── scalar post meta (parent_id) ──────────────────────────────────────────

    /**
     * get_post_metadata short-circuit. Returns a translation only for an
     * allow-listed key that has one; otherwise returns $value. Never calls
     * get_post_meta() — no recursion (rawPostMetaValue() reads the DB directly
     * when a raw value is needed).
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
        $this->ensureMetaLeavesLoaded([$lookup]);
        $leaves = $this->metaLeafMap[IdentityKey::of($lookup)] ?? [];

        if ($leaves === []) {
            return $value;
        }
        if (count($leaves) === 1 && array_key_exists('', $leaves)) {
            // The common case: a plain scalar value, one whole-value translation.
            return [$leaves['']];
        }

        // A repeater or JSON blob: patch each translated leaf into a copy of
        // the raw stored value — get_post_meta() would recurse into this same
        // filter, so read it directly.
        $raw = $this->repository->rawPostMetaValue($objectId, $metaKey);

        return [PostMetaValuePaths::patch($raw, $leaves)];
    }

    // ── scalar term meta (parent_id) ──────────────────────────────────────────

    /**
     * get_term_metadata short-circuit — the term-meta counterpart of metadata().
     * Returns a translation only for an allow-listed key that has one; otherwise
     * returns $value. Never calls get_term_meta() — no recursion (rawTermMetaValue()
     * reads the DB directly when a raw value is needed).
     *
     * @param mixed $value
     * @return mixed
     */
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
            // The common case: a plain scalar value, one whole-value translation.
            return [$leaves['']];
        }

        // A repeater or JSON blob: patch each translated leaf into a copy of
        // the raw stored value — get_term_meta() would recurse into this same
        // filter, so read it directly.
        $raw = $this->repository->rawTermMetaValue($objectId, $metaKey);

        return [PostMetaValuePaths::patch($raw, $leaves)];
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

    // ── author bio (hash-link) ────────────────────────────────────────────────

    /**
     * get_the_author_description filter — fires from get_the_author_meta('description')
     * / the_author_meta('description'), the documented WP API for the author bio.
     * A theme calling get_user_meta($id, 'description', true) directly bypasses
     * this, same known limitation as the ALT/SEO meta keys not covering every
     * possible reader.
     *
     * @param mixed $description
     * @return mixed
     */
    public function authorDescription($description)
    {
        if (!$this->isActive()) {
            return $description;
        }

        return $this->hashValue('user', (string) $description);
    }

    // ── internals ─────────────────────────────────────────────────────────────

    /**
     * Hash-link lookup: hash the original text, read the map, fall back to original.
     *
     * The translation is sanitized here, at output, and not when it is saved:
     * one stored row can serve both a title (printed raw by the_title()) and
     * an identical paragraph (rebuilt by the codec, which htmlspecialchars()
     * every text node). Sanitizing at save would be double-escaped by the
     * codec; sanitizing at each plain output site is exact. wp_kses_post()
     * is what WordPress itself applies to a post for a user without
     * unfiltered_html — it keeps inline markup, drops scripts and event
     * attributes, and entity-encodes a stray "<" instead of eating it. It
     * also closes the one path where text arrives without a capability
     * check: AI results written by applyQueueResult().
     */
    private function hashValue(string $tableKey, string $original): string
    {
        $hash = TranslationKey::currentHash($original);
        $this->ensureHashLoaded($tableKey, [$hash]);

        $key = $this->hashKey($tableKey, $hash);

        return isset($this->hashMap[$key]) ? wp_kses_post($this->hashMap[$key]) : $original;
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
            $hashes[] = TranslationKey::currentHash((string) $term->name);
            $hashes[] = TranslationKey::currentHash((string) $term->description);
        }
        $this->ensureHashLoaded('term', $hashes);

        foreach ($objects as $term) {
            $nameKey = $this->hashKey('term', TranslationKey::currentHash((string) $term->name));
            $descKey = $this->hashKey('term', TranslationKey::currentHash((string) $term->description));
            if (isset($this->hashMap[$nameKey])) {
                $term->name = wp_kses_post($this->hashMap[$nameKey]); // plain output, see hashValue()
            }
            if (isset($this->hashMap[$descKey])) {
                $term->description = wp_kses_post($this->hashMap[$descKey]);
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

    /**
     * Batch-load every leaf translation for the meta locations not yet seen —
     * same batching shape and cost as before (one query per language hop per
     * not-yet-resolved location, memoized per request), just storing a
     * [context => text] map per location instead of one string.
     *
     * @param array<int, array<string, scalar>> $lookups
     */
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
                    // Every leaf is a plain value patched into the meta as-is
                    // (no codec), so sanitize here once — see hashValue().
                    $this->metaLeafMap[$key] = array_map('wp_kses_post', $fetched[$key]);
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

    /** @return string[] */
    private function langsToTry(): array
    {
        return $this->langsToTry ??= $this->languageRegistry->resolutionChain($this->lang);
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

    private function isResolvableTermMetaKey(string $metaKey): bool
    {
        return in_array($metaKey, $this->termMetaKeys(), true);
    }

    /**
     * @return string[]
     */
    private function termMetaKeys(): array
    {
        return $this->termMetaKeys ??= TranslatableTermMetaKeys::keys();
    }
}
