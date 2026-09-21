<?php

namespace LingoWP\Resolution;

use Masterminds\HTML5;
use LingoWP\Shared\Source\Type\HtmlSource;
use LingoWP\Shared\Parsing\HtmlUnitExtractor;
use LingoWP\Shared\Text\TranslationKey;

final class HtmlRenderer
{
    private HtmlUnitExtractor $extractor;

    public function __construct(HtmlUnitExtractor $extractor)
    {
        $this->extractor = $extractor;
    }

    public function render(string $html, array $map, ?callable $onUnit = null, ?callable $onDocument = null): string
    {
        if (($map === [] && $onUnit === null && $onDocument === null) || !class_exists(HTML5::class)) {
            return $html;
        }

        try {
            $html5 = new HTML5();
            $dom   = $html5->loadHTML($html);
            $body  = $dom->getElementsByTagName('body')->item(0);
            if (!$body instanceof \DOMElement) {
                return $html;
            }

            $this->extractor->walkNode($body, $html5, $dom, function (\DOMElement $block, array $encoded) use ($map, $onUnit): ?string {
                $unit = (string) $encoded['unit'];

                if ($onUnit !== null) {
                    $selector = $this->domSelector($block);
                    if ($selector !== '') {
                        $onUnit($selector, $unit);
                    }
                }

                return $map[bin2hex(TranslationKey::currentHash($unit))] ?? null;
            });

            if ($onDocument !== null) {
                try {
                    $onDocument($dom);
                } catch (\Throwable $e) {
                }
            }

            return $html5->saveHTML($dom);
        } catch (\Throwable $e) {
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
        $max      = HtmlSource::SELECTOR_MAX_LENGTH;

        while (strlen($selector) > $max && count($segments) > 1) {
            array_shift($segments);
            $selector = implode('>', $segments);
        }

        return strlen($selector) > $max ? substr($selector, -$max) : $selector;
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
