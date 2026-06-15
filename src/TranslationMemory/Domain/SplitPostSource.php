<?php

namespace TransLangs\TranslationMemory\Domain;

use InvalidArgumentException;

/**
 * One block-level piece of a split rich-HTML post field (post_content), per
 * the split-field spec. Type 1 like its parent post, but TEXT-identity: the key
 * is text-tailed, so editing a paragraph yields a new key (that piece falls back
 * to original until re-translated) while siblings are untouched. ref2 / source
 * URL never apply — only the field and the piece's unit string are passed.
 *
 * `originalText()` is the encoded unit string (inline tags as `{n}` placeholders),
 * exactly what discovery stores and render re-derives.
 */
final class SplitPostSource extends SourceRecord
{
    public function __construct(
        private int    $postId,
        private string $field,
        private string $unit
    ) {
        if ($postId <= 0) {
            throw new InvalidArgumentException('SplitPostSource: post id must be positive.');
        }
        if ($field === '') {
            throw new InvalidArgumentException('SplitPostSource: field is required.');
        }
    }

    public function field(): string
    {
        return $this->field;
    }

    public function type(): int
    {
        return TL_Key::TYPE_POST;
    }

    public function ownerId(): ?int
    {
        return $this->postId;
    }

    public function originalText(): string
    {
        return $this->unit;
    }

    public function row(): array
    {
        return $this->toRow($this->field);
    }

    protected function buildKey(string $ref1, ?string $ref2): string
    {
        return TL_Key::makeSplit($this->postId, $ref1, $this->unit);
    }
}
