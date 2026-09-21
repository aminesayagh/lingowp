<?php

namespace LingoWP\Resolution;

use Masterminds\HTML5;
use LingoWP\LocalizationRouting\LocalizedUrlBuilder;
use LingoWP\Shared\StaticAssetExtensions;

final class PublicLinkRewriter
{
    private const SKIPPED_PATH_PREFIXES = [
        'wp-admin'   => true,
        'wp-content' => true,
        'wp-includes' => true,
        'wp-json'    => true,
    ];

    private const SKIPPED_FILES = [
        'wp-login.php' => true,
        'xmlrpc.php'  => true,
    ];

    private LocalizedUrlBuilder $urlBuilder;

    public function __construct(LocalizedUrlBuilder $urlBuilder)
    {
        $this->urlBuilder = $urlBuilder;
    }

    public function rewrite(string $html, string $targetLang): string
    {
        if (!$this->urlBuilder->isPrefixed($targetLang)) {
            return $html;
        }

        $rewritten = $this->rewriteWithDom($html, $targetLang);
        if ($rewritten !== $html) {
            return $rewritten;
        }

        return $this->rewriteAnchorAttributes($html, $targetLang);
    }

    private function rewriteWithDom(string $html, string $targetLang): string
    {
        if (!class_exists(HTML5::class)) {
            return $html;
        }

        try {
            $html5 = new HTML5();
            $dom   = $html5->loadHTML($html);
            $xpath = new \DOMXPath($dom);
            $nodes = $xpath->query('//a[@href]');

            if (!$nodes instanceof \DOMNodeList) {
                return $html;
            }

            $changed = false;

            foreach ($nodes as $node) {
                if (!$node instanceof \DOMElement || $this->shouldSkipElement($node)) {
                    continue;
                }

                $href = $node->getAttribute('href');
                $rewritten = $this->rewriteHref($href, $targetLang);
                if ($rewritten === null || $rewritten === $href) {
                    continue;
                }

                $node->setAttribute('href', $rewritten);
                $changed = true;
            }

            return $changed ? $html5->saveHTML($dom) : $html;
        } catch (\Throwable $e) {
            return $html;
        }
    }

    private function rewriteAnchorAttributes(string $html, string $targetLang): string
    {
        return preg_replace_callback(
            '/<a\\b[^>]*\\bhref\\s*=\\s*(["\\\'])(.*?)\\1[^>]*>/is',
            function (array $matches) use ($targetLang): string {
                $tag = $matches[0];
                if (preg_match('/\\s(?:data-lingowp-lang|hreflang)\\b/i', $tag) === 1) {
                    return $tag;
                }

                $rewritten = $this->rewriteHref($matches[2], $targetLang);
                if ($rewritten === null) {
                    return $tag;
                }

                return preg_replace_callback(
                    '/\\bhref\\s*=\\s*(["\\\'])(.*?)\\1/is',
                    fn(array $hrefMatches): string => 'href='
                        . $hrefMatches[1]
                        . $this->escapeAttribute($rewritten)
                        . $hrefMatches[1],
                    $tag,
                    1
                ) ?? $tag;
            },
            $html
        ) ?? $html;
    }

    private function shouldSkipElement(\DOMElement $element): bool
    {
        if ($element->hasAttribute('data-lingowp-lang') || $element->hasAttribute('hreflang')) {
            return true;
        }

        $parent = $element;
        while ($parent instanceof \DOMElement) {
            if (strtolower(trim($parent->getAttribute('id'))) === 'wpadminbar') {
                return true;
            }

            $parent = $parent->parentNode;
        }

        return false;
    }

    private function rewriteHref(string $href, string $targetLang): ?string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($href === '' || $href[0] === '#' || str_starts_with($href, '//')) {
            return null;
        }

        $parts = $this->urlParts($href);
        if (!is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== '' && $scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        $isAbsolute = isset($parts['host']);
        if ($isAbsolute && !$this->isSameSiteUrl($parts)) {
            return null;
        }

        if (!$isAbsolute && !str_starts_with($href, '/')) {
            return null;
        }

        $path = (string) ($parts['path'] ?? '/');
        $path = $path === '' ? '/' : $path;

        if (!$this->shouldRewritePath($path)) {
            return null;
        }

        $suffix = $this->suffix($parts);
        $localizedPath = $this->urlBuilder->addPrefix($path, $targetLang);

        if ($isAbsolute) {
            return function_exists('home_url')
                ? (string) home_url($localizedPath . $suffix)
                : $localizedPath . $suffix;
        }

        return $localizedPath . $suffix;
    }

    private function isSameSiteUrl(array $parts): bool
    {
        $home = $this->urlParts(function_exists('home_url') ? (string) home_url('/') : '');
        if (!is_array($home) || empty($home['host']) || empty($parts['host'])) {
            return false;
        }

        if (strtolower((string) $home['host']) !== strtolower((string) $parts['host'])) {
            return false;
        }

        return !isset($home['port'], $parts['port']) || (int) $home['port'] === (int) $parts['port'];
    }

    private function shouldRewritePath(string $path): bool
    {
        $path = strtolower(trim(rawurldecode($path), '/'));
        if ($path === '') {
            return true;
        }

        $first = explode('/', $path)[0];
        if (isset(self::SKIPPED_PATH_PREFIXES[$first]) || isset(self::SKIPPED_FILES[$first])) {
            return false;
        }

        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        return $extension === '' || !isset(StaticAssetExtensions::LIST[$extension]);
    }

    private function suffix(array $parts): string
    {
        $suffix = '';

        if (isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== '') {
            $suffix .= '?' . $parts['query'];
        }

        if (isset($parts['fragment']) && is_string($parts['fragment']) && $parts['fragment'] !== '') {
            $suffix .= '#' . $parts['fragment'];
        }

        return $suffix;
    }

    private function escapeAttribute(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    private function urlParts(string $url)
    {
        return function_exists('wp_parse_url') ? wp_parse_url($url) : parse_url($url);
    }
}
