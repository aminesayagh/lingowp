<?php

namespace LingoWP\Shared\Source\Type;
use LingoWP\Shared\Source\SourceRecord;

use InvalidArgumentException;

/**
 * Type-2 source: a post meta value, identified by post + meta key. The optional
 * context is the path inside a serialized / repeater / JSON value (e.g.
 * "slides.0.title"); null for a scalar value. Postmeta has no source URL.
 */
final class PostMetaSource extends SourceRecord
{
    public function __construct(
        private int     $postId,
        private string  $metaKey,
        private ?string $context,
        private string  $text
    ) {
        if ($postId <= 0) {
            throw new InvalidArgumentException('PostMetaSource: post id must be positive.');
        }
        if ($metaKey === '') {
            throw new InvalidArgumentException('PostMetaSource: meta key is required.');
        }
    }

    public function metaKey(): string
    {
        return $this->metaKey;
    }

    public function context(): ?string
    {
        return $this->context;
    }

    public function table(): string
    {
        return 'meta';
    }

    public function originalText(): string
    {
        return $this->text;
    }

    public function identity(): array
    {
        return [
            'post_id'       => $this->postId,
            'meta_key_hash' => hash('sha256', $this->metaKey, true),
            'context_hash'  => hash('sha256', $this->context ?? '', true),
            'original_hash' => $this->originalHash(),
        ];
    }

    public function row(): array
    {
        return $this->identity() + [
            'meta_key'      => $this->metaKey,
            'context'       => $this->context,
            'original_text' => $this->originalText(),
        ];
    }
}
