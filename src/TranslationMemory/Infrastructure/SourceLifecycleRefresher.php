<?php

namespace TransLangs\TranslationMemory\Infrastructure;

final class SourceLifecycleRefresher
{
    public function __construct(private StructuredSourceScanner $scanner) {}

    public function register(): void
    {
        add_action('wp_after_insert_post', [$this, 'refreshPost'], 10, 4);
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

        $this->scanner->refreshPost($postId);
    }
}
