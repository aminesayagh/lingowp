<?php

namespace TransLangs\ContentSource\Infrastructure\WordPress;

use TransLangs\ContentSource\Domain\SourceReader;
use TransLangs\ContentSource\Domain\SourceRoute;
use TransLangs\ContentSource\Domain\SourceString;

/**
 * Reads translatable text from a single post type: pages, posts, or any CPT.
 *
 * Owned fields only — post_title, post_content, post_excerpt.
 * Featured image alt (media-owned) and taxonomy names (taxonomy-owned) are
 * not emitted here — they are discovered exactly once by their own readers.
 */
class PostTypeSourceReader implements SourceReader
{
    public function __construct(private string $postType) {}

    public function key(): string
    {
        return 'post_type:' . $this->postType;
    }

    public function isAvailable(): bool
    {
        return function_exists('get_posts') && post_type_exists($this->postType);
    }

    /** @return SourceString[] */
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

        $strings = [];

        foreach ($posts as $post) {
            if (!$post instanceof \WP_Post) {
                continue;
            }

            $id = (int) $post->ID;

            foreach ($this->ownedFields($post) as $field => $value) {
                if ($value !== '') {
                    $strings[] = new SourceString(
                        routeTable: SourceRoute::Post,
                        parentId:   $id,
                        field:      $field,
                        metaKey:    null,
                        context:    null,
                        source:     null,
                        reference:  null,
                        text:       $value,
                    );
                }
            }
        }

        return $strings;
    }

    /**
     * The fields this reader owns for a post.
     *
     * @return array<string, string> field => value
     */
    protected function ownedFields(\WP_Post $post): array
    {
        return [
            'post_title'   => (string) $post->post_title,
            'post_content' => (string) $post->post_content,
            'post_excerpt' => (string) $post->post_excerpt,
        ];
    }
}
