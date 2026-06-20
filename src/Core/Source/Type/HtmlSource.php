<?php

namespace TransLangs\Core\Source\Type;
use TransLangs\Core\Source\Base\SourceRecord;

use InvalidArgumentException;

/**
 * Type-5 source: a rendered-HTML unit, identified by its DOM selector and text.
 * Identity is (selector_hash, original_hash): selector_hash covers the full
 * selector so uniqueness never depends on a truncated prefix, and original_hash
 * lets the same selector hold distinct units. No WordPress content FK; source_url
 * is kept readable for administration only.
 */
final class HtmlSource extends SourceRecord
{
    /** selector column is varchar(255). */
    public const SELECTOR_MAX_LENGTH = 255;

    private string $selector;

    public function __construct(
        string          $selector,
        private ?string $sourceUrl,
        private string  $text
    ) {
        $selector = trim($selector);
        if ($selector === '') {
            throw new InvalidArgumentException('HtmlSource: selector is required.');
        }

        // Keep the rightmost (most specific) characters, matching HtmlRenderer's
        // selector capping so discovery and render hash the same string.
        $this->selector = strlen($selector) > self::SELECTOR_MAX_LENGTH
            ? substr($selector, -self::SELECTOR_MAX_LENGTH)
            : $selector;
    }

    public function selector(): string
    {
        return $this->selector;
    }

    public function sourceUrl(): ?string
    {
        return $this->sourceUrl;
    }

    public function table(): string
    {
        return 'tl_html';
    }

    public function originalText(): string
    {
        return $this->text;
    }

    public function identity(): array
    {
        return [
            'selector_hash' => hash('sha256', $this->selector, true),
            'original_hash' => $this->originalHash(),
        ];
    }

    public function row(): array
    {
        return $this->identity() + [
            'selector'      => $this->selector,
            'source_url'    => $this->sourceUrl,
            'original_text' => $this->originalText(),
        ];
    }
}
