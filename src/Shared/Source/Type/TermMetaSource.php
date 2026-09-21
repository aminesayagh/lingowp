<?php

namespace LingoWP\Shared\Source\Type;
use LingoWP\Shared\Source\SourceRecord;

use InvalidArgumentException;

final class TermMetaSource extends SourceRecord
{
    private int     $termId;
    private string  $metaKey;
    private ?string $context;
    private string  $text;

    public function __construct(
        int     $termId,
        string  $metaKey,
        ?string $context,
        string  $text
    ) {
        $this->termId  = $termId;
        $this->metaKey = $metaKey;
        $this->context = $context;
        $this->text    = $text;

        if ($termId <= 0) {
            throw new InvalidArgumentException('TermMetaSource: term id must be positive.');
        }
        if ($metaKey === '') {
            throw new InvalidArgumentException('TermMetaSource: meta key is required.');
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
        return 'term_meta';
    }

    public function originalText(): string
    {
        return $this->text;
    }

    public function identity(): array
    {
        return [
            'term_id'       => $this->termId,
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
