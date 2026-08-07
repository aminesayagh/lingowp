<?php

namespace TransLangs\Extraction\Structured;

/**
 * Wires save-time structured discovery to WordPress lifecycle hooks (data
 * pipeline §2, Path A).
 *
 * - Posts (types 1-2): wp_after_insert_post (fires once after post, meta AND
 *   terms are persisted; covers attachments).
 * - Meta written outside a post save (importers, frontend forms, cron):
 *   added_post_meta / updated_post_meta, single-key re-scan.
 * - Terms (type 3): created_term / edited_term (both pass term_taxonomy_id).
 *
 * A re-entrancy guard ensures a discovery write can never trigger discovery
 * again within the same request.
 */
final class SourceLifecycleRefresher
{
    private static bool $discovering = false;

    public function __construct(private StructuredSourceScanner $scanner) {}

    public function register(): void
    {
        add_action('wp_after_insert_post', [$this, 'refreshPost'], 10, 4);
        add_action('added_post_meta', [$this, 'refreshMeta'], 10, 4);
        add_action('updated_post_meta', [$this, 'refreshMeta'], 10, 4);
        add_action('deleted_post_meta', [$this, 'refreshMeta'], 10, 4);
        add_action('created_term', [$this, 'refreshTerm'], 10, 3);
        add_action('edited_term', [$this, 'refreshTerm'], 10, 3);
    }

    public function refreshPost(int $postId, mixed $post = null, bool $update = false, mixed $postBefore = null): void
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
    public function refreshMeta(mixed $metaId, int $objectId, string $metaKey, mixed $metaValue): void
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
