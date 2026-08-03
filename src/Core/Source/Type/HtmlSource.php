<?php

namespace TransLangs\Core\Source\Type;
use TransLangs\Core\Source\Base\SourceRecord;

use InvalidArgumentException;

/**
 * Type-5 source: a rendered-HTML unit, identified by its text alone.
 * Identity is original_hash — the same text is exactly one row regardless of
 * how many DOM locations on the site render it, since the translation is
 * already shared across every location via the hash-link child table; a
 * second parent row per location would only duplicate review effort without
 * ever producing a second translation. selector/source_url are descriptive,
 * non-unique columns for administration (preview link, "field" display) —
 * INSERT IGNORE leaves them at whichever location was discovered first;
 * source_url alone is kept fresh on rediscovery (see
 * WpDbSourceTranslationRepository::refreshHtmlSourceUrls()) so a stale
 * preview link gets replaced rather than accumulating a second row.
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

    /**
     * Keep only the request path and query. A stored host would be stale after
     * a domain move and would be prepended again when the workspace calls
     * home_url() to build the front-end preview link.
     */
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
        return 'tl_html';
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
