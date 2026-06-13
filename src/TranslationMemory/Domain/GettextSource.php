<?php

namespace TransLangs\TranslationMemory\Domain;

/**
 * Type-4 source: a gettext string (`__()`, `_e()`, `_x()`, `_n()`). Gettext has
 * no location, so its identity is textual — the key tail is the text hash
 * (handled by TL_Key for type 4). ref1 is the text domain; ref2 the optional
 * gettext context (`_x()`). No owner, no source URL.
 */
final class GettextSource extends SourceRecord
{
    public const DEFAULT_DOMAIN = 'default';

    private string $domain;

    public function __construct(
        string          $domain,
        private ?string $context,
        private string  $text
    ) {
        $domain = trim($domain);
        if ($domain === '') {
            $domain = self::DEFAULT_DOMAIN;
        }
        // ref1 column is varchar(191).
        $this->domain = mb_substr($domain, 0, 191);
    }

    public function domain(): string
    {
        return $this->domain;
    }

    public function context(): ?string
    {
        return $this->context;
    }

    public function type(): int
    {
        return TL_Key::TYPE_I18N;
    }

    public function ownerId(): ?int
    {
        return null;
    }

    public function originalText(): string
    {
        return $this->text;
    }

    public function row(): array
    {
        return $this->toRow($this->domain, $this->context);
    }
}
