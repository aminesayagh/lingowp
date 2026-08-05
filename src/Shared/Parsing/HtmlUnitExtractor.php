<?php

namespace TransLangs\Shared\Parsing;

use Masterminds\HTML5;

/**
 * The single block-level unit extractor (type-5 parsing spec §2-§5, reused by
 * split fields per the split-field spec §3). One DOM walk finds each leaf
 * block-level element; the codec flattens its inline children into `{n}`
 * placeholders so the whole block is one translation unit.
 *
 * Three callers, one walk:
 *   - units()      → discovery: ordered unit strings for a field's HTML.
 *   - render()     → split-field the_content: replace each unit by its translation.
 *   - walkNode()   → the type-5 page renderer (HtmlRenderer) drives the same walk
 *                    over the page <body>, keying by DOM selector instead of text.
 *
 * Fail-open everywhere: any parse/DOM error leaves the HTML (or the block) as-is.
 */
final class HtmlUnitExtractor
{
    /** Whole subtree skipped (never descended). */
    private const PRUNE_TAGS = [
        'script' => true, 'style' => true, 'noscript' => true, 'template' => true,
        'textarea' => true, 'iframe' => true, 'svg' => true, 'canvas' => true, 'pre' => true,
    ];

    /** Block-level tags: each leaf is its own unit (a container recurses). */
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
     * Discovery: the ordered list of block-unit strings in a field's HTML
     * (document order). Inline tags appear as `{n}` markers.
     *
     * @return string[]
     */
    public function units(string $html): array
    {
        $units = [];
        $this->renderFragment($html, static function (\DOMElement $block, array $encoded) use (&$units): ?string {
            unset($block);
            $units[] = $encoded['unit'];

            return null; // collect only — no replacement
        });

        return $units;
    }

    /**
     * Split-field render: rebuild a field's HTML, replacing each block unit whose
     * unit string maps to a translation. $translate(unitString) returns the
     * translated unit string (placeholders intact) or null to leave it as-is.
     *
     * @param callable(string):?string $translate
     */
    public function render(string $html, callable $translate): string
    {
        return $this->renderFragment($html, static function (\DOMElement $block, array $encoded) use ($translate): ?string {
            unset($block);

            return $translate($encoded['unit']);
        });
    }

    /**
     * Walk a node's block-level leaves. For each, encode it and call
     * $onUnit($block, $encoded); a non-null/non-empty return is decoded against
     * the live tag map and replaces the block's inner content. Shared with the
     * type-5 page renderer (which keys $onUnit by DOM selector).
     *
     * @param callable(\DOMElement, array{unit:string, tags:array}):?string $onUnit
     */
    public function walkNode(\DOMNode $node, HTML5 $html5, \DOMDocument $dom, callable $onUnit): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);
            if ($this->isPruned($child, $tag)) {
                continue;
            }

            if (!isset(self::BLOCK_TAGS[$tag]) || $this->hasChildBlock($child)) {
                $this->walkNode($child, $html5, $dom, $onUnit); // wrapper / container: descend
                continue;
            }

            $this->handleLeaf($child, $html5, $dom, $onUnit);
        }
    }

    /**
     * @param callable(\DOMElement, array{unit:string, tags:array}):?string $onUnit
     */
    private function handleLeaf(\DOMElement $block, HTML5 $html5, \DOMDocument $dom, callable $onUnit): void
    {
        try {
            $encoded    = $this->codec->encode($block);
            $translated = $onUnit($block, $encoded);
            if ($translated === null || $translated === '') {
                return;
            }

            // Stripped single-wrapper units store the bare text; re-apply the
            // wrappers (if any) so decode rebuilds the original inline tags.
            $decoded  = $this->codec->decode($this->codec->rewrap($translated, $encoded), $encoded['tags']);
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
            // fail-open: leave the block as-is
        }
    }

    /**
     * Parse $html as a fragment (no <html>/<body> wrappers injected), walk it,
     * and serialize back. Used by units()/render(); the page renderer loads a
     * full document and calls walkNode() directly.
     *
     * @param callable(\DOMElement, array{unit:string, tags:array}):?string $onUnit
     */
    private function renderFragment(string $html, callable $onUnit): string
    {
        if ($html === '' || !class_exists(HTML5::class)) {
            return $html;
        }

        try {
            $html5    = new HTML5();
            $fragment = $html5->loadHTMLFragment($html);
            $dom      = $fragment instanceof \DOMNode ? $fragment->ownerDocument : null;
            if (!$fragment instanceof \DOMNode || !$dom instanceof \DOMDocument) {
                return $html;
            }

            $this->walkNode($fragment, $html5, $dom, $onUnit);

            return $html5->saveHTML($fragment);
        } catch (\Throwable) {
            return $html;
        }
    }

    public function isPruned(\DOMElement $element, string $tag): bool
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
}
