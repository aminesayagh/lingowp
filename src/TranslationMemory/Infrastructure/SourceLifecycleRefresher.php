<?php

namespace TransLangs\TranslationMemory\Infrastructure;

/**
 * Wires save-time structured discovery to WordPress lifecycle hooks (§5.5, D24).
 *
 * Posts (types 1-2) refresh on wp_after_insert_post — it fires once after the
 * post, its meta AND its terms are persisted. Terms (type 3) refresh on
 * created_term / edited_term, which both hand us the term_taxonomy_id directly.
 */
final class SourceLifecycleRefresher
{
    public function __construct(private StructuredSourceScanner $scanner) {}

    public function register(): void
    {
        add_action('wp_after_insert_post', [$this, 'refreshPost'], 10, 4);
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

        $this->scanner->refreshPost($postId);
    }

    /**
     * created_term / edited_term both pass ($term_id, $tt_id, $taxonomy). We key
     * type-3 sources by term_taxonomy_id, so $tt_id is used directly; name and
     * description are read from the freshly saved term.
     */
    public function refreshTerm(int $termId, int $termTaxonomyId, string $taxonomy = ''): void
    {
        unset($taxonomy);

        if ($termTaxonomyId <= 0 || !function_exists('get_term')) {
            return;
        }

        $term = get_term($termId);
        if (!$term instanceof \WP_Term) {
            return;
        }

        $this->scanner->refreshTerm(
            $termTaxonomyId,
            (string) $term->name,
            (string) $term->description
        );
    }
}
