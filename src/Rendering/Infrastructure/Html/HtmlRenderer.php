<?php

namespace TransLangs\Rendering\Infrastructure\Html;

use Masterminds\HTML5;
use TransLangs\Core\Source\HtmlSource;
use TransLangs\Core\Parsing\HtmlUnitExtractor;
use TransLangs\Core\Text\TL_Key;

/**
 * Hardcoded-HTML translation renderer — the frontend REPLACE pass for the html
 * service. Walks the block-level units of the final page <body> and replaces each
 * one whose text has a translation.
 *
 * v5: html children are HASH-LINK, keyed by original_hash alone (the selector is
 * only the parent's position identity, not part of the translation key), so a
 * unit is matched purely by the hash of its text.
 *
 * The same walk doubles as marking-mode DISCOVERY: pass $onUnit and each block's
 * (selector, unit text) is reported so the caller can mint tl_html parents. The
 * selector is what an editor sees in the admin board; it is not part of the
 * translation key. Fail-open: any parse/DOM error leaves the HTML untouched.
 */
final class HtmlRenderer
{
    public function __construct(private HtmlUnitExtractor $extractor) {}

    /**
     * @param array<string, string>        $map    lowercase hex(original_hash) => translated unit string
     * @param null|callable(string,string):void $onUnit (selector, unit text) reporter for discovery
     */
    public function render(string $html, array $map, ?callable $onUnit = null): string
    {
        if (($map === [] && $onUnit === null) || !class_exists(HTML5::class)) {
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

                return $map[bin2hex(TL_Key::currentHash($unit))] ?? null;
            });

            return $html5->saveHTML($dom);
        } catch (\Throwable) {
            return $html;
        }
    }

    /** Stable structural selector for a block, capped to the parent column width. */
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

        // Drop leftmost (least specific) segments until it fits.
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
