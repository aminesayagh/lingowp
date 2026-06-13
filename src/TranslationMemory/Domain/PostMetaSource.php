<?php

namespace TransLangs\TranslationMemory\Domain;

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

    public function type(): int
    {
        return TL_Key::TYPE_POSTMETA;
    }

    public function ownerId(): ?int
    {
        return $this->postId;
    }

    public function originalText(): string
    {
        return $this->text;
    }

    public function row(): array
    {
        return $this->toRow($this->metaKey, $this->context);
    }
}
