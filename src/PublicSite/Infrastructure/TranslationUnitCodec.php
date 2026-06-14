<?php

namespace TransLangs\PublicSite\Infrastructure;

use TransLangs\TranslationMemory\Domain\StringNormalizer;

/**
 * The placeholder chokepoint for type-5 composed units (parsing spec §5).
 *
 * A translation unit is one block element. Its inline descendants are flattened
 * into reversible numbered placeholders so the whole sentence translates as one
 * string with word order free and tags preserved:
 *
 *     <p>See <a href="/x"><strong>this</strong></a> now</p>
 *       encode → "See {1}{2}this{/2}{/1} now"   (tags[1]=a, tags[2]=strong)
 *       decode(translated, tags) → "<a href=\"/x\"><strong>…</strong></a>"
 *
 * Determinism is mandatory (hash drift = silent match failure): numbering is
 * strict document order, and the hash is taken over the unit string only
 * (text + `{n}` structure) — never attributes (P2), which live in the tag map
 * for rebuild and are derived live at render (P3).
 */
final class TranslationUnitCodec
{
    /** HTML void elements — emitted as a single self-closing placeholder `{n/}`. */
    private const VOID = [
        'br' => true, 'wbr' => true, 'img' => true, 'hr' => true, 'input' => true,
        'area' => true, 'col' => true, 'embed' => true, 'source' => true, 'track' => true,
    ];

    /**
     * @return array{unit: string, tags: array<int, array{open: string, close: string}>}
     */
    public function encode(\DOMElement $block): array
    {
        $counter = 0;
        $tags    = [];
        $unit    = $this->walk($block, $counter, $tags);

        return ['unit' => $unit, 'tags' => $tags];
    }

    /** Shared normalizer — the unit string is normalized before hashing/storing. */
    public function normalizeUnit(string $unit): string
    {
        return StringNormalizer::normalize($unit);
    }

    /**
     * Rebuild an HTML fragment from a translated unit string + the (live) tag map.
     * Text between markers is HTML-escaped; the `{n}` markers expand to real tags.
     *
     * @param array<int, array{open: string, close: string}> $tags
     */
    public function decode(string $translatedUnit, array $tags): string
    {
        // Escape the translated text first (the `{n}` markers are not affected),
        // then expand markers into the real (already-escaped-attr) tags.
        $html = htmlspecialchars($translatedUnit, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        foreach ($tags as $n => $tag) {
            $html = str_replace('{' . $n . '/}', $tag['open'], $html);
            $html = str_replace('{' . $n . '}', $tag['open'], $html);
            $html = str_replace('{/' . $n . '}', $tag['close'], $html);
        }

        return $html;
    }

    /**
     * @param array<int, array{open: string, close: string}> $tags
     */
    private function walk(\DOMNode $node, int &$counter, array &$tags): string
    {
        $unit = '';

        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $unit .= $child->nodeValue;
                continue;
            }

            if (!$child instanceof \DOMElement) {
                continue; // comments, PIs — ignored
            }

            $n    = ++$counter;
            $name = strtolower($child->tagName);
            $open = '<' . $name . $this->attributes($child);

            if (isset(self::VOID[$name])) {
                $tags[$n] = ['open' => $open . ' />', 'close' => ''];
                $unit    .= '{' . $n . '/}';
                continue;
            }

            $tags[$n] = ['open' => $open . '>', 'close' => '</' . $name . '>'];
            $unit    .= '{' . $n . '}' . $this->walk($child, $counter, $tags) . '{/' . $n . '}';
        }

        return $unit;
    }

    private function attributes(\DOMElement $element): string
    {
        $out = '';

        foreach ($element->attributes as $attr) {
            $out .= ' ' . $attr->nodeName
                . '="' . htmlspecialchars((string) $attr->nodeValue, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"';
        }

        return $out;
    }
}
