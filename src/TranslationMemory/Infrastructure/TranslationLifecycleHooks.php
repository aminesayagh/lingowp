<?php

namespace TransLangs\TranslationMemory\Infrastructure;

use TransLangs\ContentSource\Domain\SourceRoute;
use TransLangs\TranslationMemory\Application\DiscoverSourceStrings;
use TransLangs\TranslationMemory\Domain\StringHashGenerator;
use TransLangs\TranslationMemory\Domain\StringNormalizer;
use TransLangs\TranslationMemory\Domain\TranslationSourceIdentity;
use TransLangs\TranslationMemory\Infrastructure\Repositories\PostmetaTranslationsRepository;
use TransLangs\TranslationMemory\Infrastructure\Repositories\PostTranslationsRepository;

/**
 * Keeps the translation memory consistent with content changes.
 *
 * Hooks:
 *
 *   before_delete_post
 *     FK cascade on post_id removes rows from wp_tl_translations_post and
 *     wp_tl_translations_postmeta automatically. This hook is an extension
 *     point for plugin-level cache invalidation when persistent caching is added.
 *
 *   pre_delete_term
 *     FK cascade on term_taxonomy_id removes rows from wp_tl_translations_terms
 *     automatically. Extension point for future cache invalidation.
 *
 *   save_post
 *     Re-scans post fields and (for attachments) alt text after each save.
 *     Stale rows (hash no longer present in current field values) are deleted.
 *     Current field values are re-discovered so new translations can be created.
 *     A re-entrancy guard prevents recursive triggers during the write.
 */
final class TranslationLifecycleHooks
{
    /** Meta keys scanned for attachment posts. */
    private const ATTACHMENT_META_KEYS = ['_wp_attachment_image_alt'];

    public function __construct(
        private PostTranslationsRepository    $postRepo,
        private PostmetaTranslationsRepository $postmetaRepo,
        private DiscoverSourceStrings          $discovery,
        private StringNormalizer               $normalizer,
        private StringHashGenerator            $hasher,
    ) {}

    public function register(): void
    {
        add_action('before_delete_post', [$this, 'onBeforeDeletePost'], 10, 1);
        add_action('pre_delete_term',    [$this, 'onPreDeleteTerm'],    10, 2);
        add_action('save_post',          [$this, 'onSavePost'],         10, 1);
    }

    /**
     * Fires before WordPress deletes a post.
     * FK cascade handles translation row deletion; hook reserved for cache invalidation.
     */
    public function onBeforeDeletePost(int $postId): void
    {
        // FK constraint on wp_tl_translations_post.post_id (CASCADE DELETE)
        // and wp_tl_translations_postmeta.post_id (CASCADE DELETE) will remove
        // all translation rows for this post when wp_posts row is deleted.
        // No additional DB work needed here.
    }

    /**
     * Fires before WordPress deletes a term.
     * FK cascade handles translation row deletion; hook reserved for cache invalidation.
     *
     * @param int    $termId    The term_id being deleted (not term_taxonomy_id).
     * @param string $taxonomy  The term's taxonomy.
     */
    public function onPreDeleteTerm(int $termId, string $taxonomy): void
    {
        // FK constraint on wp_tl_translations_terms.term_taxonomy_id (CASCADE DELETE)
        // will remove all translation rows for the term's term_taxonomy_id when the
        // wp_term_taxonomy row is deleted.
        // No additional DB work needed here.
    }

    /**
     * Fires after a post is saved. Re-scans fields and removes stale hashes.
     *
     * The re-entrancy guard (remove_action / add_action) prevents an infinite
     * loop if any code inside this handler triggers another save_post.
     */
    public function onSavePost(int $postId): void
    {
        remove_action('save_post', [$this, 'onSavePost'], 10);

        try {
            $this->rescanPost($postId);
        } finally {
            add_action('save_post', [$this, 'onSavePost'], 10);
        }
    }

    // -------------------------------------------------------------------------

    private function rescanPost(int $postId): void
    {
        $post = get_post($postId);

        if (!$post instanceof \WP_Post) {
            return;
        }

        // Skip revisions and auto-drafts — they are never translatable.
        if ($post->post_type === 'revision' || $post->post_status === 'auto-draft') {
            return;
        }

        // Skip trashed posts; their translation rows stay intact for recovery.
        if ($post->post_status === 'trash') {
            return;
        }

        $postIdentities = $this->buildPostIdentities($post);
        $metaIdentities = $this->buildMetaIdentities($post);

        $postHashes = array_map(
            static fn(TranslationSourceIdentity $i): string => $i->originalHash,
            $postIdentities
        );
        $metaHashes = array_map(
            static fn(TranslationSourceIdentity $i): string => $i->originalHash,
            $metaIdentities
        );

        // Remove rows whose hash is no longer present (content was changed or cleared).
        $this->postRepo->deleteStaleForPost($postId, $postHashes);
        $this->postmetaRepo->deleteStaleForPost($postId, $metaHashes);

        // Discover current field values into every enabled target language.
        $all = array_merge($postIdentities, $metaIdentities);

        if ($all !== []) {
            $this->discovery->discover($all);
        }
    }

    /**
     * Build identities for the three owned post fields (title / content / excerpt).
     *
     * @return TranslationSourceIdentity[]
     */
    private function buildPostIdentities(\WP_Post $post): array
    {
        $identities = [];

        foreach (['post_title', 'post_content', 'post_excerpt'] as $field) {
            $value      = (string) ($post->$field ?? '');
            $normalized = $this->normalizer->normalize($value);

            if ($normalized === '') {
                continue;
            }

            $identities[] = new TranslationSourceIdentity(
                routeTable:     SourceRoute::Post,
                parentId:       (int) $post->ID,
                field:          $field,
                metaKey:        null,
                metaKeyHash:    null,
                context:        null,
                contextHash:    null,
                source:         null,
                reference:      null,
                referenceHash:  null,
                originalText:   $value,
                normalizedText: $normalized,
                originalHash:   $this->hasher->hashNormalized($normalized),
            );
        }

        return $identities;
    }

    /**
     * Build identities for meta fields.
     * Currently only attachment alt text; extend ATTACHMENT_META_KEYS as needed.
     *
     * @return TranslationSourceIdentity[]
     */
    private function buildMetaIdentities(\WP_Post $post): array
    {
        if ($post->post_type !== 'attachment') {
            return [];
        }

        $identities = [];
        $postId     = (int) $post->ID;

        foreach (self::ATTACHMENT_META_KEYS as $metaKey) {
            $value      = (string) get_post_meta($postId, $metaKey, true);
            $normalized = $this->normalizer->normalize($value);

            if ($normalized === '') {
                continue;
            }

            $identities[] = new TranslationSourceIdentity(
                routeTable:     SourceRoute::Postmeta,
                parentId:       $postId,
                field:          null,
                metaKey:        $metaKey,
                metaKeyHash:    $this->hasher->hashMetaKey($metaKey),
                context:        null,
                contextHash:    $this->hasher->hashContext(null),
                source:         null,
                reference:      null,
                referenceHash:  null,
                originalText:   $value,
                normalizedText: $normalized,
                originalHash:   $this->hasher->hashNormalized($normalized),
            );
        }

        return $identities;
    }
}
