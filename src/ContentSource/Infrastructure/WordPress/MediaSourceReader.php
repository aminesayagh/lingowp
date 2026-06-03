<?php

namespace TransLangs\ContentSource\Infrastructure\WordPress;

use TransLangs\ContentSource\Domain\SourceReader;
use TransLangs\ContentSource\Domain\SourceString;
use TransLangs\TranslationMemory\Domain\TranslationSourceContext;
use TransLangs\TranslationMemory\Domain\TranslationSourceType;

/**
 * Reads translatable text from the Media Library (attachments).
 *
 * In WordPress an attachment stores:
 *   - alt          → meta `_wp_attachment_image_alt`
 *   - title        → post_title
 *   - caption      → post_excerpt
 *   - description  → post_content
 *
 * Each becomes one SourceString with context = the field name. Because media
 * is read here once, no other reader (product, post) re-emits image alt as its
 * own text — alt belongs to the attachment, full stop.
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

    /**
     * @return SourceString[]
     */
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
                $strings[] = new SourceString($id, TranslationSourceType::MEDIA, TranslationSourceContext::MEDIA_ALT, $alt);
            }

            $post = get_post($id);
            if ($post === null) {
                continue;
            }

            if ((string) $post->post_title !== '') {
                $strings[] = new SourceString($id, TranslationSourceType::MEDIA, TranslationSourceContext::MEDIA_TITLE, (string) $post->post_title);
            }
            if ((string) $post->post_excerpt !== '') {
                $strings[] = new SourceString($id, TranslationSourceType::MEDIA, TranslationSourceContext::MEDIA_CAPTION, (string) $post->post_excerpt);
            }
            if ((string) $post->post_content !== '') {
                $strings[] = new SourceString($id, TranslationSourceType::MEDIA, TranslationSourceContext::MEDIA_DESCRIPTION, (string) $post->post_content);
            }
        }

        return $strings;
    }
}