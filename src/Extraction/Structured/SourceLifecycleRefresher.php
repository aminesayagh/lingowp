<?php

namespace LingoWP\Extraction\Structured;

use LingoWP\Shared\Source\TranslatableTermMetaKeys;

/**
 * Wires save-time structured discovery to WordPress lifecycle hooks (data
 * pipeline §2, Path A).
 *
 * - Posts (types 1-2): wp_after_insert_post (fires once after post, meta AND
 *   terms are persisted). Does NOT cover attachments — WordPress core
 *   deliberately returns before firing it for post_type=attachment (see
 *   wp_insert_post()'s dedicated add_attachment/edit_attachment branch), so
 *   those are separately hooked below with the same handler.
 * - Attachments (also types 1-2, since an attachment's title/caption/
 *   description are the same POST_FIELDS as any other post): add_attachment /
 *   edit_attachment, WordPress's own attachment-specific equivalents of the
 *   hook above — reuses refreshPost() as-is.
 * - Meta written outside a post save (importers, frontend forms, cron):
 *   added_post_meta / updated_post_meta, single-key re-scan.
 * - Terms (type 3): created_term / edited_term (both pass term_taxonomy_id).
 * - Author bio (type 4): added_user_meta / updated_user_meta / deleted_user_meta,
 *   filtered to the 'description' key — wp_insert_user()/wp_update_user() write
 *   it through update_user_meta(), so these already cover profile saves.
 * - Term meta (type 5): added_term_meta / updated_term_meta / deleted_term_meta,
 *   filtered to TranslatableTermMetaKeys::keys() — a site opts a key in via the
 *   `lingowp_translatable_term_meta_keys` filter.
 *
 * A re-entrancy guard ensures a discovery write can never trigger discovery
 * again within the same request.
 */
final class SourceLifecycleRefresher
{
    private static bool $discovering = false;

    private StructuredSourceScanner $scanner;

    public function __construct(StructuredSourceScanner $scanner)
    {
        $this->scanner = $scanner;
    }

    public function register(): void
    {
        add_action('wp_after_insert_post', [$this, 'refreshPost'], 10, 4);
        add_action('add_attachment', [$this, 'refreshPost'], 10, 1);
        add_action('edit_attachment', [$this, 'refreshPost'], 10, 1);
        add_action('added_post_meta', [$this, 'refreshMeta'], 10, 4);
        add_action('updated_post_meta', [$this, 'refreshMeta'], 10, 4);
        add_action('deleted_post_meta', [$this, 'refreshMeta'], 10, 4);
        add_action('created_term', [$this, 'refreshTerm'], 10, 3);
        add_action('edited_term', [$this, 'refreshTerm'], 10, 3);
        add_action('added_user_meta', [$this, 'refreshUserMeta'], 10, 4);
        add_action('updated_user_meta', [$this, 'refreshUserMeta'], 10, 4);
        add_action('deleted_user_meta', [$this, 'refreshUserMeta'], 10, 4);
        add_action('added_term_meta', [$this, 'refreshTermMetaKey'], 10, 4);
        add_action('updated_term_meta', [$this, 'refreshTermMetaKey'], 10, 4);
        add_action('deleted_term_meta', [$this, 'refreshTermMetaKey'], 10, 4);
    }

    public function refreshPost(int $postId, $post = null, bool $update = false, $postBefore = null): void
    {
        unset($post, $update, $postBefore);

        if ($postId <= 0) {
            return;
        }

        if (function_exists('wp_is_post_autosave') && wp_is_post_autosave($postId)) {
            return;
        }

        $revisionParent = function_exists('wp_is_post_revision') ? (int) wp_is_post_revision($postId) : 0;
        if ($revisionParent > 0) {
            $postId = $revisionParent;
        }

        $this->guard(fn() => $this->scanner->refreshPost($postId));
    }

    /**
     * added_post_meta / updated_post_meta / deleted_post_meta all pass
     * ($meta_id(s), $object_id, $meta_key, $meta_value). Re-scanning the key
     * after a deletion finds no current value, so reconciliation marks its
     * parents stale (translations are kept, not deleted); the scanner decides
     * whether the key is discoverable in the first place.
     */
    public function refreshMeta($metaId, int $objectId, string $metaKey, $metaValue): void
    {
        unset($metaId, $metaValue);

        if ($objectId <= 0 || $metaKey === '') {
            return;
        }

        $this->guard(fn() => $this->scanner->refreshPostMetaKey($objectId, $metaKey));
    }

    /**
     * created_term / edited_term pass ($term_id, $tt_id, $taxonomy). We key
     * type-3 sources by term_taxonomy_id; name/description are read from the
     * freshly saved term.
     */
    public function refreshTerm(int $termId, int $termTaxonomyId, string $taxonomy = ''): void
    {
        if ($termTaxonomyId <= 0 || !function_exists('get_term')) {
            return;
        }

        $term = get_term($termId);
        if (!$term instanceof \WP_Term) {
            return;
        }

        $this->guard(fn() => $this->scanner->refreshTerm(
            $termTaxonomyId,
            $taxonomy !== '' ? $taxonomy : (string) $term->taxonomy,
            (string) $term->name,
            (string) $term->description
        ));
    }

    /**
     * added_user_meta / updated_user_meta / deleted_user_meta share the same
     * ($meta_id, $object_id, $meta_key, $meta_value) shape as the post-meta
     * hooks above. Only 'description' (the author bio) is scannable today.
     */
    public function refreshUserMeta($metaId, int $objectId, string $metaKey, $metaValue): void
    {
        unset($metaId, $metaValue);

        if ($objectId <= 0 || $metaKey !== 'description') {
            return;
        }

        $this->guard(fn() => $this->scanner->refreshUser($objectId));
    }

    /**
     * added_term_meta / updated_term_meta / deleted_term_meta share the same
     * ($meta_id, $object_id, $meta_key, $meta_value) shape as the post-meta
     * hooks above. $object_id here is the term_id, matching get_term_meta()'s
     * own relationship (not term_taxonomy_id, which wp_lingowp_term uses).
     */
    public function refreshTermMetaKey($metaId, int $objectId, string $metaKey, $metaValue): void
    {
        unset($metaId, $metaValue);

        if ($objectId <= 0 || !in_array($metaKey, TranslatableTermMetaKeys::keys(), true)) {
            return;
        }

        $this->guard(fn() => $this->scanner->refreshTermMeta($objectId));
    }

    /** Run discovery work once, never re-entering it within the same request. */
    private function guard(callable $work): void
    {
        if (self::$discovering) {
            return;
        }

        self::$discovering = true;
        try {
            $work();
        } finally {
            self::$discovering = false;
        }
    }
}
