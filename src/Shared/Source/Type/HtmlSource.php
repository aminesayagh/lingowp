<?php

namespace LingoWP\Shared\Source\Type;
use LingoWP\Shared\Source\SourceRecord;

use InvalidArgumentException;

final class HtmlSource extends SourceRecord
{
    public const SELECTOR_MAX_LENGTH = 255;

    private string $selector;
    private ?string $sourceUrl;
    private string $text;

    public function __construct(
        string $selector,
        ?string $sourceUrl,
        string $text
    ) {
        $this->sourceUrl = $sourceUrl;
        $this->text      = $text;

        $selector = trim($selector);
        if ($selector === '') {
            throw new InvalidArgumentException('HtmlSource: selector is required.');
        }

        $this->selector = strlen($selector) > self::SELECTOR_MAX_LENGTH
            ? substr($selector, -self::SELECTOR_MAX_LENGTH)
            : $selector;

        $this->sourceUrl = self::normalizeSourceUrl($sourceUrl);
    }

    public function selector(): string
    {
        return $this->selector;
    }

    public function sourceUrl(): ?string
    {
        return $this->sourceUrl;
    }

    public static function normalizeSourceUrl(?string $sourceUrl): ?string
    {
        $sourceUrl = trim((string) $sourceUrl);
        if ($sourceUrl === '') {
            return null;
        }

        $parts = parse_url($sourceUrl);
        if ($parts === false) {
            return null;
        }

        $path = (string) ($parts['path'] ?? '/');
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }

        $query = isset($parts['query']) && $parts['query'] !== ''
            ? '?' . $parts['query']
            : '';

        return $path . $query;
    }

    public function table(): string
    {
        return 'html';
    }

    public function originalText(): string
    {
        return $this->text;
    }

    public function identity(): array
    {
        return [
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
