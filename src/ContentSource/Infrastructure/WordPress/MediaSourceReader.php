<?php

namespace TransLangs\ContentSource\Infrastructure\WordPress;

use TransLangs\ContentSource\Domain\SourceReader;
use TransLangs\ContentSource\Domain\SourceRoute;
use TransLangs\ContentSource\Domain\SourceString;

/**
 * Reads translatable text from the Media Library (attachments).
 *
 * Routing per field:
 *   - alt          → postmeta, meta_key _wp_attachment_image_alt, no context
 *   - post_title   → post, field post_title
 *   - post_excerpt → post, field post_excerpt  (caption)
 *   - post_content → post, field post_content  (description)
 */
class MediaSourceReader implements SourceReader
{
    public function key(): string
    {
        return 'media';
    }

    public function isAvailable(): bool
    {
        return function_exists('get_posts');
    }

    /** @return SourceString[] */
    public function read(int $offset, int $limit): array
    {
        $attachments = get_posts([
            'post_type'        => 'attachment',
            'post_status'      => 'inherit',
            'posts_per_page'   => $limit,
            'offset'           => $offset,
            'orderby'          => 'ID',
            'order'            => 'ASC',
            'suppress_filters' => true,
            'fields'           => 'ids',
        ]);

        $strings = [];

        foreach ($attachments as $id) {
            $id = (int) $id;

            $alt = (string) get_post_meta($id, '_wp_attachment_image_alt', true);
            if ($alt !== '') {
                $strings[] = new SourceString(
                    routeTable: SourceRoute::Postmeta,
                    parentId:   $id,
                    field:      null,
                    metaKey:    '_wp_attachment_image_alt',
                    context:    null,
                    source:     null,
                    reference:  null,
                    text:       $alt,
                );
            }

            $post = get_post($id);
            if ($post === null) {
                continue;
            }

            if ((string) $post->post_title !== '') {
                $strings[] = new SourceString(
                    routeTable: SourceRoute::Post,
                    parentId:   $id,
                    field:      'post_title',
                    metaKey:    null,
                    context:    null,
                    source:     null,
                    reference:  null,
                    text:       (string) $post->post_title,
                );
            }
            if ((string) $post->post_excerpt !== '') {
                $strings[] = new SourceString(
                    routeTable: SourceRoute::Post,
                    parentId:   $id,
                    field:      'post_excerpt',
                    metaKey:    null,
                    context:    null,
                    source:     null,
                    reference:  null,
                    text:       (string) $post->post_excerpt,
                );
            }
            if ((string) $post->post_content !== '') {
                $strings[] = new SourceString(
                    routeTable: SourceRoute::Post,
                    parentId:   $id,
                    field:      'post_content',
                    metaKey:    null,
                    context:    null,
                    source:     null,
                    reference:  null,
                    text:       (string) $post->post_content,
                );
            }
        }

        return $strings;
    }
}
