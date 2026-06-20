<?php

namespace TransLangs\Source\Domain;

/**
 * Type-4 source: a gettext string (`__()`, `_e()`, `_x()`, `_n()`). Gettext has
 * no location, so its identity is textual — the key tail is the text hash
 * (handled by TL_Key for type 4). ref1 is the text domain; ref2 the optional
 * gettext context (`_x()`). No owner, no source URL.
 */
final class GettextSource extends SourceRecord
{
    public const DEFAULT_DOMAIN = 'default';

    /** staticText.source token for gettext-originated strings (varchar(20)). */
    public const SOURCE = 'gettext';

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

    public function table(): string
    {
        return 'tl_staticText';
    }

    public function originalText(): string
    {
        return $this->text;
    }

    /** Readable reference (domain|context) stored on the parent, capped to varchar(191). */
    public function reference(): string
    {
        return mb_substr($this->domain . '|' . ($this->context ?? ''), 0, 191);
    }

    public function identity(): array
    {
        return [
            'source'         => self::SOURCE,
            'reference_hash' => hash('sha256', $this->reference(), true),
            'original_hash'  => $this->originalHash(),
        ];
    }

    public function row(): array
    {
        return $this->identity() + [
            'reference'     => $this->reference(),
            'original_text' => $this->originalText(),
        ];
    }
}
