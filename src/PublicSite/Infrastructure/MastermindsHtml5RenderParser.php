<?php

namespace TransLangs\PublicSite\Infrastructure;

use Masterminds\HTML5;

final class MastermindsHtml5RenderParser implements HtmlRenderParser
{
    public function __construct(private HtmlTextTools $textTools) {}

    public function translate(string $html, array $translationsByHash, array $ownedHashes): RenderedHtmlResult
    {
        if (!class_exists(HTML5::class)) {
            return RenderedHtmlResult::failure($html);
        }

        try {
            $html5 = new HTML5();
            $dom   = $html5->loadHTML($html);
            $xpath = new \DOMXPath($dom);
            $nodes = $xpath->query('//text()[normalize-space(.) != ""]');

            if (!$nodes instanceof \DOMNodeList) {
                return RenderedHtmlResult::failure($html);
            }

            $replacementCount = 0;
            $candidates       = [];

            foreach ($nodes as $node) {
                if (!$node instanceof \DOMText) {
                    continue;
                }

                if ($this->shouldSkipNode($node)) {
                    continue;
                }

                $tags = $this->tagPath($node);
                if ($this->textTools->shouldSkipPath($tags)) {
                    continue;
                }

                $text       = (string) $node->nodeValue;
                $normalized = $this->textTools->normalizedText($text);
                if ($normalized === '') {
                    continue;
                }

                $hashHex = $this->textTools->originalHashHex($normalized);
                if (isset($translationsByHash[$hashHex])) {
                    $node->nodeValue = $this->textTools->replacePreservingEdgeWhitespace(
                        $text,
                        $translationsByHash[$hashHex]
                    );
                    ++$replacementCount;
                    continue;
                }

                if (!isset($ownedHashes[$hashHex])) {
                    $selector = $this->textTools->structuralSelector($tags);
                    $candidates[$hashHex . '|' . $selector] = [
                        'original_text'      => $text,
                        'normalized_text'    => $normalized,
                        'original_hash_hex'  => $hashHex,
                        'selector'           => $selector,
                    ];
                }
            }

            return RenderedHtmlResult::success(
                $html5->saveHTML($dom),
                $replacementCount,
                array_values($candidates)
            );
        } catch (\Throwable) {
            return RenderedHtmlResult::failure($html);
        }
    }

    private function shouldSkipNode(\DOMText $node): bool
    {
        $parent = $node->parentNode;

        while ($parent instanceof \DOMElement) {
            if ($this->textTools->shouldSkipElementByAttributes(
                $parent->getAttribute('id'),
                $parent->getAttribute('class')
            )) {
                return true;
            }

            $parent = $parent->parentNode;
        }

        return false;
    }

    /**
     * @return string[] Root-to-leaf element tag path for a text node.
     */
    private function tagPath(\DOMText $node): array
    {
        $tags   = [];
        $parent = $node->parentNode;

        while ($parent instanceof \DOMElement) {
            array_unshift($tags, strtolower($parent->tagName));
            $parent = $parent->parentNode;
        }

        return $tags;
    }
}
