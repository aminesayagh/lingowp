<?php

namespace LingoWP\Shared\Parsing;

use Masterminds\HTML5;

final class HtmlUnitExtractor
{
    private const PRUNE_TAGS = [
        'script' => true, 'style' => true, 'noscript' => true, 'template' => true,
        'textarea' => true, 'iframe' => true, 'svg' => true, 'canvas' => true, 'pre' => true,
    ];

    private const BLOCK_TAGS = [
        'p' => true, 'div' => true, 'li' => true, 'ul' => true, 'ol' => true,
        'h1' => true, 'h2' => true, 'h3' => true, 'h4' => true, 'h5' => true, 'h6' => true,
        'td' => true, 'th' => true, 'tr' => true, 'table' => true, 'thead' => true,
        'tbody' => true, 'tfoot' => true, 'section' => true,
        'article' => true, 'header' => true, 'footer' => true, 'main' => true, 'aside' => true,
        'nav' => true, 'blockquote' => true, 'figure' => true, 'figcaption' => true,
        'dd' => true, 'dt' => true, 'dl' => true, 'form' => true, 'fieldset' => true, 'address' => true,
        'pre' => true,
    ];

    private const STANDALONE_TEXT_TAGS = [
        'cite' => true, 'summary' => true,
    ];

    private TranslationUnitCodec $codec;

    public function __construct(TranslationUnitCodec $codec)
    {
        $this->codec = $codec;
    }

    public function units(string $html): array
    {
        $units = [];
        $this->renderFragment($html, static function (\DOMElement $block, array $encoded) use (&$units): ?string {
            unset($block);
            $units[] = $encoded['unit'];

            return null;
        });

        return $units;
    }

    public function render(string $html, callable $translate): string
    {
        return $this->renderFragment($html, static function (\DOMElement $block, array $encoded) use ($translate): ?string {
            unset($block);

            return $translate($encoded['unit']);
        });
    }

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

            if (isset(self::STANDALONE_TEXT_TAGS[$tag]) && !$this->hasChildBlock($child)) {
                $this->handleLeaf($child, $html5, $dom, $onUnit);
                continue;
            }

            if (!isset(self::BLOCK_TAGS[$tag]) || $this->hasChildBlock($child)) {
                $this->walkNode($child, $html5, $dom, $onUnit);
                continue;
            }

            $this->handleLeaf($child, $html5, $dom, $onUnit);
        }
    }

    private function handleLeaf(\DOMElement $block, HTML5 $html5, \DOMDocument $dom, callable $onUnit): void
    {
        try {
            $preserveWhitespace = strtolower($block->tagName) === 'pre'
                && $this->hasClass($block, 'wp-block-verse');
            $encoded = $this->codec->encode($block, $preserveWhitespace);
            $translated = $onUnit($block, $encoded);
            if ($translated === null || $translated === '') {
                return;
            }

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
        } catch (\Throwable $e) {
        }
    }

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
        } catch (\Throwable $e) {
            return $html;
        }
    }

    public function isPruned(\DOMElement $element, string $tag): bool
    {
        if (!($tag === 'pre' && $this->hasClass($element, 'wp-block-verse')) && isset(self::PRUNE_TAGS[$tag])) {
            return true;
        }
        if ($element->hasAttribute('data-lingowp-skip') || $element->hasAttribute('data-lingowp-region')) {
            return true;
        }
        if (strtolower(trim($element->getAttribute('translate'))) === 'no') {
            return true;
        }
        if (strtolower(trim($element->getAttribute('id'))) === 'wpadminbar') {
            return true;
        }

        return $this->hasClass($element, 'notranslate');
    }

    private function hasClass(\DOMElement $element, string $class): bool
    {
        $classes = preg_split('/\s+/', strtolower(trim($element->getAttribute('class')))) ?: [];

        return in_array($class, $classes, true);
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
