<?php

namespace TransLangs\ContentSource\Infrastructure\WordPress;

use TransLangs\ContentSource\Domain\SourceReader;
use TransLangs\ContentSource\Domain\SourceString;
use TransLangs\TranslationMemory\Domain\TranslationSourceContext;
use TransLangs\TranslationMemory\Domain\TranslationSourceType;

/**
 * Reads translatable text from a single post type: pages, posts, or any CPT.
 *
 * One instance per post type. source_type is:
 *   - 'page'/'post'/'product' for the known types, or
 *   - the runtime post type name for any other CPT.
 *
 * Owned fields only:
 *   - post_title    → context "post_title"
 *   - post_content  → context "post_content"
 *   - post_excerpt  → context "post_excerpt"
 *
 * It deliberately does NOT emit the featured image alt (owned by media) or
 * taxonomy term names (owned by taxonomy). Those are referenced, not owned, and
 * are discovered exactly once by their own readers.
 *
 * post_content is emitted as the raw stored content; the rendered-HTML auditor
 * still handles the *displayed* content, but storing the field lets the admin
 * translate it as a unit and gives the render lookup an entity-scoped match.
 */
class PostTypeSourceReader implements SourceReader
{
    public function __construct(private string $postType)
    {
    }

    public function key(): string
    {
        return 'post_type:' . $this->postType;
    }

    public function isAvailable(): bool
    {
        return function_exists('get_posts') && post_type_exists($this->postType);
    }

    /**
     * @return SourceString[]
     */
    public function read(int $offset, int $limit): array
    {
        $posts = get_posts([
            'post_type'        => $this->postType,
            'post_status'      => 'publish',
            'posts_per_page'   => $limit,
            'offset'           => $offset,
            'orderby'          => 'ID',
            'order'            => 'ASC',
            'suppress_filters' => true,
        ]);

        $sourceType = $this->resolveSourceType();
        $strings    = [];

        foreach ($posts as $post) {
            if (!$post instanceof \WP_Post) {
                continue;
            }

            $id = (int) $post->ID;

            foreach ($this->ownedFields($post) as $context => $value) {
                if ($value !== '') {
                    $strings[] = new SourceString($id, $sourceType, $context, $value);
                }
            }
        }

        return $strings;
    }

    /**
     * The fields this reader owns for a post. Subclasses (e.g. WooCommerce) add
     * their own owned fields by overriding/extending this.
     *
     * @return array<string, string> context => value
     */
    protected function ownedFields(\WP_Post $post): array
    {
        return [
            TranslationSourceContext::POST_TITLE   => (string) $post->post_title,
            TranslationSourceContext::POST_CONTENT => (string) $post->post_content,
            TranslationSourceContext::POST_EXCERPT => (string) $post->post_excerpt,
        ];
    }

    /**
     * Map the post type to a source_type. Known types use their constant; any
     * other CPT uses its own runtime name.
     */
    protected function resolveSourceType(): string
    {
        return match ($this->postType) {
            'page'    => TranslationSourceType::PAGE,
            'post'    => TranslationSourceType::POST,
            'product' => TranslationSourceType::PRODUCT,
            default   => $this->postType,
        };
    }
}