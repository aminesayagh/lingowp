<?php

namespace LingoWP\Shared\Source;
use LingoWP\Shared\Source\Type\PostSource;
use LingoWP\Shared\Source\Type\PostMetaSource;
use LingoWP\Shared\Text\TranslationKey;
use LingoWP\Shared\Text\IdentityKey;

abstract class SourceRecord
{
    abstract public function table(): string;

    abstract public function originalText(): string;

    abstract public function identity(): array;

    final public function originalHash(): string
    {
        return TranslationKey::currentHash($this->originalText());
    }

    public function row(): array
    {
        return $this->identity() + ['original_text' => $this->originalText()];
    }

    final public function dedupeKey(): string
    {
        return $this->table() . ':' . IdentityKey::of($this->identity());
    }
}
