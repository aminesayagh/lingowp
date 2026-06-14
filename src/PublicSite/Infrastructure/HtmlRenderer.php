<?php

namespace TransLangs\PublicSite\Infrastructure;

use Masterminds\HTML5;
use TransLangs\TranslationMemory\Domain\TL_Key;

/**
 * Hardcoded-HTML translation renderer — the frontend REPLACE pass for the html
 * service (parsing spec §3, §9). A single DOM walk over the final HTML: each
 * block-level unit's selector key is looked up in the per-URL map; on a hit the
 * block's inner HTML is rebuilt from the translated unit string (placeholders
 * decoded against the live tag map). Discovery is NOT done here (marking-mode only).
 *
 * Fail-open everywhere: any parse/DOM error leaves the HTML (or the block)
 * untouched — never emit broken markup.
 */
final class HtmlRenderer
{
    /** Whole subtree skipped (never descended). */
    private const PRUNE_TAGS = [
        'script' => true, 'style' => true, 'noscript' => true, 'template' => true,
        'textarea' => true, 'iframe' => true, 'svg' => true, 'canvas' => true, 'pre' => true,
    ];

    /** Block-level tags: each is its own unit (or a container to recurse into). */
    private const BLOCK_TAGS = [
        'p' => true, 'div' => true, 'li' => true, 'ul' => true, 'ol' => true,
        'h1' => true, 'h2' => true, 'h3' => true, 'h4' => true, 'h5' => true, 'h6' => true,
        'td' => true, 'th' => true, 'tr' => true, 'table' => true, 'section' => true,
        'article' => true, 'header' => true, 'footer' => true, 'main' => true, 'aside' => true,
        'nav' => true, 'blockquote' => true, 'figure' => true, 'figcaption' => true,
        'dd' => true, 'dt' => true, 'dl' => true, 'form' => true, 'fieldset' => true, 'address' => true,
    ];

    public function __construct(private TranslationUnitCodec $codec) {}

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

            $this->walk($body, $html5, $dom, $map);

            return $html5->saveHTML($dom);
        } catch (\Throwable) {
            return $html;
        }
    }

    /**
     * @param array<string, string> $map
     */
    private function walk(\DOMElement $element, HTML5 $html5, \DOMDocument $dom, array $map): void
    {
        foreach (iterator_to_array($element->childNodes) as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);
            if ($this->isPruned($child, $tag)) {
                continue;
            }

            if (!isset(self::BLOCK_TAGS[$tag])) {
                $this->walk($child, $html5, $dom, $map); // inline/other wrapper: descend
                continue;
            }

            if ($this->hasChildBlock($child)) {
                $this->walk($child, $html5, $dom, $map); // container of blocks: recurse
                continue;
            }

            $this->replaceUnit($child, $html5, $dom, $map);
        }
    }

    /**
     * @param array<string, string> $map
     */
    private function replaceUnit(\DOMElement $block, HTML5 $html5, \DOMDocument $dom, array $map): void
    {
        $selector = $this->domSelector($block);
        if ($selector === '') {
            return;
        }

        $hex = bin2hex(TL_Key::make(TL_Key::TYPE_HTML, null, $selector, null, ''));
        if (!isset($map[$hex])) {
            return;
        }

        try {
            $encoded  = $this->codec->encode($block);
            $decoded  = $this->codec->decode($map[$hex], $encoded['tags']);
            $fragment = $html5->loadHTMLFragment($decoded);
            if (!$fragment instanceof \DOMNode) {
                return;
            }

            $imported = $dom->importNode($fragment, true);

            while ($block->firstChild) {
                $block->removeChild($block->firstChild);
            }
            $block->appendChild($imported);
        } catch (\Throwable) {
            // fail-open: leave the block as rendered
        }
    }

    private function isPruned(\DOMElement $element, string $tag): bool
    {
        if (isset(self::PRUNE_TAGS[$tag])) {
            return true;
        }
        if ($element->hasAttribute('data-tl-skip') || $element->hasAttribute('data-tl-region')) {
            return true;
        }
        if (strtolower(trim($element->getAttribute('translate'))) === 'no') {
            return true;
        }
        if (strtolower(trim($element->getAttribute('id'))) === 'wpadminbar') {
            return true;
        }

        $classes = preg_split('/\s+/', strtolower(trim($element->getAttribute('class')))) ?: [];

        return in_array('notranslate', $classes, true);
    }

    private function hasChildBlock(\DOMElement $element): bool
    {
        foreach ($element->childNodes as $child) {
            if ($child instanceof \DOMElement && isset(self::BLOCK_TAGS[strtolower($child->tagName)])) {
                return true;
            }
        }

        return false;
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
