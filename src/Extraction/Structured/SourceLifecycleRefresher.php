<?php

namespace LingoWP\Extraction\Structured;

use LingoWP\Shared\Source\TranslatableTermMetaKeys;
use LingoWP\Shared\Source\Type\UserSource;

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

    public function refreshMeta($metaId, int $objectId, string $metaKey, $metaValue): void
    {
        unset($metaId, $metaValue);

        if ($objectId <= 0 || $metaKey === '') {
            return;
        }

        $this->guard(fn() => $this->scanner->refreshPostMetaKey($objectId, $metaKey));
    }

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

    public function refreshUserMeta($metaId, int $objectId, string $metaKey, $metaValue): void
    {
        unset($metaId, $metaValue);

        if ($objectId <= 0 || !in_array($metaKey, UserSource::allowedFields(), true)) {
            return;
        }

        $this->guard(fn() => $this->scanner->refreshUser($objectId));
    }

    public function refreshTermMetaKey($metaId, int $objectId, string $metaKey, $metaValue): void
    {
        unset($metaId, $metaValue);

        if ($objectId <= 0 || !in_array($metaKey, TranslatableTermMetaKeys::keys(), true)) {
            return;
        }

        $this->guard(fn() => $this->scanner->refreshTermMeta($objectId));
    }

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
