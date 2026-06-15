<?php

namespace TransLangs\PublicSite\Infrastructure;

use Masterminds\HTML5;
use TransLangs\TranslationMemory\Domain\TL_Key;

/**
 * Hardcoded-HTML translation renderer — the frontend REPLACE pass for the html
 * service (parsing spec §3, §9). Drives the shared block-unit walk over the
 * final page <body>, keying each unit by its DOM selector (type-5 identity).
 * Discovery is NOT done here (marking-mode only). Fail-open: parse/DOM errors
 * leave the HTML untouched.
 */
final class HtmlRenderer
{
    public function __construct(private HtmlUnitExtractor $extractor) {}

    /**
     * @param array<string, string> $map hex(source_key) => translated unit string
     */
    public function render(string $html, array $map): string
    {
        if ($map === [] || !class_exists(HTML5::class)) {
            return $html;
        }

        try {
            $html5 = new HTML5();
            $dom   = $html5->loadHTML($html);
            $body  = $dom->getElementsByTagName('body')->item(0);
            if (!$body instanceof \DOMElement) {
                return $html;
            }

            $this->extractor->walkNode($body, $html5, $dom, function (\DOMElement $block) use ($map): ?string {
                $selector = $this->domSelector($block);
                if ($selector === '') {
                    return null;
                }
                $hex = bin2hex(TL_Key::make(TL_Key::TYPE_HTML, null, $selector, null, ''));

                return $map[$hex] ?? null;
            });

            return $html5->saveHTML($dom);
        } catch (\Throwable) {
            return $html;
        }
    }

    private function domSelector(\DOMElement $element): string
    {
        $segments = [];
        $node     = $element;

        while ($node instanceof \DOMElement) {
            $tag = strtolower($node->tagName);
            if ($tag === 'html' || $tag === 'body') {
                break;
            }
            array_unshift($segments, $this->selectorSegment($node, $tag));
            $node = $node->parentNode;
        }

        $selector = implode('>', $segments);

        // Cap at the §1/D15 selector length: drop leftmost (least specific) segments.
        while (strlen($selector) > TL_Key::SELECTOR_MAX_LENGTH && count($segments) > 1) {
            array_shift($segments);
            $selector = implode('>', $segments);
        }

        return strlen($selector) > TL_Key::SELECTOR_MAX_LENGTH
            ? substr($selector, -TL_Key::SELECTOR_MAX_LENGTH)
            : $selector;
    }

    private function selectorSegment(\DOMElement $node, string $tag): string
    {
        $parent = $node->parentNode;
        if (!$parent instanceof \DOMNode) {
            return $tag;
        }

        $sameTag = 0;
        $index   = 0;
        foreach ($parent->childNodes as $sibling) {
            if ($sibling instanceof \DOMElement && strtolower($sibling->tagName) === $tag) {
                $sameTag++;
                if ($sibling === $node) {
                    $index = $sameTag;
                }
            }
        }

        return $sameTag > 1 ? $tag . ':nth-of-type(' . $index . ')' : $tag;
    }
}
