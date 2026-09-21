<?php

namespace LingoWP\Shared\Source\Type;
use LingoWP\Shared\Source\SourceRecord;

use InvalidArgumentException;

class PostSource extends SourceRecord
{
    private const FIELDS = ['post_title', 'post_content', 'post_excerpt'];

    private int    $postId;
    private string $field;
    private string $text;

    public function __construct(
        int    $postId,
        string $field,
        string $text
    ) {
        $this->postId = $postId;
        $this->field  = $field;
        $this->text   = $text;

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
        return 'post';
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
