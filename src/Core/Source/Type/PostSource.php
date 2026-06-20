<?php

namespace TransLangs\Core\Source\Type;
use TransLangs\Core\Source\Base\SourceRecord;

use InvalidArgumentException;

/**
 * Type-1 source: a post field, keyed by its whole value (matches the_title /
 * the_content / get_the_excerpt). A post has no context and no source URL — this
 * class passes only its field, so those columns can never be set for a post.
 */
class PostSource extends SourceRecord
{
    private const FIELDS = ['post_title', 'post_content', 'post_excerpt'];

    public function __construct(
        private int    $postId,
        private string $field,
        private string $text
    ) {
        if ($postId <= 0) {
            throw new InvalidArgumentException('PostSource: post id must be positive.');
        }
        if (!in_array($field, self::FIELDS, true)) {
            throw new InvalidArgumentException("PostSource: unsupported field '{$field}'.");
        }
    }

    public function field(): string
    {
        return $this->field;
    }

    public function table(): string
    {
        return 'tl_post';
    }

    public function originalText(): string
    {
        return $this->text;
    }

    public function identity(): array
    {
        return [
            'post_id'       => $this->postId,
            'field'         => $this->field,
            'original_hash' => $this->originalHash(),
        ];
    }
}
